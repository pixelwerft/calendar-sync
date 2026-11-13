<?php

namespace pixelwerft\calendarsync\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\providers\Microsoft;

class MicrosoftTest extends ProviderTestCase
{
    public function testListCalendars(): void
    {
        $microsoft = new Microsoft();
        $this->mock($microsoft, [
            $this->json(['value' => [
                ['id' => 'AAA=', 'name' => 'Calendar', 'hexColor' => '#0078d4', 'canEdit' => true, 'isDefaultCalendar' => true],
            ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/calendars?$skip=1']),
            $this->json(['value' => [
                ['id' => 'BBB=', 'name' => 'Birthdays', 'hexColor' => '', 'canEdit' => false],
            ]]),
        ]);

        $calendars = $microsoft->listCalendars($this->connection('microsoft'));

        $this->assertCount(2, $calendars);
        $this->assertTrue($calendars[0]->primary);
        $this->assertSame('#0078d4', $calendars[0]->color);
        $this->assertTrue($calendars[1]->readOnly);
        $this->assertNull($calendars[1]->color);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/calendars?$skip=1', (string)$this->request(1)->getUri());
    }

    public function testFetchEvents(): void
    {
        $microsoft = new Microsoft();
        $this->mock($microsoft, [$this->json(['value' => [
            [
                'id' => 'evt-1', 'iCalUId' => 'uid-1', 'changeKey' => 'ck1', 'subject' => 'Team meeting',
                'body' => ['contentType' => 'text', 'content' => "Agenda\r\nSecond line\r\n"],
                'location' => ['displayName' => 'Room 4'],
                'start' => ['dateTime' => '2026-12-01T09:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-12-01T10:30:00.0000000', 'timeZone' => 'UTC'],
                'isAllDay' => false, 'isCancelled' => false, 'showAs' => 'busy', 'type' => 'singleInstance',
                'organizer' => ['emailAddress' => ['name' => 'Ada', 'address' => 'ada@example.org']],
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/abc'],
                'originalStartTimeZone' => 'W. Europe Standard Time',
            ],
            [
                'id' => 'evt-2', 'subject' => 'Retreat',
                'body' => ['contentType' => 'html', 'content' => '<html><body><p>Bring boots</p></body></html>'],
                'location' => ['displayName' => ''],
                'start' => ['dateTime' => '2026-12-05T00:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-12-08T00:00:00.0000000', 'timeZone' => 'UTC'],
                'isAllDay' => true, 'showAs' => 'tentative', 'type' => 'occurrence', 'seriesMasterId' => 'master',
            ],
            [
                'id' => 'evt-3', 'subject' => 'Called off', 'isCancelled' => true,
                'start' => ['dateTime' => '2026-12-06T09:00:00.0000000', 'timeZone' => 'UTC'],
                'end' => ['dateTime' => '2026-12-06T10:00:00.0000000', 'timeZone' => 'UTC'],
            ],
        ]])]);

        $utc = new DateTimeZone('UTC');
        $events = $microsoft->fetchEvents($this->connection('microsoft'), 'AAA=', new DateTimeImmutable('2026-11-01', $utc), new DateTimeImmutable('2027-01-01', $utc));

        $this->assertStringContainsString('/me/calendars/AAA%3D/calendarView', (string)$this->request(0)->getUri());
        $this->assertSame('2026-11-01T00:00:00Z', $this->query(0)['startDateTime']);
        $prefer = $this->request(0)->getHeaderLine('Prefer');
        $this->assertStringContainsString('outlook.timezone="UTC"', $prefer);
        $this->assertStringContainsString('outlook.body-content-type="text"', $prefer);
        $this->assertStringContainsString('IdType="ImmutableId"', $prefer);

        $this->assertCount(3, $events);
        [$meeting, $retreat, $cancelled] = $events;
        $this->assertSame('2026-12-01 09:00', $meeting->start->format('Y-m-d H:i'));
        $this->assertSame("Agenda\r\nSecond line", $meeting->description);
        $this->assertSame('Room 4', $meeting->location);
        $this->assertSame('Ada', $meeting->organizer);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/abc', $meeting->url);
        // a Windows name is no time zone PHP knows
        $this->assertNull($meeting->timezone);
        $this->assertFalse($meeting->recurring);

        $this->assertTrue($retreat->allDay);
        $this->assertTrue($retreat->recurring);
        $this->assertSame('Bring boots', $retreat->description);
        $this->assertNull($retreat->location);
        $this->assertSame(EventData::STATUS_TENTATIVE, $retreat->status);
        $this->assertSame('2026-12-05', $retreat->start->format('Y-m-d'));
        $this->assertSame('2026-12-08', $retreat->end->format('Y-m-d'));

        $this->assertSame(EventData::STATUS_CANCELLED, $cancelled->status);
    }

    public function testCreateAndUpdatePayloads(): void
    {
        $utc = new DateTimeZone('UTC');
        $answer = [
            'id' => 'new-1', 'subject' => 'Made in Craft',
            'start' => ['dateTime' => '2026-12-01T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-12-01T10:00:00.0000000', 'timeZone' => 'UTC'],
        ];
        $microsoft = new Microsoft();
        $this->mock($microsoft, [$this->json($answer, 201), $this->json($answer)]);

        $event = new EventData();
        $event->title = 'Made in Craft';
        $event->description = '<p>Rich <strong>text</strong></p>';
        $event->location = 'Online';
        $event->start = new DateTimeImmutable('2026-12-01 09:00', $utc);
        $event->end = new DateTimeImmutable('2026-12-01 10:00', $utc);

        $created = $microsoft->createEvent($this->connection('microsoft'), 'AAA=', $event);
        $this->assertSame('new-1', $created->remoteId);
        $this->assertSame('POST', $this->request(0)->getMethod());
        $body = $this->body(0);
        $this->assertSame('Made in Craft', $body['subject']);
        $this->assertSame(['contentType' => 'text', 'content' => 'Rich text'], $body['body']);
        $this->assertSame(['displayName' => 'Online'], $body['location']);
        $this->assertSame(['dateTime' => '2026-12-01T09:00:00', 'timeZone' => 'UTC'], $body['start']);
        $this->assertFalse($body['isAllDay']);

        $microsoft->updateEvent($this->connection('microsoft'), 'AAA=', 'new-1', $event, ['title']);
        $this->assertSame('PATCH', $this->request(1)->getMethod());
        $this->assertStringEndsWith('/me/events/new-1', $this->request(1)->getUri()->getPath());
        $this->assertSame(['subject' => 'Made in Craft'], $this->body(1));
    }
}
