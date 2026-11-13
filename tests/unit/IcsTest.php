<?php

namespace pixelwerft\calendarsync\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\helpers\Ics;
use pixelwerft\calendarsync\models\EventData;

class IcsTest extends TestCase
{
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;

    protected function setUp(): void
    {
        $utc = new DateTimeZone('UTC');
        $this->from = new DateTimeImmutable('2026-11-01', $utc);
        $this->to = new DateTimeImmutable('2027-01-01', $utc);
    }

    public function testTimedEventWithTimeZone(): void
    {
        $events = Ics::parse($this->ics("
BEGIN:VEVENT
UID:timed-1
DTSTART;TZID=Europe/Zurich:20261201T100000
DTEND;TZID=Europe/Zurich:20261201T113000
SUMMARY:Team meeting
LOCATION:Room 4
DESCRIPTION:Agenda\\nSecond line
URL:https://example.org/meeting
ORGANIZER;CN=Ada Lovelace:mailto:ada@example.org
STATUS:TENTATIVE
END:VEVENT"), '/cal/timed.ics', $this->from, $this->to);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('/cal/timed.ics', $event->remoteId);
        $this->assertSame('timed-1', $event->icalUid);
        $this->assertSame('Team meeting', $event->title);
        $this->assertSame("Agenda\nSecond line", $event->description);
        $this->assertSame('Room 4', $event->location);
        $this->assertSame('https://example.org/meeting', $event->url);
        $this->assertSame('Ada Lovelace', $event->organizer);
        $this->assertSame(EventData::STATUS_TENTATIVE, $event->status);
        $this->assertFalse($event->allDay);
        $this->assertFalse($event->recurring);
        $this->assertSame('Europe/Zurich', $event->timezone);
        // 10:00 in Zurich in winter is 09:00 UTC
        $this->assertSame('2026-12-01 09:00', $event->start->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 10:30', $event->end->format('Y-m-d H:i'));
    }

    public function testAllDayEventKeepsItsDaysInEveryTimeZone(): void
    {
        $ics = $this->ics("
BEGIN:VEVENT
UID:allday-1
DTSTART;VALUE=DATE:20261224
DTEND;VALUE=DATE:20261227
SUMMARY:Holidays
END:VEVENT");

        foreach (['UTC', 'America/Los_Angeles', 'Pacific/Auckland'] as $timezone) {
            $event = Ics::parse($ics, '/cal/a.ics', $this->from, $this->to, new DateTimeZone($timezone))[0];
            $this->assertTrue($event->allDay, $timezone);
            $this->assertSame('2026-12-24 00:00', $event->start->format('Y-m-d H:i'), $timezone);
            // the end is the day after the last day
            $this->assertSame('2026-12-27 00:00', $event->end->format('Y-m-d H:i'), $timezone);
        }
    }

    public function testEventWithoutEnd(): void
    {
        $allDay = Ics::parse($this->ics("
BEGIN:VEVENT
UID:a
DTSTART;VALUE=DATE:20261224
SUMMARY:One day
END:VEVENT"), '/a.ics', $this->from, $this->to)[0];
        $this->assertSame('2026-12-25', $allDay->end->format('Y-m-d'));

        $duration = Ics::parse($this->ics("
BEGIN:VEVENT
UID:b
DTSTART:20261224T100000Z
DURATION:PT45M
SUMMARY:With a duration
END:VEVENT"), '/b.ics', $this->from, $this->to)[0];
        $this->assertSame('10:45', $duration->end->format('H:i'));
    }

    public function testRepeatingEventComesAsItsOccurrences(): void
    {
        $events = Ics::parse($this->ics("
BEGIN:VEVENT
UID:weekly-1
DTSTART;TZID=Europe/Zurich:20261102T090000
DTEND;TZID=Europe/Zurich:20261102T093000
RRULE:FREQ=WEEKLY;COUNT=4
EXDATE;TZID=Europe/Zurich:20261109T090000
SUMMARY:Standup
END:VEVENT"), '/cal/weekly.ics', $this->from, $this->to);

        // four occurrences, one of them excluded
        $this->assertCount(3, $events);
        $ids = [];
        foreach ($events as $event) {
            $this->assertTrue($event->recurring);
            $this->assertSame('Standup', $event->title);
            $this->assertSame('08:00', $event->start->format('H:i'));
            $ids[] = $event->remoteId;
        }
        $this->assertSame([
            '/cal/weekly.ics#20261102T080000Z',
            '/cal/weekly.ics#20261116T080000Z',
            '/cal/weekly.ics#20261123T080000Z',
        ], $ids);
    }

    public function testBuildAndParseAgree(): void
    {
        $utc = new DateTimeZone('UTC');
        $event = new EventData();
        $event->title = 'Made in Craft; with, signs';
        $event->description = "Line one\nLine two";
        $event->location = 'Online';
        $event->url = 'https://example.org/craft';
        $event->start = new DateTimeImmutable('2026-12-01 09:00', $utc);
        $event->end = new DateTimeImmutable('2026-12-01 10:15', $utc);

        $parsed = Ics::parse(Ics::build($event, 'uid-1'), '/x.ics', $this->from, $this->to)[0];
        $this->assertSame($event->hash(EventData::WRITABLE), $parsed->hash(EventData::WRITABLE));
        $this->assertSame('uid-1', $parsed->icalUid);

        $event->allDay = true;
        $event->start = new DateTimeImmutable('2026-12-24', $utc);
        $event->end = new DateTimeImmutable('2026-12-26', $utc);
        $ics = Ics::build($event, 'uid-2');
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261224', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20261226', $ics);
        $this->assertSame($event->hash(EventData::WRITABLE), Ics::parse($ics, '/y.ics', $this->from, $this->to)[0]->hash(EventData::WRITABLE));
    }

    public function testPatchChangesOnlyWhatIsAsked(): void
    {
        $ics = $this->ics("
BEGIN:VEVENT
UID:timed-1
SEQUENCE:2
DTSTART;TZID=Europe/Zurich:20261201T100000
DTEND;TZID=Europe/Zurich:20261201T113000
SUMMARY:Team meeting
LOCATION:Room 4
X-CUSTOM:keep me
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Reminder
END:VALARM
END:VEVENT");
        $event = new EventData();
        $event->title = 'Renamed';
        $event->location = null;

        $patched = (string)Ics::patch($ics, $event, ['title', 'location']);

        $this->assertStringContainsString('SUMMARY:Renamed', $patched);
        $this->assertStringNotContainsString('LOCATION', $patched);
        $this->assertStringContainsString('DTSTART;TZID=Europe/Zurich:20261201T100000', $patched);
        $this->assertStringContainsString('X-CUSTOM:keep me', $patched);
        $this->assertStringContainsString('BEGIN:VALARM', $patched);
        $this->assertStringContainsString('SEQUENCE:3', $patched);
    }

    public function testFeed(): void
    {
        $event = new EventData();
        $event->title = 'Public thing';
        $event->start = new DateTimeImmutable('2026-12-01 09:00', new DateTimeZone('UTC'));
        $event->end = new DateTimeImmutable('2026-12-01 10:00', new DateTimeZone('UTC'));

        $feed = Ics::feed('Team', [['uid' => 'row-1@example.org', 'event' => $event, 'url' => 'https://example.org/e/1']], '#34a853');

        $this->assertStringContainsString('X-WR-CALNAME:Team', $feed);
        $this->assertStringContainsString('UID:row-1@example.org', $feed);
        $this->assertStringContainsString('SUMMARY:Public thing', $feed);
        $this->assertStringContainsString('https://example.org/e/1', $feed);
        $this->assertSame(1, substr_count($feed, 'BEGIN:VEVENT'));
    }

    public function testRubbishIsNoEvent(): void
    {
        $this->assertSame([], Ics::parse('not a calendar', '/x.ics', $this->from, $this->to));
        $this->assertNull(Ics::patch('not a calendar', new EventData(), ['title']));
    }

    private function ics(string $body): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n" . str_replace("\n", "\r\n", trim($body)) . "\r\nEND:VCALENDAR\r\n";
    }
}
