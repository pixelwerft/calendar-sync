<?php

namespace pixelwerft\calendarsync\helpers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use pixelwerft\calendarsync\models\EventData;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * iCalendar in and out: what a CalDAV server stores, and what the public feed hands to calendar apps.
 */
abstract class Ics
{
    public const PRODID = '-//pixelwerft//Calendar Sync//EN';

    /**
     * The events of one iCalendar resource between two moments. A repeating event comes as its occurrences.
     *
     * @param string $resourceId The ID the events get; occurrences get it with `#` and their start
     * @param DateTimeZone|null $timezone For times that carry no time zone
     * @return EventData[]
     */
    public static function parse(string $ics, string $resourceId, DateTimeInterface $from, DateTimeInterface $to, ?DateTimeZone $timezone = null): array
    {
        $calendar = self::read($ics);
        if ($calendar === null) {
            return [];
        }

        $recurring = false;
        foreach ($calendar->select('VEVENT') as $vevent) {
            if (isset($vevent->RRULE) || isset($vevent->RDATE) || isset($vevent->{'RECURRENCE-ID'})) {
                $recurring = true;
                break;
            }
        }
        if ($recurring) {
            try {
                $calendar = $calendar->expand($from, $to, $timezone);
            } catch (\Throwable) {
                // a rule nobody can follow: better no occurrences than a wrong sync
                return [];
            }
        }

        $events = [];
        foreach ($calendar->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent) {
                continue;
            }
            $event = self::fromVEvent($vevent, $timezone);
            if ($event === null) {
                continue;
            }
            $event->recurring = $recurring;
            $event->remoteId = $recurring
                ? $resourceId . '#' . $event->start->format($event->allDay ? 'Ymd' : 'Ymd\THis\Z')
                : $resourceId;
            $events[] = $event;
        }

