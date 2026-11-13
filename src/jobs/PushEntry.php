<?php

namespace pixelwerft\calendarsync\jobs;

use craft\queue\BaseJob;
use pixelwerft\calendarsync\CalendarSync;

/**
 * Sends one entry to its calendar, right after it was saved, deleted or restored.
 */
class PushEntry extends BaseJob
{
    public int $calendarId = 0;
    public int $entryId = 0;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $calendar = CalendarSync::$plugin->calendars->getCalendarById($this->calendarId);
        if ($calendar === null) {
            return;
        }
        // If it fails, the next full run of the calendar sends the entry
        CalendarSync::$plugin->sync->pushEntry($calendar, $this->entryId);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return CalendarSync::t('Sending an entry to the calendar');
    }
}
