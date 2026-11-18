<?php

namespace pixelwerft\calendarsync\gql;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\Gql;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\db\EventQuery;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;

/**
 * Events and calendars in GraphQL: the queries `calendarSyncEvents`, `calendarSyncEvent`, `calendarSyncEventCount`
 * and `calendarSyncCalendars`, for schemas that are allowed to query them.
 */
abstract class Schema
{
    /** The part of a schema that allows the queries. */
    public const COMPONENT = 'calendarSync.events';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function queries(): array
    {
        if (!Gql::canSchema(self::COMPONENT, 'read')) {
            return [];
        }

        return [
            'calendarSyncEvents' => [
                'type' => Type::listOf(self::eventType()),
                'args' => self::eventArguments(),
                'resolve' => static fn($source, array $arguments) => self::find($arguments)->all(),
                'description' => 'The events of the synced calendars.',
            ],
            'calendarSyncEvent' => [
                'type' => self::eventType(),
                'args' => self::eventArguments(),
                'resolve' => static fn($source, array $arguments) => self::find($arguments)->one(),
                'description' => 'One event of the synced calendars.',
            ],
            'calendarSyncEventCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => self::eventArguments(),
                'resolve' => static fn($source, array $arguments) => self::find($arguments)->count(),
                'description' => 'How many events match.',
            ],
            'calendarSyncCalendars' => [
                'type' => Type::listOf(self::calendarType()),
                'args' => [
                    'handle' => ['type' => Type::listOf(Type::string()), 'description' => 'Only the calendars with these handles.'],
                ],
                'resolve' => static function($source, array $arguments) {
                    $calendars = CalendarSync::$plugin->calendars->getAllCalendars();
                    if (!empty($arguments['handle'])) {
                        $calendars = array_filter($calendars, static fn(Calendar $calendar) => in_array($calendar->handle, $arguments['handle'], true));
                    }

                    return array_values($calendars);
                },
                'description' => 'The synced calendars.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function eventArguments(): array
    {
        return [
            'id' => ['type' => Type::listOf(Type::int()), 'description' => 'Only the events with these IDs.'],
            'calendar' => ['type' => Type::listOf(Type::string()), 'description' => 'Only events of the calendars with these handles.'],
            'after' => ['type' => Type::string(), 'description' => 'Only events that are not over at this moment, e.g. `2026-06-01` or `now`.'],
            'before' => ['type' => Type::string(), 'description' => 'Only events that start before this moment.'],
            'upcoming' => ['type' => Type::boolean(), 'description' => 'Running and coming events, the next one first.'],
            'past' => ['type' => Type::boolean(), 'description' => 'Events that are over, the latest first.'],
            'today' => ['type' => Type::boolean(), 'description' => 'Events of today.'],
            'day' => ['type' => Type::string(), 'description' => 'Events of this day, e.g. `2026-06-01`.'],
            'month' => ['type' => Type::string(), 'description' => 'Events of the month this day lies in.'],
            'search' => ['type' => Type::string(), 'description' => 'Events with this text in the title, description or location.'],
            'allDay' => ['type' => Type::boolean(), 'description' => 'All-day events only, or timed events only.'],
            'recurring' => ['type' => Type::boolean(), 'description' => 'Occurrences of repeating events only, or without them.'],
            'hasEntry' => ['type' => Type::boolean(), 'description' => 'Events with an entry only, or without one.'],
            'entryId' => ['type' => Type::listOf(Type::int()), 'description' => 'The events of these entries.'],
            'orderBy' => ['type' => Type::string(), 'description' => 'E.g. `startDate DESC` or `title`.'],
            'limit' => ['type' => Type::int(), 'description' => 'How many events at most.'],
            'offset' => ['type' => Type::int(), 'description' => 'How many events to skip.'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function find(array $arguments): EventQuery
    {
        // an argument that was not given must not reset what an earlier one set
        $criteria = array_filter($arguments, static fn($value) => $value !== null);
        // a query must not ask for everything by accident
        $criteria['limit'] = min((int)($criteria['limit'] ?? 100), 1000);

        return CalendarSync::$plugin->events->find()->withEntries()->configure($criteria);
    }

    public static function eventType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('CalendarSyncEvent', static fn() => new DataType([
            'name' => 'CalendarSyncEvent',
            'description' => 'An event of a synced calendar.',
            'fields' => static fn() => [
                'id' => ['type' => Type::int()],
                'uid' => ['type' => Type::string()],
                'title' => ['type' => Type::string()],
                'description' => ['type' => Type::string(), 'description' => 'The description as it is stored: text, or HTML if the calendar sent HTML.'],
                'descriptionHtml' => [
                    'type' => Type::string(),
                    'description' => 'The description as safe HTML, formatted the way its calendar is set up: line breaks, links, attached documents.',
                    'resolve' => static fn(Event $event) => $event->getDescriptionHtml(),
                ],
                'location' => ['type' => Type::string()],
                'url' => ['type' => Type::string()],
                'startDate' => ['type' => DateTimeType::getType()],
                'endDate' => ['type' => DateTimeType::getType(), 'description' => 'The end. For an all-day event: its last day.'],
                'allDay' => ['type' => Type::boolean()],
                'timezone' => ['type' => Type::string()],
                'status' => ['type' => Type::string(), 'description' => '`confirmed`, `tentative` or `cancelled`.'],
                'organizer' => ['type' => Type::string()],
                'recurring' => ['type' => Type::boolean(), 'description' => 'One occurrence of a repeating event.'],
                'origin' => ['type' => Type::string(), 'description' => '`remote` (made in the calendar) or `craft` (made from an entry).'],
                'duration' => ['type' => Type::int(), 'description' => 'Minutes.', 'resolve' => static fn(Event $event) => $event->getDuration()],
                'isMultiDay' => ['type' => Type::boolean(), 'resolve' => static fn(Event $event) => $event->getIsMultiDay()],
                'isPast' => ['type' => Type::boolean(), 'resolve' => static fn(Event $event) => $event->getIsPast()],
                'isOngoing' => ['type' => Type::boolean(), 'resolve' => static fn(Event $event) => $event->getIsOngoing()],
                'isUpcoming' => ['type' => Type::boolean(), 'resolve' => static fn(Event $event) => $event->getIsUpcoming()],
                'attachments' => [
                    'type' => Type::listOf(self::attachmentType()),
                    'description' => 'Documents attached to the event, e.g. files from Google Drive.',
                ],
                'addToCalendarUrl' => [
                    'type' => Type::string(),
                    'description' => 'The address that puts the event into a visitor’s calendar.',
                    'args' => ['type' => ['type' => Type::string(), 'description' => '`google`, `outlook`, `office365` or `ics` (the default).']],
                    'resolve' => static fn(Event $event, array $arguments) => $event->getAddToCalendarUrl((string)($arguments['type'] ?? 'ics')),
                ],
                'jsonLd' => [
                    'type' => Type::string(),
                    'description' => 'The event as structured data (schema.org), as JSON.',
                    'resolve' => static fn(Event $event) => json_encode($event->getJsonLd(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
                'calendar' => ['type' => self::calendarType(), 'resolve' => static fn(Event $event) => $event->getCalendar()],
                'entryId' => ['type' => Type::int()],
                'entry' => [
                    'type' => EntryInterface::getType(),
                    'description' => 'The entry the event is mapped to, if the schema may query its section.',
                    'resolve' => static function(Event $event) {
                        $entry = $event->getEntry();
                        $section = $entry?->getSection();

                        return $entry !== null && $section !== null && Gql::canSchema('sections.' . $section->uid, 'read') ? $entry : null;
                    },
                ],
            ],
        ]));
    }

    public static function calendarType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('CalendarSyncCalendar', static fn() => new DataType([
            'name' => 'CalendarSyncCalendar',
            'description' => 'A synced calendar.',
            'fields' => [
                'id' => ['type' => Type::int()],
                'handle' => ['type' => Type::string()],
                'name' => ['type' => Type::string()],
                'color' => ['type' => Type::string(), 'description' => 'E.g. `#2d6cdf`.'],
                'timezone' => ['type' => Type::string()],
                'entryPrefix' => ['type' => Type::string()],
                'feedUrl' => [
                    'type' => Type::string(),
                    'description' => 'The address of the public iCalendar feed, if it is switched on.',
                    'resolve' => static fn(Calendar $calendar) => $calendar->getFeedUrl('ics'),
                ],
                'jsonFeedUrl' => [
                    'type' => Type::string(),
                    'description' => 'The address of the public JSON feed, if it is switched on.',
                    'resolve' => static fn(Calendar $calendar) => $calendar->getFeedUrl('json'),
                ],
                'lastSyncedAt' => ['type' => DateTimeType::getType()],
            ],
        ]));
    }

    public static function attachmentType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('CalendarSyncAttachment', static fn() => new DataType([
            'name' => 'CalendarSyncAttachment',
            'description' => 'A document attached to an event.',
            'fields' => [
                'title' => ['type' => Type::string()],
                'url' => ['type' => Type::string()],
            ],
        ]));
    }
}
