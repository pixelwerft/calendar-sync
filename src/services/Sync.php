<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use DateTimeImmutable;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\db\Table;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\jobs\PushEntry;
use pixelwerft\calendarsync\jobs\SyncCalendar;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\Run;
use pixelwerft\calendarsync\models\SyncResult;
use pixelwerft\calendarsync\providers\ProviderInterface;
use Throwable;
use yii\base\Component;

/**
 * The sync itself.
 *
 * One run of a calendar looks at the period set for it and
 * 1. reads the events of the calendar and brings Craft up to date (directions «calendar → Craft» and «both»),
 * 2. reads the entries of the section and brings the calendar up to date («Craft → calendar» and «both»).
 *
 * Changes are found by fingerprints: of each event as the calendar had it at the last run, and of each entry as it
 * was then. If only one side changed, the other follows. If both changed, the side set in the calendar wins.
 *
 * Removing follows the same idea: an event that left the calendar takes its entry with it (delete, disable or
 * keep – as set), an entry that was deleted takes its event with it. Occurrences of repeating events are only read.
 */
class Sync extends Component
{
    /** Entries are being saved by the sync itself: no reason to send them back to the calendar. */
    private bool $writing = false;

    /** A trial run: everything is looked at and counted, nothing is written – not here and not in the calendar. */
    private bool $preview = false;

    /**
     * Entries the first half of a trial run would have written or removed. The second half must not count them
     * again: in a real run they would be up to date by then.
     *
     * @var array<int, bool>
     */
    private array $previewHandled = [];

    /**
     * Runs one calendar now.
     *
     * @param bool $force Write every entry again, also for events that did not change – after the mapping changed
     * @param string $trigger What started the run, for the log: one of the `Run::TRIGGER_…` values
     * @param bool $preview A trial run: says what would happen and changes nothing
     */
    public function syncCalendar(Calendar $calendar, bool $force = false, string $trigger = Run::TRIGGER_MANUAL, bool $preview = false): SyncResult
    {
        $result = new SyncResult();
        $result->preview = $preview;
        $started = microtime(true);
        $mutex = Craft::$app->getMutex();
        $lock = 'calendar-sync:' . $calendar->id;
        if (!$mutex->acquire($lock)) {
            $result->addError(CalendarSync::t('This calendar is being synced right now.'));
            return $result;
        }

        $this->preview = $preview;
        $this->previewHandled = [];
        try {
            [$connection, $provider] = $this->endpoint($calendar);
            [$from, $to] = $calendar->window();

            if ($calendar->imports()) {
                $this->import($calendar, $connection, $provider, $from, $to, $force, $result);
            }
            if ($calendar->exports() && $connection->canWrite()) {
                $this->export($calendar, $connection, $provider, $from, $to, $result);
            }
            if (!$preview) {
                // what has left the period and belongs to no entry is not kept for ever
                CalendarSync::$plugin->events->prune($calendar->id, $from);
            }
        } catch (Throwable $e) {
            Craft::error(sprintf('Calendar «%s»: %s', $calendar->handle, $e->getMessage()), 'calendar-sync');
            Craft::$app->getErrorHandler()->logException($e);
            $result->addError($e->getMessage());
        } finally {
            $this->preview = false;
            $this->previewHandled = [];
            $mutex->release($lock);
        }

        if ($preview) {
            return $result;
        }

        $statusBefore = $calendar->lastSyncStatus;
        CalendarSync::$plugin->calendars->markSynced(
            $calendar,
            $result->hasErrors() ? Calendar::STATUS_ERROR : Calendar::STATUS_OK,
            $result->toJson(false),
        );
        $this->afterRun($calendar, $result, $started, $trigger, $statusBefore);

        return $result;
    }

