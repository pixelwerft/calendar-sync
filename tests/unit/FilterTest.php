<?php

namespace pixelwerft\calendarsync\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\providers\Google;
use pixelwerft\calendarsync\providers\Microsoft;

class FilterTest extends TestCase
{
    public function testWithoutFiltersEveryEventPasses(): void
    {
        $calendar = new Calendar();

        $this->assertFalse($calendar->hasFilters());
        $this->assertTrue($calendar->accepts($this->event('Dentist', private: true)));
    }

    public function testPrivateEventsCanBeLeftOut(): void
    {
        $calendar = new Calendar(['skipPrivate' => true]);

        $this->assertTrue($calendar->hasFilters());
        $this->assertFalse($calendar->accepts($this->event('Dentist', private: true)));
        $this->assertTrue($calendar->accepts($this->event('Board meeting')));
    }

    public function testWordsAreFoundInTitleDescriptionLocationAndCategories(): void
    {
        $calendar = new Calendar(['filterInclude' => "Public,\n open DAY "]);

        $this->assertTrue($calendar->accepts($this->event('Our Open Day')));
        $this->assertTrue($calendar->accepts($this->event('Concert', categories: ['Website', 'public'])));
        $this->assertTrue($calendar->accepts($this->event('Concert', description: 'A PUBLIC evening')));
        $this->assertTrue($calendar->accepts($this->event('Concert', location: 'Public library')));
        $this->assertFalse($calendar->accepts($this->event('Board meeting', categories: ['Internal'])));
    }

    public function testExcludingBeatsIncluding(): void
    {
        $calendar = new Calendar(['filterInclude' => 'public', 'filterExclude' => 'cancelled, internal']);

        $this->assertTrue($calendar->accepts($this->event('Public reading')));
        $this->assertFalse($calendar->accepts($this->event('Public reading (cancelled)')));
        $this->assertFalse($calendar->accepts($this->event('Public reading', categories: ['Internal'])));
    }

    public function testEmptyListsAreNoFilter(): void
    {
        $calendar = new Calendar(['filterInclude' => " , \n ", 'filterExclude' => '']);

        $this->assertFalse($calendar->hasFilters());
        $this->assertTrue($calendar->accepts($this->event('Anything')));
    }

    public function testGoogleTellsWhatIsPrivate(): void
    {
        $google = new Google();
        $item = ['id' => 'a', 'summary' => 'Dentist', 'start' => ['dateTime' => '2026-06-10T09:00:00Z'], 'end' => ['dateTime' => '2026-06-10T10:00:00Z']];

        $this->assertFalse($google->toEventData($item)->private);
        $this->assertTrue($google->toEventData($item + ['visibility' => 'private'])->private);
        $this->assertTrue($google->toEventData($item + ['visibility' => 'confidential'])->private);
        $this->assertFalse($google->toEventData($item + ['visibility' => 'public'])->private);
    }

    public function testMicrosoftTellsWhatIsPrivateAndTheCategories(): void
    {
        $microsoft = new Microsoft();
        $item = [
            'id' => 'a', 'subject' => 'Review',
            'start' => ['dateTime' => '2026-06-10T09:00:00.0000000', 'timeZone' => 'UTC'],
            'end' => ['dateTime' => '2026-06-10T10:00:00.0000000', 'timeZone' => 'UTC'],
        ];

        $plain = $microsoft->toEventData($item);
        $this->assertFalse($plain->private);
        $this->assertSame([], $plain->categories);

        $marked = $microsoft->toEventData($item + ['sensitivity' => 'private', 'categories' => ['Website', 'Red category']]);
        $this->assertTrue($marked->private);
        $this->assertSame(['Website', 'Red category'], $marked->categories);
    }

    /**
     * @param string[] $categories
     */
    private function event(string $title, bool $private = false, array $categories = [], ?string $description = null, ?string $location = null): EventData
    {
        $event = new EventData();
        $event->title = $title;
        $event->private = $private;
        $event->categories = $categories;
        $event->description = $description;
        $event->location = $location;

        return $event;
    }
}
