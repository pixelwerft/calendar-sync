<?php

namespace pixelwerft\calendarsync\tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\models\EventData;

class EventDataTest extends TestCase
{
    public function testSameContentSameHash(): void
    {
        $a = $this->event();
        $b = $this->event();
        // what differs between providers without being a change
        $b->description = "Agenda\r\nSecond line  ";
        $b->etag = 'another';
        $b->remoteId = 'another';

        $this->assertSame($a->hash(), $b->hash());
    }

    public function testChangedContentChangesHash(): void
    {
        $a = $this->event();
        foreach (['title' => 'Other', 'location' => 'Elsewhere', 'allDay' => true, 'status' => EventData::STATUS_TENTATIVE] as $property => $value) {
            $b = $this->event();
            $b->$property = $value;
            $this->assertNotSame($a->hash(), $b->hash(), $property);
        }
        $b = $this->event();
        $b->start = $b->start->modify('+1 minute');
        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function testHashOfSomeAttributesIgnoresTheOthers(): void
    {
        $a = $this->event();
        $b = $this->event();
        $b->location = 'Elsewhere';
        $b->organizer = 'Someone';

        $this->assertSame($a->hash(['title', 'start', 'end']), $b->hash(['title', 'start', 'end']));
        $this->assertNotSame($a->hash(['title', 'location']), $b->hash(['title', 'location']));
    }

    private function event(): EventData
    {
        $event = new EventData();
        $event->remoteId = 'abc';
        $event->title = 'Team meeting';
        $event->description = "Agenda\nSecond line";
        $event->location = 'Room 4';
        $event->start = new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('UTC'));
        $event->end = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('UTC'));

        return $event;
    }
}
