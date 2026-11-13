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
}
