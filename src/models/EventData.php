<?php

namespace pixelwerft\calendarsync\models;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One event in the neutral form every provider reads and writes.
 *
 * Times are UTC. An all-day event has its dates at 00:00 UTC and an exclusive end: one day on 24 December runs from
 * 24 December 00:00 to 25 December 00:00 – the way Google, Microsoft and iCalendar say it.
 */
class EventData
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_TENTATIVE = 'tentative';
    public const STATUS_CANCELLED = 'cancelled';

    /** What a mapping can read and write. `status` and `organizer` are only read from the calendar. */
    public const ATTRIBUTES = ['title', 'description', 'location', 'url', 'start', 'end', 'allDay', 'status', 'organizer'];
    public const WRITABLE = ['title', 'description', 'location', 'url', 'start', 'end', 'allDay'];

    public string $remoteId = '';
    public ?string $icalUid = null;
    public ?string $etag = null;

    public string $title = '';
    public ?string $description = null;
    public ?string $location = null;
    public ?string $url = null;

    public DateTimeImmutable $start;
    public DateTimeImmutable $end;
    public bool $allDay = false;

    /** The IANA time zone the event was created in, if the provider tells. */
    public ?string $timezone = null;
    public string $status = self::STATUS_CONFIRMED;
    public ?string $organizer = null;

    /** One occurrence of a repeating event. Those are read, never written back. */
    public bool $recurring = false;

    /**
     * Documents attached to the event, e.g. files from Google Drive. Read from the calendar, never written.
     *
     * @var array<int, array{title: string, url: string}>
     */
    public array $attachments = [];

    /** The event does not block its time («show as free»). Only matters to a connection that shows busy times. */
    public bool $free = false;

    public function __construct()
    {
        $this->start = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $this->end = $this->start;
    }

    /**
     * A fingerprint of what the event says, to tell whether it changed since the last run.
     *
     * @param string[]|null $attributes Only these attributes; null = all
     */
    public function hash(?array $attributes = null): string
    {
        $values = [];
        foreach ($attributes ?? self::ATTRIBUTES as $attribute) {
            $values[$attribute] = match ($attribute) {
                'title' => self::text($this->title),
                'description' => self::text($this->description),
                'location' => self::text($this->location),
                'url' => self::text($this->url),
                'start' => $this->start->format('Y-m-d\TH:i'),
                'end' => $this->end->format('Y-m-d\TH:i'),
                'allDay' => $this->allDay,
                'status' => $this->status,
                'organizer' => self::text($this->organizer),
                default => null,
            };
        }
        if ($attributes === null && $this->attachments) {
            $values['attachments'] = $this->attachments;
        }
        ksort($values);

        return md5((string)json_encode($values));
    }

    /**
     * Providers return the same text with different line ends and trailing blanks; those are no change.
     */
    private static function text(?string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$value));
    }
}