    /**
     * Sends one entry to a calendar right away – after it was saved, deleted or restored.
     */
    public function pushEntry(Calendar $calendar, int $entryId): SyncResult
    {
        $result = new SyncResult();
        $started = microtime(true);
        if (!$calendar->enabled || !$calendar->exports() || !$calendar->hasEntries()) {
            return $result;
        }
        $mutex = Craft::$app->getMutex();
        $lock = 'calendar-sync:' . $calendar->id;
        if (!$mutex->acquire($lock, 15)) {
            // a full run is going on; it sends this entry too, or the next one does
            return $result;
        }

        try {
            [$connection, $provider] = $this->endpoint($calendar);
            if (!$connection->canWrite()) {
                return $result;
            }
            [$from, $to] = $calendar->window();
            $entry = $this->findEntry($calendar, $entryId, true);
            $event = CalendarSync::$plugin->events->getEventByEntryId($calendar->id, $entryId);

            if ($entry !== null && $this->qualifies($calendar, $entry)) {
                $this->exportEntry($calendar, $connection, $provider, $entry, $event, $from, $to, $result);
            } elseif ($event !== null) {
                $this->exportGone($calendar, $connection, $provider, $entry, $event, $result);
            }
        } catch (Throwable $e) {
            Craft::error(sprintf('Calendar «%s», entry %d: %s', $calendar->handle, $entryId, $e->getMessage()), 'calendar-sync');
            $result->addError($e->getMessage());
        } finally {
            $mutex->release($lock);
        }

        if ($result->hasErrors() || $result->hasChanges()) {
            // the state of the calendar is that of its last full run; a single entry only leaves a line in the log
            $this->afterRun($calendar, $result, $started, Run::TRIGGER_ENTRY, null);
        }

        return $result;
    }

    /**
     * The log and the notifications. Whatever goes wrong here must not turn a run that worked into one that failed.
     */
    private function afterRun(Calendar $calendar, SyncResult $result, float $started, string $trigger, ?string $statusBefore): void
    {
        try {
            CalendarSync::$plugin->runs->record($calendar, $result, $started, $trigger);
            if ($trigger !== Run::TRIGGER_ENTRY) {
                CalendarSync::$plugin->notifier->afterRun($calendar, $result, $statusBefore);
            }
        } catch (Throwable $e) {
            Craft::error('Run not logged: ' . $e->getMessage(), 'calendar-sync');
        }
    }

    /**
     * Puts a run of a calendar on the queue. A calendar that already waits there is not queued again, unless asked.
     */
    public function queue(Calendar $calendar, bool $force = false, bool $always = false, string $trigger = Run::TRIGGER_MANUAL): bool
    {
        $cache = Craft::$app->getCache();
        $key = self::queuedKey($calendar->id);
        if (!$always && $cache->get($key)) {
            return false;
        }
        $cache->set($key, true, 900);
        Queue::push(new SyncCalendar(['calendarId' => $calendar->id, 'force' => $force, 'trigger' => $trigger]));

        return true;
    }

