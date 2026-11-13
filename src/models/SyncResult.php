<?php

namespace pixelwerft\calendarsync\models;

use pixelwerft\calendarsync\CalendarSync;

/**
 * What one run did.
 */
class SyncResult
{
    /** Events of the calendar, as kept in Craft. */
    public int $created = 0;
    public int $updated = 0;
    public int $removed = 0;
    public int $unchanged = 0;

    /** Entries written from events. */
    public int $entriesCreated = 0;
    public int $entriesUpdated = 0;
    public int $entriesRemoved = 0;

    /** Events written to the calendar from entries. */
    public int $pushed = 0;
    public int $pushedRemoved = 0;

    /** Both sides changed; the side set in the calendar won. */
    public int $conflicts = 0;

    /** @var string[] */
    public array $errors = [];

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
     * The run as it is stored with the calendar: numbers, no words, so that everyone reads it in their language.
     */
    public function toJson(): string
    {
        return (string)json_encode(get_object_vars($this), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
            $result->$name = is_array($default)
                ? array_values(array_map('strval', (array)$values[$name]))
                : (int)$values[$name];
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
}
