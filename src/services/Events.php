<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use pixelwerft\calendarsync\db\EventQuery;
use pixelwerft\calendarsync\db\Table;
use pixelwerft\calendarsync\helpers\Text;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\models\EventData;
use yii\base\Component;

/**
 * The events the plugin keeps: a copy of what is in the calendars, with the link to the entry of each.
 */
class Events extends Component
{
    public function find(): EventQuery
    {
        return new EventQuery();
    }

    public function getEventById(int $id): ?Event
    {
        $row = (new Query())->from(Table::EVENTS)->where(['id' => $id])->one();

        return $row ? $this->populate($row) : null;
    }

    /**
     * Every event of a calendar, by the key of its ID at the provider.
     *
     * @return array<string, Event>
     */
    public function getEventsByCalendarId(int $calendarId): array
    {
        $events = [];
        $query = (new Query())->from(Table::EVENTS)->where(['calendarId' => $calendarId]);
        foreach ($query->each(500) as $row) {
            $events[(string)$row['remoteKey']] = $this->populate($row);
        }

        return $events;
    }

    public function getEventByEntryId(int $calendarId, int $entryId): ?Event
    {
        $row = (new Query())->from(Table::EVENTS)->where(['calendarId' => $calendarId, 'entryId' => $entryId])->one();

        return $row ? $this->populate($row) : null;
    }

    /**
     * Stores an event as the provider has it: a new one, or over the one given.
     *
     * @param array<string, mixed> $link Bookkeeping to set along: origin, entryId, entryHash
     */
    public function saveFromData(Calendar $calendar, EventData $data, ?Event $event = null, array $link = []): Event
    {
        $values = $this->values($calendar, $data) + array_intersect_key($link, array_flip(['origin', 'entryId', 'entryHash']));

        if ($event?->id) {
            Db::update(Table::EVENTS, $values, ['id' => $event->id]);
            $id = $event->id;
        } else {
            Db::insert(Table::EVENTS, $values + ['uid' => StringHelper::UUID()]);
            $id = (int)Craft::$app->getDb()->getLastInsertID(Table::EVENTS);
        }

        return $this->getEventById($id) ?? throw new \RuntimeException('The event could not be stored.');
    }

    public function getEventByUid(string $uid): ?Event
    {
        $row = (new Query())->from(Table::EVENTS)->where(['uid' => $uid])->one();

        return $row ? $this->populate($row) : null;
    }

