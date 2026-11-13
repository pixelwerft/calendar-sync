<?php

namespace pixelwerft\calendarsync\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\helpers\Text;

/**
 * A synced event as templates get it.
 *
 * `startDate` and `endDate` are in the time zone of the site. For an all-day event both are days at 00:00 and
 * `endDate` is the last day of the event – so one day on 24 December starts and ends on 24 December.
 */
class Event extends Model
{
    public const ORIGIN_REMOTE = 'remote';
    public const ORIGIN_CRAFT = 'craft';

    public ?int $id = null;
    public int $calendarId = 0;
    public string $remoteId = '';
    public ?string $icalUid = null;
    public ?string $etag = null;

    public string $title = '';
    public ?string $description = null;
    public ?string $location = null;
    public ?string $url = null;

    /**
     * Documents attached to the event in the calendar, e.g. files from Google Drive.
     *
     * @var array<int, array{title: string, url: string}>
     */
    public array $attachments = [];

    public DateTime $startDate;
    public DateTime $endDate;
    public bool $allDay = false;
    public ?string $timezone = null;
    public string $status = EventData::STATUS_CONFIRMED;
    public ?string $organizer = null;

    /** One occurrence of a repeating event. */
    public bool $recurring = false;

    /** Where the event was created: in the calendar (`remote`) or from an entry (`craft`). */
    public string $origin = self::ORIGIN_REMOTE;

    public ?int $entryId = null;
    public ?string $remoteHash = null;
    public ?string $entryHash = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private Entry|false|null $entry = null;

    public function __construct($config = [])
    {
        $this->startDate = new DateTime();
        $this->endDate = new DateTime();
        parent::__construct($config);
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getCalendar(): ?Calendar
    {
        return CalendarSync::$plugin->calendars->getCalendarById($this->calendarId);
    }

    /**
     * The entry this event is mapped to, in the site of the calendar. Null if the calendar has no section, or the
     * entry is not live.
     */
    public function getEntry(): ?Entry
    {
        if ($this->entry === null) {
            $this->entry = false;
            if ($this->entryId) {
                $this->entry = Entry::find()
                    ->id($this->entryId)
                    ->siteId($this->getCalendar()?->getSiteId())
                    ->one() ?? false;
            }
        }

        return $this->entry ?: null;
    }

    /**
     * Sets the entry, so a list of events does not look each one up.
     */
    public function setEntry(?Entry $entry): void
    {
        $this->entry = $entry ?? false;
    }

    /** The first moment after the event; for an all-day event the day after its last day. */
    public function getExclusiveEnd(): DateTime
    {
        return $this->allDay ? (clone $this->endDate)->modify('+1 day') : clone $this->endDate;
    }

    public function getIsMultiDay(): bool
    {
        return $this->startDate->format('Y-m-d') !== $this->endDate->format('Y-m-d');
    }

    public function getIsPast(): bool
    {
        return $this->getExclusiveEnd() <= new DateTime();
    }

    public function getIsOngoing(): bool
    {
        $now = new DateTime();

        return $this->startDate <= $now && $this->getExclusiveEnd() > $now;
    }

    public function getIsUpcoming(): bool
    {
        return $this->startDate > new DateTime();
    }

    public function getIsCancelled(): bool
    {
        return $this->status === EventData::STATUS_CANCELLED;
    }

    /** Length in minutes; an all-day event counts whole days. */
    public function getDuration(): int
    {
        return (int)round(($this->getExclusiveEnd()->getTimestamp() - $this->startDate->getTimestamp()) / 60);
    }

    /**
     * The description as safe HTML, the way its calendar wants it: with or without its line breaks, addresses as
     * links, attached documents as a list. HTML from the calendar is cleaned.
     */
    public function getDescriptionHtml(): string
    {
        $calendar = $this->getCalendar();
        $html = Text::toHtml((string)$this->description, $calendar->descriptionLineBreaks ?? true);
        if ($html !== '') {
            $html = \yii\helpers\HtmlPurifier::process($html);
            if ($calendar->descriptionLinks ?? true) {
                $html = Text::linkify($html);
            }
        }
        if ($calendar->descriptionDocuments ?? true) {
            $html .= Text::documentList($this->attachments);
        }

        return $html;
    }

    /**
     * The event in the neutral form of the providers.
     */
    public function toEventData(): EventData
    {
        $utc = new DateTimeZone('UTC');
        $data = new EventData();
        $data->remoteId = $this->remoteId;
        $data->icalUid = $this->icalUid;
        $data->etag = $this->etag;
        $data->title = $this->title;
        $data->description = $this->description;
        $data->location = $this->location;
        $data->url = $this->url;
        $data->attachments = $this->attachments;
        $data->allDay = $this->allDay;
        if ($this->allDay) {
            $data->start = new DateTimeImmutable($this->startDate->format('Y-m-d') . ' 00:00:00', $utc);
            $data->end = new DateTimeImmutable($this->getExclusiveEnd()->format('Y-m-d') . ' 00:00:00', $utc);
        } else {
            $data->start = DateTimeImmutable::createFromMutable($this->startDate)->setTimezone($utc);
            $data->end = DateTimeImmutable::createFromMutable($this->endDate)->setTimezone($utc);
        }
        $data->timezone = $this->timezone;
        $data->status = $this->status;
        $data->organizer = $this->organizer;
        $data->recurring = $this->recurring;

        return $data;
    }

    /**
     * The event for a JSON feed, in the shape calendar scripts such as FullCalendar expect.
     *
     * @return array<string, mixed>
     */
    public function toFeedArray(): array
    {
        $entry = $this->getEntry();

        return [
            'id' => $this->uid,
            'title' => $this->title,
            'start' => $this->allDay ? $this->startDate->format('Y-m-d') : $this->startDate->format(DATE_ATOM),
            'end' => $this->allDay ? $this->getExclusiveEnd()->format('Y-m-d') : $this->endDate->format(DATE_ATOM),
            'allDay' => $this->allDay,
            'url' => $entry?->getUrl() ?? $this->url,
            'extendedProps' => [
                'description' => $this->description,
                'location' => $this->location,
                'status' => $this->status,
                'recurring' => $this->recurring,
                'calendar' => $this->getCalendar()?->handle,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function extraFields(): array
    {
        return ['calendar', 'entry'];
    }

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();
        // the fingerprints are bookkeeping of the sync
        unset($fields['remoteHash'], $fields['entryHash'], $fields['etag']);

        return $fields;
    }

    /**
     * The time zone of the site: what `startDate` and `endDate` are in.
     */
    public static function displayTimeZone(): DateTimeZone
    {
        return new DateTimeZone(Craft::$app->getTimeZone());
    }
}
