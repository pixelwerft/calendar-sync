<?php

namespace pixelwerft\calendarsync\models;

use craft\base\Model;
use DateTime;
use pixelwerft\calendarsync\CalendarSync;

/**
 * One run of a calendar, as the log keeps it.
 */
class Run extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SAVE = 'save';
    public const TRIGGER_CONSOLE = 'console';
    public const TRIGGER_ENTRY = 'entry';

    public ?int $id = null;
    public int $calendarId = 0;
    public string $trigger = self::TRIGGER_MANUAL;
    public string $status = Calendar::STATUS_OK;
    public ?DateTime $dateStarted = null;

    /** Milliseconds. */
    public int $duration = 0;

    public ?SyncResult $result = null;

    public function getCalendar(): ?Calendar
    {
        return CalendarSync::$plugin->calendars->getCalendarById($this->calendarId);
    }

    public function getIsOk(): bool
    {
        return $this->status === Calendar::STATUS_OK;
    }

    /**
     * What the run did, in the language of whoever reads it – without the errors, which are listed on their own.
     */
    public function getSummary(): string
    {
        if ($this->result === null) {
            return '';
        }
        $numbers = clone $this->result;
        $numbers->errors = [];

        return $numbers->summary();
    }

    /**
     * What went wrong in the run.
     *
     * @return string[]
     */
    public function getProblems(): array
    {
        return $this->result->errors ?? [];
    }

    /**
     * The single changes of the run, with the words for their kind.
     *
     * @return array<int, array{action: string, label: string, title: string, date: ?string}>
     */
    public function getChanges(): array
    {
        $labels = SyncResult::actionLabels();

        return array_map(static fn(array $change) => $change + ['label' => $labels[$change['action']] ?? $change['action']], $this->result->changes ?? []);
    }

    public function getTriggerLabel(): string
    {
        return self::triggerLabels()[$this->trigger] ?? $this->trigger;
    }

    /**
     * @return array<string, string>
     */
    public static function triggerLabels(): array
    {
        return [
            self::TRIGGER_SCHEDULE => CalendarSync::t('By the schedule'),
            self::TRIGGER_MANUAL => CalendarSync::t('By hand'),
            self::TRIGGER_SAVE => CalendarSync::t('After saving the calendar'),
            self::TRIGGER_CONSOLE => CalendarSync::t('Console command'),
            self::TRIGGER_ENTRY => CalendarSync::t('An entry was saved'),
        ];
    }
}