    /**
     * Queues every calendar that is due by its interval.
     */
    public function queueDue(): int
    {
        $queued = 0;
        foreach (CalendarSync::$plugin->calendars->getDueCalendars() as $calendar) {
            if ($this->queue($calendar, false, false, Run::TRIGGER_SCHEDULE)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * A job took the calendar from the queue.
     */
    public function dequeued(int $calendarId): void
    {
        Craft::$app->getCache()->delete(self::queuedKey($calendarId));
    }

    /**
     * An entry was saved, deleted or restored: if its section goes to a calendar, send it there.
     */
    public function handleEntryChange(ElementInterface $element): void
    {
        if (
            $this->writing ||
            !$element instanceof Entry ||
            $element->sectionId === null ||
            ElementHelper::isDraftOrRevision($element) ||
            !CalendarSync::$plugin->getSettings()->syncOnSave
        ) {
            return;
        }

        foreach (CalendarSync::$plugin->calendars->getExportCalendarsBySectionId($element->sectionId) as $calendar) {
            if ($calendar->getSiteId() !== $element->siteId) {
                continue;
            }
            // No memory of what was queued before: a second job for the same entry finds nothing to do and costs
            // little, while a forgotten one (in a queue worker that lives for hours) would lose a change.
            Queue::push(new PushEntry(['calendarId' => $calendar->id, 'entryId' => (int)$element->id]));
        }
    }

    /**
     * Calendar → Craft.
     */
    private function import(Calendar $calendar, Connection $connection, ProviderInterface $provider, DateTimeImmutable $from, DateTimeImmutable $to, bool $force, SyncResult $result): void
    {
        $events = CalendarSync::$plugin->events;
        $remote = $provider->fetchEvents($connection, $calendar->remoteId, $from, $to);
        $existing = $events->getEventsByCalendarId((int)$calendar->id);
        if ($calendar->hasFilters()) {
            $remote = $this->filtered($calendar, $remote, $existing, $result);
        }
        if ($connection->getIsBusyOnly()) {
            $remote = $this->busyOnly($remote, $connection->getBusyTitle());
        }
        $seen = [];

        foreach ($remote as $data) {
            if ($data->remoteId === '' || $data->status === EventData::STATUS_CANCELLED) {
                // a cancelled event is treated like one that is gone
                continue;
            }
            $key = Events::key($data->remoteId);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $previous = $existing[$key] ?? null;

            try {
                if ($previous === null) {
                    $event = $this->preview
                        ? $events->draft($calendar, $data)
                        : $events->saveFromData($calendar, $data, null, ['origin' => Event::ORIGIN_REMOTE]);
                    $changed = true;
                    $result->created++;
                    $result->note(SyncResult::EVENT_NEW, $data->title, $data->start);
                } elseif ($previous->remoteHash !== $data->hash() || $previous->getIsCancelled()) {
                    $event = $this->preview
                        ? $events->draft($calendar, $data, $previous)
                        : $events->saveFromData($calendar, $data, $previous);
                    $changed = true;
                    $result->updated++;
                    $result->note(SyncResult::EVENT_CHANGED, $data->title, $data->start);
                } else {
                    $event = $previous;
                    $changed = false;
                    $result->unchanged++;
                }

                if ($calendar->hasEntries()) {
                    $this->importEntry($calendar, $event, $previous, $data, $changed || $force, $result);
                }
            } catch (Throwable $e) {
                Craft::error(sprintf('Calendar «%s», event «%s»: %s', $calendar->handle, $data->title, $e->getMessage()), 'calendar-sync');
                $result->addError(sprintf('«%s»: %s', $data->title, $e->getMessage()));
            }
        }

        // What Craft knows and the calendar did not return is gone – if it lies inside the period that was asked
        // for. A day of margin on both ends: providers decide by their own clock what still belongs to a day.
        $inner = [$from->modify('+1 day'), $to->modify('-1 day')];
        foreach ($existing as $key => $event) {
            if (isset($seen[$key]) || $event->getIsCancelled()) {
                continue;
            }
            $data = $event->toEventData();
            if ($data->end <= $inner[0] || $data->start >= $inner[1]) {
                continue;
            }
            try {
                $this->importGone($calendar, $event, $result);
            } catch (Throwable $e) {
                $result->addError(sprintf('«%s»: %s', $event->title, $e->getMessage()));
            }
        }
    }

    /**
     * The events that pass the filters of the calendar. An event that was made from an entry always passes: it is
     * Craft's own, and leaving it out would look as if it had been deleted in the calendar.
     *
     * @param EventData[] $remote
     * @param array<string, Event> $existing By key
     * @return EventData[]
     */
    private function filtered(Calendar $calendar, array $remote, array $existing, SyncResult $result): array
    {
        $accepted = [];
        foreach ($remote as $data) {
            $known = $existing[Events::key($data->remoteId)] ?? null;
            if ($known?->origin === Event::ORIGIN_CRAFT || $calendar->accepts($data)) {
                $accepted[] = $data;
                continue;
            }
            if ($data->status !== EventData::STATUS_CANCELLED) {
                $result->skipped++;
                $result->note(SyncResult::SKIPPED, $data->title, $data->start);
            }
        }

        return $accepted;
    }

    /**
     * What is left of events when a connection only shows busy times: when, and nothing else. An event that does
     * not block its time is left out.
     *
     * @param EventData[] $events
     * @return EventData[]
     */
    public function busyOnly(array $events, string $title): array
    {
        $busy = [];
        foreach ($events as $data) {
            if ($data->free) {
                continue;
            }
            $data->title = $title;
            $data->description = null;
            $data->location = null;
            $data->url = null;
            $data->organizer = null;
            $data->attachments = [];
            $busy[] = $data;
        }

        return $busy;
    }

    /**
     * Brings the entry of an event up to date, or creates it.
     */
    private function importEntry(Calendar $calendar, Event $event, ?Event $previous, EventData $data, bool $changed, SyncResult $result): void
    {
        $events = CalendarSync::$plugin->events;
        $mapper = CalendarSync::$plugin->mapper;
        $entry = $event->entryId ? $this->findEntry($calendar, $event->entryId) : null;

        if ($entry === null) {
            if ($event->entryId && $calendar->exports()) {
                // the entry was deleted in Craft: the other half of the run removes the event from the calendar
                return;
            }
            if ($event->origin === Event::ORIGIN_CRAFT) {
                return;
            }
            $entry = new Entry();
            $entry->sectionId = $calendar->sectionId;
            $entry->typeId = $calendar->entryTypeId;
            $entry->siteId = $calendar->getSiteId();
            $entry->enabled = true;
            // the lenient scenario the entry is saved with does not set it, and without it the entry is not live
            $entry->postDate = new \DateTime();
            if ($calendar->authorId) {
                $entry->setAuthorId($calendar->authorId);
            }
            $result->entriesCreated++;
            $result->note(SyncResult::ENTRY_NEW, $data->title, $data->start);
            if ($this->preview) {
                return;
            }
            $mapper->applyToEntry($calendar, $entry, $data);
            $this->saveEntry($entry, $event);
            $events->updateLink($event, [
                'entryId' => (int)$entry->id,
                'entryHash' => $mapper->entryHash($calendar, $entry, $event),
            ]);

            return;
        }

        if (!$changed) {
            return;
        }

        if ($calendar->exports() && $event->entryHash !== null) {
            // compared with the event as it was: what the mapping leaves open is taken from there
            $localChanged = $mapper->entryHash($calendar, $entry, $previous ?? $event) !== $event->entryHash;
            if ($localChanged) {
                $result->conflicts++;
                $result->note(SyncResult::CONFLICT, $data->title, $data->start);
                if ($calendar->conflictWinner === Calendar::WINNER_LOCAL) {
                    // the entry stays; the other half of the run writes it over the event
                    return;
                }
            }
        }

        $result->entriesUpdated++;
        $result->note(SyncResult::ENTRY_CHANGED, $data->title, $data->start);
        if ($this->preview) {
            $this->previewHandled[(int)$entry->id] = true;
            return;
        }
        $mapper->applyToEntry($calendar, $entry, $data);
        $this->saveEntry($entry, $event);
        $events->updateLink($event, ['entryHash' => $mapper->entryHash($calendar, $entry, $event)]);
    }

    /**
     * An event is no longer in the calendar.
     */
    private function importGone(Calendar $calendar, Event $event, SyncResult $result): void
    {
        $events = CalendarSync::$plugin->events;
        $entry = $event->entryId && $calendar->hasEntries() ? $this->findEntry($calendar, $event->entryId) : null;
        if ($entry !== null && $this->preview) {
            $this->previewHandled[(int)$entry->id] = true;
        }

        if ($entry !== null) {
            switch ($calendar->missingBehavior) {
                case Calendar::MISSING_DELETE:
                    if (!$this->preview) {
                        $this->writing = true;
                        try {
                            Craft::$app->getElements()->deleteElement($entry);
                        } finally {
                            $this->writing = false;
                        }
                    }
                    $result->entriesRemoved++;
                    $result->note(SyncResult::ENTRY_REMOVED, (string)$entry->title, $event->startDate);
                    break;
                case Calendar::MISSING_DISABLE:
                    if ($entry->enabled) {
                        if (!$this->preview) {
                            $entry->enabled = false;
                            $this->saveEntry($entry, $event);
                        }
                        $result->entriesRemoved++;
                        $result->note(SyncResult::ENTRY_REMOVED, (string)$entry->title, $event->startDate);
                    }
                    break;
                default:
                    if ($calendar->exports()) {
                        // The entry stays and is live. Without a trace of its event it would look like a new entry
                        // and go to the calendar again – so the event is kept as cancelled.
                        if (!$this->preview) {
                            Craft::$app->getDb()->createCommand()
                                ->update(Table::EVENTS, ['status' => EventData::STATUS_CANCELLED], ['id' => $event->id])
                                ->execute();
                        }
                        $result->removed++;
                        $result->note(SyncResult::EVENT_REMOVED, $event->title, $event->startDate);

                        return;
                    }
            }
        }

        if (!$this->preview) {
            $events->deleteEvent($event);
        }
        $result->removed++;
        $result->note(SyncResult::EVENT_REMOVED, $event->title, $event->startDate);
    }

    /**
     * Craft → calendar.
     */
    private function export(Calendar $calendar, Connection $connection, ProviderInterface $provider, DateTimeImmutable $from, DateTimeImmutable $to, SyncResult $result): void
    {
        if (!$calendar->hasEntries()) {
            return;
        }

        $linked = [];
        foreach (CalendarSync::$plugin->events->getEventsByCalendarId((int)$calendar->id) as $event) {
            if ($event->entryId) {
                $linked[$event->entryId] = $event;
            }
        }

        $handled = [];
        $query = Entry::find()
            ->sectionId($calendar->sectionId)
            ->typeId($calendar->entryTypeId)
            ->siteId($calendar->getSiteId())
            ->status(Entry::STATUS_LIVE);

        foreach ($query->each(100) as $entry) {
            /** @var Entry $entry */
            $handled[$entry->id] = true;
            if (isset($this->previewHandled[$entry->id])) {
                continue;
            }
            try {
                $this->exportEntry($calendar, $connection, $provider, $entry, $linked[$entry->id] ?? null, $from, $to, $result);
            } catch (Throwable $e) {
                Craft::error(sprintf('Calendar «%s», entry %d: %s', $calendar->handle, $entry->id, $e->getMessage()), 'calendar-sync');
                $result->addError(sprintf('«%s»: %s', $entry->title, $e->getMessage()));
            }
        }

        foreach ($linked as $entryId => $event) {
            if (isset($handled[$entryId]) || isset($this->previewHandled[$entryId])) {
                continue;
            }
            try {
                $this->exportGone($calendar, $connection, $provider, $this->findEntry($calendar, $entryId, true), $event, $result);
            } catch (Throwable $e) {
                $result->addError(sprintf('«%s»: %s', $event->title, $e->getMessage()));
            }
        }
    }

    /**
     * Creates the event of a live entry, or changes it if the entry changed since the last run.
     */
    private function exportEntry(Calendar $calendar, Connection $connection, ProviderInterface $provider, Entry $entry, ?Event $event, DateTimeImmutable $from, DateTimeImmutable $to, SyncResult $result): void
    {
        if ($event !== null && ($event->recurring || $event->getIsCancelled())) {
            return;
        }
        $mapper = CalendarSync::$plugin->mapper;
        $data = $mapper->entryToEvent($calendar, $entry, $event);
        if ($data === null) {
            // no start, no event
            return;
        }
        $attributes = $mapper->writtenAttributes($calendar);
        $hash = $data->hash($attributes);
        $events = CalendarSync::$plugin->events;

        if ($event === null) {
            if ($data->end <= $from || $data->start >= $to) {
                return;
            }
            $result->pushed++;
            $result->note(SyncResult::SENT_NEW, $data->title, $data->start);
            if ($this->preview) {
                return;
            }
            $remote = $provider->createEvent($connection, $calendar->remoteId, $data);
            $events->saveFromData($calendar, $remote, null, [
                'origin' => Event::ORIGIN_CRAFT,
                'entryId' => (int)$entry->id,
                'entryHash' => $hash,
            ]);

            return;
        }

        if ($event->entryHash === $hash) {
            return;
        }
        $result->pushed++;
        $result->note(SyncResult::SENT_CHANGED, $data->title, $data->start);
        if ($this->preview) {
            return;
        }

        try {
            $remote = $provider->updateEvent($connection, $calendar->remoteId, $event->remoteId, $data, $attributes);
        } catch (ProviderException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
            // gone at the provider, but the entry lives: back it goes
            $remote = $provider->createEvent($connection, $calendar->remoteId, $data);
        }
        $events->saveFromData($calendar, $remote, $event, ['entryHash' => $hash]);
    }

    /**
     * The entry of an event is deleted, not live any more, or left the section.
     *
     * A deleted entry takes its event with it. An entry that is only disabled does so if the event came from Craft:
     * the calendar shows what is live. An event that was made in the calendar stays there.
     */
    private function exportGone(Calendar $calendar, Connection $connection, ProviderInterface $provider, ?Entry $entry, Event $event, SyncResult $result): void
    {
        if ($event->recurring || $event->getIsCancelled()) {
            return;
        }
        $deleted = $entry === null || $entry->trashed;
        if (!$deleted && $event->origin !== Event::ORIGIN_CRAFT) {
            return;
        }

        $result->pushedRemoved++;
        $result->note(SyncResult::SENT_REMOVED, $event->title, $event->startDate);
        if ($this->preview) {
            return;
        }
        $provider->deleteEvent($connection, $calendar->remoteId, $event->remoteId);
        CalendarSync::$plugin->events->deleteEvent($event);
    }

    /**
     * @return array{0: Connection, 1: ProviderInterface}
     */
    private function endpoint(Calendar $calendar): array
    {
        $connection = $calendar->getConnection();
        $provider = $connection?->getProvider();
        if ($connection === null || $provider === null) {
            throw new ProviderException(CalendarSync::t('The connection of this calendar does not exist any more.'));
        }

        return [$connection, $provider];
    }

    /**
     * The entry in the site of the calendar, in any status.
     */
    private function findEntry(Calendar $calendar, int $entryId, bool $trashed = false): ?Entry
    {
        $query = Entry::find()->id($entryId)->siteId($calendar->getSiteId())->status(null);
        if ($trashed) {
            $query->trashed(null);
        }

        return $query->one();
    }

    /** Whether an entry is one the calendar shows: live, and of the section and type that are mapped. */
    private function qualifies(Calendar $calendar, Entry $entry): bool
    {
        return !$entry->trashed
            && $entry->sectionId === $calendar->sectionId
            && $entry->getTypeId() === $calendar->entryTypeId
            && $entry->getStatus() === Entry::STATUS_LIVE;
    }

    private function saveEntry(Entry $entry, Event $event): void
    {
        $this->writing = true;
        try {
            // Required fields the mapping does not fill must not stop the sync
            $entry->setScenario(Element::SCENARIO_ESSENTIALS);
            $saved = Craft::$app->getElements()->saveElement($entry);
        } finally {
            $this->writing = false;
        }

        if (!$saved) {
            // try again at the next run
            CalendarSync::$plugin->events->updateLink($event, ['remoteHash' => null]);
            throw new \RuntimeException(implode(' ', $entry->getFirstErrors()) ?: CalendarSync::t('The entry could not be saved.'));
        }
    }

    private static function queuedKey(int $calendarId): string
    {
        return 'calendar-sync:queued:' . $calendarId;
    }
}
