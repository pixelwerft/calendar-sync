<?php

namespace pixelwerft\calendarsync\variables;

use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\db\EventQuery;
use pixelwerft\calendarsync\models\Calendar;

/**
 * `craft.calendarSync` in templates.
 */
class CalendarSyncVariable
{
    /**
     * Finds events: `craft.calendarSync.events().calendar('team').upcoming().limit(5)`,
     * or with the criteria at once: `craft.calendarSync.events({ calendar: 'team', upcoming: true, limit: 5 })`.
     *
     * @param array<string, mixed> $criteria
     */
    public function events(array $criteria = []): EventQuery
    {
        return CalendarSync::$plugin->events->find()->configure($criteria);
    }

    /**
     * @return Calendar[]
     */
    public function calendars(): array
    {
        return CalendarSync::$plugin->calendars->getAllCalendars();
    }

    public function calendar(string $handle): ?Calendar
    {
        return CalendarSync::$plugin->calendars->getCalendarByHandle($handle);
    }

    /**
     * The address of the public feed of a calendar, or null if the feed is off.
     *
     * @param string $format `ics` to subscribe to, `json` for a calendar script
     */
    public function feedUrl(string $handle, string $format = 'ics'): ?string
    {
        return $this->calendar($handle)?->getFeedUrl($format);
    }
}
