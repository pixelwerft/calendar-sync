<?php

namespace pixelwerft\calendarsync\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
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
     * The address of a page or a file that puts this event into a visitor's own calendar.
     *
     * @param string $type `google`, `outlook` (Outlook.com), `office365`, or `ics` – a file every calendar app opens
     */
    public function getAddToCalendarUrl(string $type = 'ics'): ?string
    {
        $utc = new DateTimeZone('UTC');
        $details = Text::fromHtml((string)$this->description);
        $link = $this->getEntry()?->getUrl() ?? $this->url;
        if ($link) {
            $details = trim($details . "\n\n" . $link);
        }
        $details = mb_substr($details, 0, 1500);

        switch ($type) {
            case 'google':
                $dates = $this->allDay
                    ? $this->startDate->format('Ymd') . '/' . $this->getExclusiveEnd()->format('Ymd')
                    : (clone $this->startDate)->setTimezone($utc)->format('Ymd\THis\Z') . '/' . (clone $this->endDate)->setTimezone($utc)->format('Ymd\THis\Z');

                return 'https://calendar.google.com/calendar/render?' . http_build_query(array_filter([
                    'action' => 'TEMPLATE',
                    'text' => $this->title,
                    'dates' => $dates,
                    'details' => $details,
                    'location' => (string)$this->location,
                ], static fn(string $value) => $value !== ''), '', '&', PHP_QUERY_RFC3986);
            case 'outlook':
            case 'office365':
                $host = $type === 'outlook' ? 'outlook.live.com' : 'outlook.office.com';

                return 'https://' . $host . '/calendar/0/action/compose?' . http_build_query(array_filter([
                    'rru' => 'addevent',
                    'subject' => $this->title,
                    'startdt' => $this->allDay ? $this->startDate->format('Y-m-d') : (clone $this->startDate)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                    'enddt' => $this->allDay ? $this->getExclusiveEnd()->format('Y-m-d') : (clone $this->endDate)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                    'allday' => $this->allDay ? 'true' : 'false',
                    'body' => $details,
                    'location' => (string)$this->location,
                ], static fn(string $value) => $value !== ''), '', '&', PHP_QUERY_RFC3986);
            case 'ics':
                return $this->uid ? UrlHelper::siteUrl(sprintf('calendar-sync/event/%s/%s.ics', $this->uid, self::signature($this->uid))) : null;
        }

        return null;
    }

    /**
     * The links that put this event into a visitor's calendar, ready for a list or a menu.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public function getAddToCalendarLinks(): array
    {
        $links = [];
        foreach (['google' => 'Google Calendar', 'outlook' => 'Outlook.com', 'office365' => 'Microsoft 365', 'ics' => CalendarSync::t('Calendar file (.ics)')] as $type => $label) {
            $url = $this->getAddToCalendarUrl($type);
            if ($url !== null) {
                $links[$type] = ['label' => $label, 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * What makes the address of the calendar file of an event valid: only addresses the site itself has handed out
     * work, so nobody can fetch events by guessing.
     */
    public static function signature(string $uid): string
    {
        return substr(hash_hmac('sha256', 'calendar-sync:event:' . $uid, Craft::$app->getConfig()->getGeneral()->securityKey), 0, 24);
    }

    /**
     * The event as structured data (schema.org), for search engines.
     *
     * @return array<string, mixed>
     */
    public function getJsonLd(): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $this->title,
            'startDate' => $this->allDay ? $this->startDate->format('Y-m-d') : $this->startDate->format(DATE_ATOM),
            'endDate' => $this->allDay ? $this->endDate->format('Y-m-d') : $this->endDate->format(DATE_ATOM),
            'eventStatus' => $this->getIsCancelled() ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
        ];
        $description = Text::singleLine((string)$this->description);
        if ($description !== '') {
            $data['description'] = mb_substr($description, 0, 5000);
        }
        if ((string)$this->location !== '') {
            $data['location'] = ['@type' => 'Place', 'name' => $this->location];
        }
        $url = $this->getEntry()?->getUrl() ?? $this->url;
        if ($url) {
            $data['url'] = $url;
        }

        return $data;
    }

    /**
     * The structured data as a script tag for the head or body of a page.
     */
    public function getJsonLdScript(): \Twig\Markup
    {
        return self::jsonLdScript($this->getJsonLd());
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function jsonLdScript(array $data): \Twig\Markup
    {
        // the hex flags keep a title like «</script>» from ending the tag
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return Template::raw('<script type="application/ld+json">' . $json . '</script>');
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