    /**
     * The event as it would be stored – for a trial run, which stores nothing.
     */
    public function draft(Calendar $calendar, EventData $data, ?Event $event = null): Event
    {
        return $this->populate($this->values($calendar, $data) + [
            'id' => $event->id ?? 0,
            'origin' => $event->origin ?? Event::ORIGIN_REMOTE,
            'entryId' => $event->entryId ?? null,
            'entryHash' => $event->entryHash ?? null,
            'dateCreated' => null,
            'dateUpdated' => null,
            'uid' => $event->uid ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Calendar $calendar, EventData $data): array
    {
        return [
            'calendarId' => $calendar->id,
            'remoteKey' => self::key($data->remoteId),
            'remoteId' => $data->remoteId,
            'icalUid' => $data->icalUid,
            'etag' => Text::limit($data->etag, 255),
            'title' => (string)Text::limit($data->title, 500),
            'description' => $data->description,
            'location' => $data->location,
            'url' => $data->url,
            'attachments' => $data->attachments ? Json::encode($data->attachments) : null,
            'startDate' => $data->start->format('Y-m-d H:i:s'),
            'endDate' => $data->end->format('Y-m-d H:i:s'),
            'allDay' => $data->allDay,
            'timezone' => Text::limit($data->timezone, 100),
            'status' => $data->status,
            'organizer' => Text::limit($data->organizer, 500),
            'recurring' => $data->recurring,
            'remoteHash' => $data->hash(),
        ];
    }

    /**
     * Sets bookkeeping of the sync on an event: its entry and the fingerprints.
     *
     * @param array<string, mixed> $values Of entryId, entryHash, remoteHash, origin
     */
    public function updateLink(Event $event, array $values): void
    {
        $values = array_intersect_key($values, array_flip(['entryId', 'entryHash', 'remoteHash', 'origin']));
        if (!$values || !$event->id) {
            return;
        }
        Db::update(Table::EVENTS, $values, ['id' => $event->id], updateTimestamp: false);
        foreach ($values as $name => $value) {
            $event->$name = $value;
        }
    }

    public function deleteEvent(Event $event): void
    {
        if ($event->id) {
            Db::delete(Table::EVENTS, ['id' => $event->id]);
        }
    }

    /**
     * Removes events that ended before a moment and are not linked to an entry – what has left the synced period.
     */
    public function prune(int $calendarId, \DateTimeInterface $before): int
    {
        return Db::delete(Table::EVENTS, [
            'and',
            ['calendarId' => $calendarId, 'entryId' => null],
            ['<', 'endDate', $before->format('Y-m-d H:i:s')],
        ]);
    }

    /**
     * Loads the live entries of a list of events with one query per site instead of one per event.
     *
     * @param Event[] $events
     */
    public function eagerLoadEntries(array $events): void
    {
        $bySite = [];
        foreach ($events as $event) {
            if ($event->entryId) {
                $bySite[$event->getCalendar()?->getSiteId() ?? 0][] = $event->entryId;
            }
        }
        $entries = [];
        foreach ($bySite as $siteId => $ids) {
            $entries[$siteId] = Entry::find()->id(array_unique($ids))->siteId($siteId ?: null)->indexBy('id')->all();
        }
        foreach ($events as $event) {
            $siteId = $event->getCalendar()?->getSiteId() ?? 0;
            $event->setEntry($event->entryId ? ($entries[$siteId][$event->entryId] ?? null) : null);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function populate(array $row): Event
    {
        $utc = new DateTimeZone('UTC');
        $display = Event::displayTimeZone();
        $allDay = (bool)$row['allDay'];

        if ($allDay) {
            // days, not moments: the same day in every time zone
            $start = new DateTime(substr((string)$row['startDate'], 0, 10) . ' 00:00:00', $display);
            $end = (new DateTime(substr((string)$row['endDate'], 0, 10) . ' 00:00:00', $display))->modify('-1 day');
            if ($end < $start) {
                $end = clone $start;
            }
        } else {
            $start = (new DateTime((string)$row['startDate'], $utc))->setTimezone($display);
            $end = (new DateTime((string)$row['endDate'], $utc))->setTimezone($display);
        }

        return new Event([
            'id' => (int)$row['id'],
            'calendarId' => (int)$row['calendarId'],
            'remoteId' => (string)$row['remoteId'],
            'icalUid' => $row['icalUid'],
            'etag' => $row['etag'],
            'title' => (string)$row['title'],
            'description' => $row['description'],
            'location' => $row['location'],
            'url' => $row['url'],
            'attachments' => self::attachments($row['attachments'] ?? null),
            'startDate' => $start,
            'endDate' => $end,
            'allDay' => $allDay,
            'timezone' => $row['timezone'],
            'status' => (string)$row['status'],
            'organizer' => $row['organizer'],
            'recurring' => (bool)$row['recurring'],
            'origin' => (string)$row['origin'],
            'entryId' => $row['entryId'] !== null ? (int)$row['entryId'] : null,
            'remoteHash' => $row['remoteHash'],
            'entryHash' => $row['entryHash'],
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated']) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']) ?: null,
            'uid' => $row['uid'],
        ]);
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private static function attachments(?string $json): array
    {
        $attachments = [];
        $decoded = $json ? Json::decodeIfJson($json) : null;
        foreach (is_array($decoded) ? $decoded : [] as $attachment) {
            if (is_array($attachment) && !empty($attachment['url'])) {
                $attachments[] = ['title' => (string)($attachment['title'] ?? $attachment['url']), 'url' => (string)$attachment['url']];
            }
        }

        return $attachments;
    }

    /**
     * The key an event is found by: the SHA-1 of its ID at the provider, which can be longer than an index allows.
     */
    public static function key(string $remoteId): string
    {
        return sha1($remoteId);
    }
}
