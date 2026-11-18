<?php

namespace pixelwerft\calendarsync\models;

use pixelwerft\calendarsync\CalendarSync;

/**
 * What one run did – or, for a trial run, what it would do.
 */
class SyncResult
{
    public const EVENT_NEW = 'event.new';
    public const EVENT_CHANGED = 'event.changed';
    public const EVENT_REMOVED = 'event.removed';
    public const ENTRY_NEW = 'entry.new';
    public const ENTRY_CHANGED = 'entry.changed';
    public const ENTRY_REMOVED = 'entry.removed';
    public const SENT_NEW = 'sent.new';
    public const SENT_CHANGED = 'sent.changed';
    public const SENT_REMOVED = 'sent.removed';
    public const CONFLICT = 'conflict';
    public const SKIPPED = 'skipped';

    /** No run lists more than this; the numbers still count everything. */
    private const MAX_CHANGES = 200;

    /** Events of the calendar, as kept in Craft. */
    public int $created = 0;
    public int $updated = 0;
    public int $removed = 0;
    public int $unchanged = 0;

    /** Events the filters of the calendar left out. */
    public int $skipped = 0;

    /** Entries written from events. */
    public int $entriesCreated = 0;
    public int $entriesUpdated = 0;
    public int $entriesRemoved = 0;

    /** Events written to the calendar from entries. */
    public int $pushed = 0;
    public int $pushedRemoved = 0;

    /** Both sides changed; the side set in the calendar won. */
    public int $conflicts = 0;

    /** A trial run: nothing was written. */
    public bool $preview = false;

    /** @var string[] */
    public array $errors = [];

    /**
     * What happened, item by item: one of the constants above, the title, and the day of the event.
     *
     * @var array<int, array{action: string, title: string, date: ?string}>
     */
    public array $changes = [];

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function addError(string $message): void
    {
        // a broken calendar must not fill the column with a thousand lines
        if (count($this->errors) < 20) {
            $this->errors[] = $message;
        }
    }

    /**
     * Notes one change, for the log and for the trial run.
     */
    public function note(string $action, string $title, ?\DateTimeInterface $date = null): void
    {
        if (count($this->changes) < self::MAX_CHANGES) {
            $this->changes[] = [
                'action' => $action,
                'title' => mb_substr($title, 0, 200),
                'date' => $date?->format('Y-m-d'),
            ];
        }
    }

    /**
     * Whether the run found anything to do.
     */
    public function hasChanges(): bool
    {
        return $this->created + $this->updated + $this->removed
            + $this->entriesCreated + $this->entriesUpdated + $this->entriesRemoved
            + $this->pushed + $this->pushedRemoved > 0;
    }

    /**
     * The run as it is stored: numbers, no words, so that everyone reads it in their language.
     *
     * @param bool $withChanges The list of single changes is long; the calendar itself only keeps the numbers
     */
    public function toJson(bool $withChanges = true): string
    {
        $values = get_object_vars($this);
        if (!$withChanges) {
            unset($values['changes']);
        }

        return (string)json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A stored run. Null for anything else, such as a plain message.
     */
    public static function fromJson(?string $json): ?self
    {
        $values = $json !== null && str_starts_with($json, '{') ? json_decode($json, true) : null;
        if (!is_array($values)) {
            return null;
        }
        $result = new self();
        foreach (get_object_vars($result) as $name => $default) {
            if (!isset($values[$name])) {
                continue;
            }
            if ($name === 'changes') {
                foreach ((array)$values[$name] as $change) {
                    if (is_array($change) && isset($change['action'], $change['title'])) {
                        $result->changes[] = [
                            'action' => (string)$change['action'],
                            'title' => (string)$change['title'],
                            'date' => isset($change['date']) ? (string)$change['date'] : null,
                        ];
                    }
                }
            } elseif (is_array($default)) {
                $result->$name = array_values(array_map('strval', (array)$values[$name]));
            } elseif (is_bool($default)) {
                $result->$name = (bool)$values[$name];
            } else {
                $result->$name = (int)$values[$name];
            }
        }

        return $result;
    }

    public function summary(): string
    {
        $parts = [
            CalendarSync::t('{n, plural, =1{# event} other{# events}} new', ['n' => $this->created]),
            CalendarSync::t('{n} changed', ['n' => $this->updated]),
            CalendarSync::t('{n} removed', ['n' => $this->removed]),
        ];
        if ($this->skipped) {
            $parts[] = CalendarSync::t('{n} left out by the filter', ['n' => $this->skipped]);
        }
        if ($this->entriesCreated || $this->entriesUpdated || $this->entriesRemoved) {
            $parts[] = CalendarSync::t('entries: {created} new, {updated} changed, {removed} removed', [
                'created' => $this->entriesCreated,
                'updated' => $this->entriesUpdated,
                'removed' => $this->entriesRemoved,
            ]);
        }
        if ($this->pushed || $this->pushedRemoved) {
            $parts[] = CalendarSync::t('sent to the calendar: {pushed}, removed there: {removed}', [
                'pushed' => $this->pushed,
                'removed' => $this->pushedRemoved,
            ]);
        }
        if ($this->conflicts) {
            $parts[] = CalendarSync::t('{n, plural, =1{# conflict} other{# conflicts}}', ['n' => $this->conflicts]);
        }
        $summary = implode(' · ', $parts);
        if ($this->errors) {
            $summary .= "\n" . implode("\n", $this->errors);
        }

        return $summary;
    }

    /**
     * The words for the kinds of change, for lists.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            self::EVENT_NEW => CalendarSync::t('Event new'),
            self::EVENT_CHANGED => CalendarSync::t('Event changed'),
            self::EVENT_REMOVED => CalendarSync::t('Event removed'),
            self::ENTRY_NEW => CalendarSync::t('Entry new'),
            self::ENTRY_CHANGED => CalendarSync::t('Entry changed'),
            self::ENTRY_REMOVED => CalendarSync::t('Entry removed'),
            self::SENT_NEW => CalendarSync::t('Sent to the calendar'),
            self::SENT_CHANGED => CalendarSync::t('Changed in the calendar'),
            self::SENT_REMOVED => CalendarSync::t('Removed from the calendar'),
            self::CONFLICT => CalendarSync::t('Conflict'),
            self::SKIPPED => CalendarSync::t('Left out by the filter'),
        ];
    }
}
