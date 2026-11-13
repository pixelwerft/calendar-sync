<?php

namespace pixelwerft\calendarsync\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\providers\Google;

class GoogleTest extends ProviderTestCase
{
    public function testListCalendarsFollowsPages(): void
    {
        $google = new Google();
        $this->mock($google, [
            $this->json(['items' => [
                ['id' => 'primary@example.org', 'summary' => 'Ada', 'primary' => true, 'accessRole' => 'owner', 'backgroundColor' => '#4285f4', 'timeZone' => 'Europe/Zurich'],
            ], 'nextPageToken' => 'page-2']),
            $this->json(['items' => [
                ['id' => 'holidays@group.v.calendar.google.com', 'summary' => 'Holidays', 'summaryOverride' => 'Public holidays', 'accessRole' => 'reader'],
            ]]),
        ]);

        $calendars = $google->listCalendars($this->connection('google'));

        $this->assertCount(2, $calendars);
        $this->assertSame('Ada', $calendars[0]->name);
        $this->assertTrue($calendars[0]->primary);
        $this->assertFalse($calendars[0]->readOnly);
        $this->assertSame('Public holidays', $calendars[1]->name);
        $this->assertTrue($calendars[1]->readOnly);
        $this->assertSame('Bearer token-1', $this->request(0)->getHeaderLine('Authorization'));
        $this->assertSame('page-2', $this->query(1)['pageToken']);
    }

    public function testFetchEventsAsksForThePeriodAndSingleOccurrences(): void
    {
        $google = new Google();
        $this->mock($google, [$this->json(['items' => [
            [
                'id' => 'evt1', 'iCalUID' => 'evt1@google.com', 'etag' => '"1"', 'status' => 'confirmed',
                'summary' => 'Team meeting', 'description' => '<b>Agenda</b>', 'location' => 'Room 4',
                'start' => ['dateTime' => '2026-12-01T10:00:00+01:00', 'timeZone' => 'Europe/Zurich'],
                'end' => ['dateTime' => '2026-12-01T11:30:00+01:00', 'timeZone' => 'Europe/Zurich'],
                'organizer' => ['email' => 'ada@example.org', 'displayName' => 'Ada'],
                'hangoutLink' => 'https://meet.google.com/abc',
            ],
            [
                'id' => 'evt2_20261205', 'recurringEventId' => 'evt2', 'status' => 'tentative',
                'summary' => 'Retreat',
                'start' => ['date' => '2026-12-05'],
                'end' => ['date' => '2026-12-08'],
            ],
            ['id' => 'broken', 'summary' => 'No start'],
        ]])]);

        $utc = new DateTimeZone('UTC');
        $events = $google->fetchEvents($this->connection('google'), 'primary@example.org', new DateTimeImmutable('2026-11-01', $utc), new DateTimeImmutable('2027-01-01', $utc));

        $this->assertStringContainsString('/calendars/primary%40example.org/events', (string)$this->request(0)->getUri());
        $query = $this->query(0);
        $this->assertSame('true', $query['singleEvents']);
        $this->assertSame('2026-11-01T00:00:00Z', $query['timeMin']);
        $this->assertSame('2027-01-01T00:00:00Z', $query['timeMax']);

        $this->assertCount(2, $events);
        [$meeting, $retreat] = $events;
        $this->assertSame('evt1', $meeting->remoteId);
        $this->assertSame('2026-12-01 09:00', $meeting->start->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 10:30', $meeting->end->format('Y-m-d H:i'));
        $this->assertSame('Europe/Zurich', $meeting->timezone);
        $this->assertSame('Ada', $meeting->organizer);
        $this->assertSame('https://meet.google.com/abc', $meeting->url);
        $this->assertFalse($meeting->allDay);
        $this->assertFalse($meeting->recurring);

        $this->assertTrue($retreat->allDay);
        $this->assertTrue($retreat->recurring);
        $this->assertSame(EventData::STATUS_TENTATIVE, $retreat->status);
        $this->assertSame('2026-12-05 00:00', $retreat->start->format('Y-m-d H:i'));
        $this->assertSame('2026-12-08 00:00', $retreat->end->format('Y-m-d H:i'));
    }

    public function testCreateSendsATimedEvent(): void
    {
        $google = new Google();
        $this->mock($google, [$this->json([
            'id' => 'new1', 'summary' => 'Made in Craft',
            'start' => ['dateTime' => '2026-12-01T09:00:00Z'], 'end' => ['dateTime' => '2026-12-01T10:00:00Z'],
        ])]);

        $event = $this->event();
        $created = $google->createEvent($this->connection('google'), 'cal', $event);

        $this->assertSame('POST', $this->request(0)->getMethod());
        $body = $this->body(0);
        $this->assertSame('Made in Craft', $body['summary']);
        $this->assertSame('Online', $body['location']);
        $this->assertSame(['dateTime' => '2026-12-01T09:00:00Z', 'timeZone' => 'Europe/Zurich'], $body['start']);
        $this->assertSame('https://example.org/craft', $body['source']['url']);
        $this->assertSame('new1', $created->remoteId);
    }

    public function testUpdatePatchesOnlyTheGivenAttributes(): void
    {
        $google = new Google();
        $this->mock($google, [$this->json([
            'id' => 'evt1', 'summary' => 'Renamed',
            'start' => ['date' => '2026-12-24'], 'end' => ['date' => '2026-12-26'],
        ])]);

        $event = $this->event();
        $event->title = 'Renamed';
        $event->allDay = true;
        $event->start = new DateTimeImmutable('2026-12-24', new DateTimeZone('UTC'));
        $event->end = new DateTimeImmutable('2026-12-26', new DateTimeZone('UTC'));
        $google->updateEvent($this->connection('google'), 'cal', 'evt1', $event, ['title', 'start', 'end', 'allDay']);

        $this->assertSame('PATCH', $this->request(0)->getMethod());
        $this->assertStringEndsWith('/calendars/cal/events/evt1', $this->request(0)->getUri()->getPath());
        $body = $this->body(0);
        $this->assertSame(['summary', 'start', 'end'], array_keys($body));
        // a date replaces a time: the time is sent empty
        $this->assertSame(['date' => '2026-12-24', 'dateTime' => null, 'timeZone' => null], $body['start']);
        $this->assertSame('2026-12-26', $body['end']['date']);
    }

    public function testDeleteOfAGoneEventIsNoError(): void
    {
        $google = new Google();
        $this->mock($google, [new Response(410, [], '{"error":{"message":"Resource has been deleted"}}')]);
        $google->deleteEvent($this->connection('google'), 'cal', 'evt1');
        $this->assertSame('DELETE', $this->request(0)->getMethod());

        $this->mock($google, [new Response(403, [], '{"error":{"message":"Forbidden for this calendar"}}')]);
        try {
            $google->deleteEvent($this->connection('google'), 'cal', 'evt1');
            $this->fail('A refusal has to be reported.');
        } catch (ProviderException $e) {
            $this->assertSame(403, $e->statusCode);
            $this->assertStringContainsString('Forbidden for this calendar', $e->getMessage());
        }
    }

    private function event(): EventData
    {
        $utc = new DateTimeZone('UTC');
        $event = new EventData();
        $event->title = 'Made in Craft';
        $event->location = 'Online';
        $event->url = 'https://example.org/craft';
        $event->timezone = 'Europe/Zurich';
        $event->start = new DateTimeImmutable('2026-12-01 09:00', $utc);
        $event->end = new DateTimeImmutable('2026-12-01 10:00', $utc);

        return $event;
    }
}
