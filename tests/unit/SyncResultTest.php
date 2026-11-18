<?php

namespace pixelwerft\calendarsync\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\models\SyncResult;

class SyncResultTest extends TestCase
{
    public function testAStoredRunComesBackAsItWas(): void
    {
        $result = new SyncResult();
        $result->created = 3;
        $result->entriesUpdated = 2;
        $result->pushed = 1;
        $result->addError('Zürich «401»');

        $restored = SyncResult::fromJson($result->toJson());

        $this->assertNotNull($restored);
        $this->assertSame(3, $restored->created);
        $this->assertSame(2, $restored->entriesUpdated);
        $this->assertSame(1, $restored->pushed);
        $this->assertSame(0, $restored->removed);
        $this->assertSame(['Zürich «401»'], $restored->errors);
        $this->assertTrue($restored->hasErrors());
    }

    public function testAPlainMessageIsNotARun(): void
    {
        $this->assertNull(SyncResult::fromJson(null));
        $this->assertNull(SyncResult::fromJson(''));
        $this->assertNull(SyncResult::fromJson('3 events new · 0 changed'));
        $this->assertNull(SyncResult::fromJson('{broken'));
    }

    public function testTheSingleChangesComeBackToo(): void
    {
        $result = new SyncResult();
        $result->preview = true;
        $result->skipped = 2;
        $result->note(SyncResult::EVENT_NEW, 'Board meeting', new \DateTimeImmutable('2026-06-10 19:00'));
        $result->note(SyncResult::SKIPPED, 'Dentist');

        $restored = SyncResult::fromJson($result->toJson());

        $this->assertNotNull($restored);
        $this->assertTrue($restored->preview);
        $this->assertSame(2, $restored->skipped);
        $this->assertSame([
            ['action' => SyncResult::EVENT_NEW, 'title' => 'Board meeting', 'date' => '2026-06-10'],
            ['action' => SyncResult::SKIPPED, 'title' => 'Dentist', 'date' => null],
        ], $restored->changes);
    }

    public function testTheCalendarOnlyKeepsTheNumbers(): void
    {
        $result = new SyncResult();
        $result->created = 1;
        $result->note(SyncResult::EVENT_NEW, 'Board meeting');

        $this->assertStringNotContainsString('changes', $result->toJson(false));
        $this->assertSame(1, SyncResult::fromJson($result->toJson(false))->created);
        $this->assertSame([], SyncResult::fromJson($result->toJson(false))->changes);
    }

    public function testNoRunListsMoreThanTwoHundredChanges(): void
    {
        $result = new SyncResult();
        for ($i = 0; $i < 250; $i++) {
            $result->created++;
            $result->note(SyncResult::EVENT_NEW, "Event $i");
        }

        $this->assertCount(200, $result->changes);
        $this->assertSame(250, $result->created);
        $this->assertTrue($result->hasChanges());
        $this->assertFalse((new SyncResult())->hasChanges());
    }
}
