<?php

namespace pixelwerft\calendarsync\jobs;

use craft\queue\BaseJob;
use pixelwerft\calendarsync\CalendarSync;

/**
 * One run of a calendar.
 */
class SyncCalendar extends BaseJob
{
    public int $calendarId = 0;

    /** Write every entry again, also for events that did not change. */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = CalendarSync::$plugin;
        $plugin->sync->dequeued($this->calendarId);

        $calendar = $plugin->calendars->getCalendarById($this->calendarId);
        if ($calendar === null || !$calendar->enabled) {
            return;
        }
        // Errors are kept with the calendar and shown there; a failed job would only be run again and again
        $plugin->sync->syncCalendar($calendar, $this->force);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $calendar = CalendarSync::$plugin->calendars->getCalendarById($this->calendarId);

        return CalendarSync::t('Syncing calendar «{name}»', ['name' => $calendar->name ?? $this->calendarId]);
    }
}