        return $events;
    }

    /**
     * The events of a whole calendar file – a public calendar address – between two moments. An event is known by
     * its UID, an occurrence of a repeating event by its UID and its start.
     *
     * @param DateTimeZone|null $timezone For times that carry no time zone
     * @return EventData[]|null Null if the text is no calendar or holds a rule nobody can follow: then nothing is
     * known, which is not the same as «no events»
     */
    public static function parseFeed(string $ics, DateTimeInterface $from, DateTimeInterface $to, ?DateTimeZone $timezone = null): ?array
    {
        $calendar = self::read($ics);
        if ($calendar === null) {
            return null;
        }
        try {
            // leaves out what lies outside the period, turns repeating events into occurrences, times into UTC
            $calendar = $calendar->expand($from, $to, $timezone);
        } catch (\Throwable) {
            return null;
        }

        $events = [];
        foreach ($calendar->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VEvent) {
                continue;
            }
            $event = self::fromVEvent($vevent, $timezone);
            if ($event === null) {
                continue;
            }
            $event->recurring = isset($vevent->{'RECURRENCE-ID'});
            $uid = $event->icalUid ?: sha1($event->title . '|' . $event->start->format('c'));
            $event->remoteId = $event->recurring
                ? $uid . '#' . $event->start->format($event->allDay ? 'Ymd' : 'Ymd\THis\Z')
                : $uid;
            $events[] = $event;
        }

        return $events;
    }

    /**
     * What a calendar file says about itself: its name and its time zone. Null if the text is no calendar.
     *
     * @return array{name: ?string, timezone: ?string}|null
     */
    public static function feedInfo(string $ics): ?array
    {
        $calendar = self::read($ics);
        if ($calendar === null) {
            return null;
        }
        $name = trim((string)($calendar->{'X-WR-CALNAME'} ?? ''));
        $timezone = trim((string)($calendar->{'X-WR-TIMEZONE'} ?? ''));

        return [
            'name' => $name !== '' ? $name : null,
            'timezone' => $timezone !== '' && in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : null,
        ];
    }

    /**
     * One event as an iCalendar resource, the way it is stored on a CalDAV server.
     */
    public static function build(EventData $event, string $uid): string
    {
        $calendar = new VCalendar();
        self::set($calendar, 'PRODID', self::PRODID);
        /** @var VEvent $vevent */
        $vevent = $calendar->add('VEVENT', ['UID' => $uid]);
        self::apply($vevent, $event, EventData::WRITABLE);

        return $calendar->serialize();
    }

    /**
     * Changes the given attributes of the main event of a resource and returns the new resource. Null if the
     * resource holds no event.
     *
     * @param string[] $attributes
     */
    public static function patch(string $ics, EventData $event, array $attributes): ?string
    {
        $calendar = self::read($ics);
        if ($calendar === null) {
            return null;
        }
        foreach ($calendar->select('VEVENT') as $vevent) {
            if ($vevent instanceof VEvent && !isset($vevent->{'RECURRENCE-ID'})) {
                self::apply($vevent, $event, $attributes);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $sequence = $vevent->select('SEQUENCE');
                self::set($vevent, 'DTSTAMP', $now);
                self::set($vevent, 'LAST-MODIFIED', $now);
                self::set($vevent, 'SEQUENCE', (int)(string)($sequence[0] ?? 0) + 1);

                return $calendar->serialize();
            }
        }

        return null;
    }

    /**
     * A whole calendar for the public feed.
     *
     * @param array<int, array{uid: string, event: EventData, url?: ?string, modified?: ?DateTimeInterface}> $items
     */
    public static function feed(string $name, array $items, ?string $color = null): string
    {
        $calendar = new VCalendar();
        self::set($calendar, 'PRODID', self::PRODID);
        $calendar->add('X-WR-CALNAME', $name);
        $calendar->add('NAME', $name);
        if ($color) {
            $calendar->add('X-APPLE-CALENDAR-COLOR', $color);
        }
        // ask subscribers to look again every hour
        $calendar->add('REFRESH-INTERVAL', 'PT1H', ['VALUE' => 'DURATION']);
        $calendar->add('X-PUBLISHED-TTL', 'PT1H');

        foreach ($items as $item) {
            /** @var VEvent $vevent */
            $vevent = $calendar->add('VEVENT', ['UID' => $item['uid']]);
            $event = clone $item['event'];
            if (!empty($item['url'])) {
                $event->url = $item['url'];
            }
            self::apply($vevent, $event, EventData::WRITABLE);
            if ($event->status !== EventData::STATUS_CONFIRMED) {
                self::set($vevent, 'STATUS', strtoupper($event->status));
            }
            if (!empty($item['modified'])) {
                self::set($vevent, 'LAST-MODIFIED', DateTimeImmutable::createFromInterface($item['modified'])->setTimezone(new DateTimeZone('UTC')));
            }
        }

        return $calendar->serialize();
    }

    /**
     * @param string[] $attributes
     */
    private static function apply(VEvent $vevent, EventData $event, array $attributes): void
    {
        if (in_array('title', $attributes, true)) {
            self::set($vevent, 'SUMMARY', $event->title);
        }
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION', 'url' => 'URL'] as $attribute => $property) {
            if (!in_array($attribute, $attributes, true)) {
                continue;
            }
            $value = $attribute === 'description' ? Text::fromHtml((string)$event->description) : trim((string)$event->$attribute);
            unset($vevent->$property);
            if ($value !== '') {
                $vevent->add($property, $value);
            }
        }
        if (array_intersect(['start', 'end', 'allDay'], $attributes)) {
            unset($vevent->DTSTART, $vevent->DTEND, $vevent->DURATION);
            if ($event->allDay) {
                $vevent->add('DTSTART', $event->start->format('Ymd'), ['VALUE' => 'DATE']);
                $vevent->add('DTEND', $event->end->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $utc = new DateTimeZone('UTC');
                $vevent->add('DTSTART', $event->start->setTimezone($utc));
                $vevent->add('DTEND', $event->end->setTimezone($utc));
            }
        }
    }

    /**
     * Sets a property that occurs once: whatever was there goes.
     */
    private static function set(Component $component, string $name, mixed $value): void
    {
        $component->remove($name);
        $component->add($name, $value);
    }

    private static function read(string $ics): ?VCalendar
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (\Throwable) {
            return null;
        }

        return $calendar instanceof VCalendar ? $calendar : null;
    }

    private static function fromVEvent(VEvent $vevent, ?DateTimeZone $timezone): ?EventData
    {
        if (!isset($vevent->DTSTART)) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $event = new EventData();
        $event->icalUid = isset($vevent->UID) ? (string)$vevent->UID : null;
        $event->title = isset($vevent->SUMMARY) ? (string)$vevent->SUMMARY : '';
        $event->description = self::value($vevent, 'DESCRIPTION');
        $event->location = self::value($vevent, 'LOCATION');
        $event->url = self::value($vevent, 'URL');
        $event->status = match (strtoupper((string)($vevent->STATUS ?? ''))) {
            'CANCELLED' => EventData::STATUS_CANCELLED,
            'TENTATIVE' => EventData::STATUS_TENTATIVE,
            default => EventData::STATUS_CONFIRMED,
        };
        $event->free = strtoupper((string)($vevent->TRANSP ?? '')) === 'TRANSPARENT';
        foreach ($vevent->select('ATTACH') as $attach) {
            // only what is a link; files inside the event are left where they are
            $url = trim((string)$attach);
            if (preg_match('~^https?://~i', $url)) {
                $name = isset($attach['FILENAME']) ? trim((string)$attach['FILENAME']) : '';
                $event->attachments[] = [
                    'title' => $name !== '' ? $name : (rawurldecode(basename((string)parse_url($url, PHP_URL_PATH))) ?: $url),
                    'url' => $url,
                ];
            }
        }
        if (isset($vevent->ORGANIZER)) {
            $name = isset($vevent->ORGANIZER['CN']) ? trim((string)$vevent->ORGANIZER['CN']) : '';
            $event->organizer = $name !== '' ? $name : ((string)preg_replace('~^mailto:~i', '', (string)$vevent->ORGANIZER) ?: null);
        }
        $event->timezone = isset($vevent->DTSTART['TZID']) && in_array((string)$vevent->DTSTART['TZID'], DateTimeZone::listIdentifiers(), true)
            ? (string)$vevent->DTSTART['TZID']
            : null;

        try {
            /** @var \Sabre\VObject\Property\ICalendar\DateTime $dtstart */
            $dtstart = $vevent->DTSTART;
            $event->allDay = !$dtstart->hasTime();
            $start = $dtstart->getDateTime($timezone);

            $end = null;
            if (isset($vevent->DTEND)) {
                /** @var \Sabre\VObject\Property\ICalendar\DateTime $dtend */
                $dtend = $vevent->DTEND;
                $end = $dtend->getDateTime($timezone);
            } elseif (isset($vevent->DURATION)) {
                /** @var \Sabre\VObject\Property\ICalendar\Duration $duration */
                $duration = $vevent->DURATION;
                $end = $start->add($duration->getDateInterval());
            }

            if ($event->allDay) {
                $event->start = new DateTimeImmutable($start->format('Y-m-d') . ' 00:00:00', $utc);
                $event->end = $end ? new DateTimeImmutable($end->format('Y-m-d') . ' 00:00:00', $utc) : $event->start->modify('+1 day');
                if ($event->end <= $event->start) {
                    $event->end = $event->start->modify('+1 day');
                }
            } else {
                $event->start = DateTimeImmutable::createFromInterface($start)->setTimezone($utc);
                $event->end = $end ? DateTimeImmutable::createFromInterface($end)->setTimezone($utc) : $event->start;
                if ($event->end < $event->start) {
                    $event->end = $event->start;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return $event;
    }

    private static function value(VEvent $vevent, string $property): ?string
    {
        if (!isset($vevent->$property)) {
            return null;
        }
        $value = trim((string)$vevent->$property);

        return $value !== '' ? $value : null;
    }
}
