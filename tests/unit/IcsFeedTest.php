<?php

namespace pixelwerft\calendarsync\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use pixelwerft\calendarsync\helpers\Ics;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\providers\IcsFeed;
use pixelwerft\calendarsync\services\Sync;

class IcsFeedTest extends ProviderTestCase
{
    private const FEED = <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//Example//EN
        X-WR-CALNAME:Club events
        X-WR-TIMEZONE:Europe/Zurich
        BEGIN:VEVENT
        UID:meeting-1@example.org
        SUMMARY:Board meeting
        DESCRIPTION:Bring the minutes
        LOCATION:Clubhouse
        DTSTART;TZID=Europe/Zurich:20260610T190000
        DTEND;TZID=Europe/Zurich:20260610T210000
        ATTACH;FILENAME=Agenda.pdf:https://drive.google.com/file/d/abc/view
        END:VEVENT
        BEGIN:VEVENT
        UID:trip-1@example.org
        SUMMARY:Club trip
        DTSTART;VALUE=DATE:20260613
        DTEND;VALUE=DATE:20260615
        TRANSP:TRANSPARENT
        END:VEVENT
        BEGIN:VEVENT
        UID:training@example.org
        SUMMARY:Training
        DTSTART:20260601T170000Z
        DTEND:20260601T180000Z
        RRULE:FREQ=WEEKLY;COUNT=10
        END:VEVENT
        BEGIN:VEVENT
        UID:old@example.org
        SUMMARY:Long ago
        DTSTART:20250101T100000Z
        DTEND:20250101T110000Z
        END:VEVENT
        END:VCALENDAR
        ICS;

    public function testAnAddressIsBroughtIntoShape(): void
    {
        $this->assertSame('https://example.org/cal.ics', IcsFeed::normalizeUrl(' webcal://example.org/cal.ics '));
        $this->assertSame('https://example.org/cal.ics', IcsFeed::normalizeUrl('https://example.org/cal.ics'));
        $this->assertSame(
            'https://calendar.google.com/calendar/ical/club%40group.calendar.google.com/public/basic.ics',
            IcsFeed::normalizeUrl('club@group.calendar.google.com'),
        );
    }

    public function testAPublicCalendarCanOnlyBeRead(): void
    {
        $this->assertFalse(IcsFeed::canWrite());
        $this->assertFalse(IcsFeed::usesOAuth());
    }

    public function testTheCalendarTakesItsNameFromTheFile(): void
    {
        $provider = new IcsFeed();
        $this->mock($provider, [new Response(200, ['Content-Type' => 'text/calendar'], self::feed())]);

        $calendars = $provider->listCalendars($this->feedConnection());

        $this->assertCount(1, $calendars);
        $this->assertSame(IcsFeed::CALENDAR_ID, $calendars[0]->id);
        $this->assertSame('Club events', $calendars[0]->name);
        $this->assertSame('Europe/Zurich', $calendars[0]->timezone);
        $this->assertTrue($calendars[0]->readOnly);
        $this->assertSame('https://example.org/club.ics', (string)$this->request(0)->getUri());
    }

    public function testEventsOfThePeriodComeWithStableIds(): void
    {
        $provider = new IcsFeed();
        $this->mock($provider, [new Response(200, [], self::feed())]);
        $utc = new DateTimeZone('UTC');

        $events = $provider->fetchEvents(
            $this->feedConnection(),
            IcsFeed::CALENDAR_ID,
            new DateTimeImmutable('2026-06-08', $utc),
            new DateTimeImmutable('2026-06-16', $utc),
        );
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->remoteId] = $event;
        }

        $this->assertSame(
            ['meeting-1@example.org', 'training@example.org#20260608T170000Z', 'training@example.org#20260615T170000Z', 'trip-1@example.org'],
            (static function(array $ids) {
                sort($ids);
                return $ids;
            })(array_keys($byId)),
        );

        $meeting = $byId['meeting-1@example.org'];
        $this->assertSame('Board meeting', $meeting->title);
        $this->assertSame('2026-06-10 17:00', $meeting->start->format('Y-m-d H:i'));
        $this->assertFalse($meeting->recurring);
        $this->assertFalse($meeting->free);
        $this->assertSame([['title' => 'Agenda.pdf', 'url' => 'https://drive.google.com/file/d/abc/view']], $meeting->attachments);

        $trip = $byId['trip-1@example.org'];
        $this->assertTrue($trip->allDay);
        $this->assertTrue($trip->free);
        $this->assertSame('2026-06-15', $trip->end->format('Y-m-d'));

        $this->assertTrue($byId['training@example.org#20260608T170000Z']->recurring);
    }

    public function testWhatIsNoCalendarIsNotAnEmptyCalendar(): void
    {
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable('2026-06-01', $utc);
        $to = new DateTimeImmutable('2026-07-01', $utc);

        $this->assertNull(Ics::parseFeed('<html><body>Sign in</body></html>', $from, $to));
        $this->assertNull(Ics::feedInfo('not found'));
        $this->assertSame([], Ics::parseFeed("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", $from, $to));
    }

    public function testBusyTimesCarryNoDetails(): void
    {
        $utc = new DateTimeZone('UTC');
        $events = Ics::parseFeed(self::feed(), new DateTimeImmutable('2026-06-08', $utc), new DateTimeImmutable('2026-06-16', $utc)) ?? [];

        $busy = (new Sync())->busyOnly($events, 'Busy');

        // the trip is marked as free: it blocks nothing and is left out
        $this->assertCount(3, $busy);
        foreach ($busy as $event) {
            $this->assertInstanceOf(EventData::class, $event);
            $this->assertSame('Busy', $event->title);
            $this->assertNull($event->description);
            $this->assertNull($event->location);
            $this->assertNull($event->url);
            $this->assertNull($event->organizer);
            $this->assertSame([], $event->attachments);
        }
    }

    public function testAttachmentsAreAChange(): void
    {
        $event = new EventData();
        $event->title = 'Board meeting';
        $before = $event->hash();
        $event->attachments = [['title' => 'Agenda', 'url' => 'https://example.org/agenda.pdf']];

        $this->assertNotSame($before, $event->hash());
        // …but not for the attributes an entry writes
        $this->assertSame($event->hash(['title']), (static function() {
            $plain = new EventData();
            $plain->title = 'Board meeting';

            return $plain->hash(['title']);
        })());
    }

    private static function feed(): string
    {
        // the heredoc is indented for the eye; iCalendar wants no leading blanks and CRLF
        return (string)preg_replace('~^\s+~m', '', str_replace("\n", "\r\n", self::FEED));
    }

    private function feedConnection(): Connection
    {
        $connection = new Connection();
        $connection->provider = 'ics';
        $connection->name = 'Club';
        $connection->settings = ['url' => 'https://example.org/club.ics'];

        return $connection;
    }
}
