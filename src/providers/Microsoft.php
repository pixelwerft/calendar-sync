<?php

namespace pixelwerft\calendarsync\providers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\helpers\Text;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\RemoteCalendar;

/**
 * Microsoft 365, Outlook.com and Exchange Online, over Microsoft Graph.
 */
class Microsoft extends OAuthProvider
{
    private const API = 'https://graph.microsoft.com/v1.0';

    /**
     * Times in UTC, descriptions as text, and IDs that stay the same when an event is moved to another folder.
     */
    private const PREFER = 'outlook.timezone="UTC", outlook.body-content-type="text", IdType="ImmutableId"';

    private const SELECT = 'id,iCalUId,changeKey,subject,body,location,start,end,isAllDay,isCancelled,showAs,sensitivity,categories,type,seriesMasterId,organizer,onlineMeeting,originalStartTimeZone';

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'microsoft';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Microsoft 365 / Outlook';
    }

    protected function clientId(): string
    {
        return CalendarSync::$plugin->getSettings()->getMicrosoftClientId();
    }

    protected function clientSecret(): string
    {
        return CalendarSync::$plugin->getSettings()->getMicrosoftClientSecret();
    }

    protected function authorizeEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', rawurlencode(CalendarSync::$plugin->getSettings()->getMicrosoftTenant()));
    }

    protected function tokenEndpoint(): string
    {
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode(CalendarSync::$plugin->getSettings()->getMicrosoftTenant()));
    }

    protected function scopes(bool $write): array
    {
        // offline_access = a refresh token
        return ['offline_access', 'User.Read', $write ? 'Calendars.ReadWrite' : 'Calendars.Read'];
    }

    protected function authorizeParams(): array
    {
        return ['response_mode' => 'query', 'prompt' => 'select_account'];
    }

    protected function account(Connection $connection): ?string
    {
        $data = $this->api($connection, 'GET', self::API . '/me', ['query' => ['$select' => 'mail,userPrincipalName']]);
        $account = $data['mail'] ?? $data['userPrincipalName'] ?? null;

        return $account ? (string)$account : null;
    }

    /**
     * @inheritdoc
     */
    public function listCalendars(Connection $connection): array
    {
        $calendars = [];
        $url = self::API . '/me/calendars';
        $options = ['query' => ['$top' => 100, '$select' => 'id,name,hexColor,canEdit,isDefaultCalendar']];
        while ($url) {
            $data = $this->graph($connection, 'GET', $url, $options);
            foreach ($data['value'] ?? [] as $item) {
                $calendars[] = new RemoteCalendar(
                    id: (string)$item['id'],
                    name: (string)($item['name'] ?? $item['id']),
                    color: !empty($item['hexColor']) ? (string)$item['hexColor'] : null,
                    readOnly: !($item['canEdit'] ?? true),
                    primary: (bool)($item['isDefaultCalendar'] ?? false),
                );
            }
            // the next page is a whole address with its own query
            $url = $data['@odata.nextLink'] ?? null;
            $options = [];
        }

        return $calendars;
    }

    /**
     * @inheritdoc
     */
    public function fetchEvents(Connection $connection, string $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $events = [];
        // calendarView = repeating events as their single occurrences
        $url = self::API . '/me/calendars/' . rawurlencode($calendarId) . '/calendarView';
        $options = ['query' => [
            'startDateTime' => self::utc($from),
            'endDateTime' => self::utc($to),
            '$top' => 250,
            '$select' => self::SELECT,
        ]];
        while ($url) {
            $data = $this->graph($connection, 'GET', $url, $options);
            foreach ($data['value'] ?? [] as $item) {
                $event = $this->toEventData($item);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
            $url = $data['@odata.nextLink'] ?? null;
            $options = [];
        }

        return $events;
    }

    /**
     * @inheritdoc
     */
    public function createEvent(Connection $connection, string $calendarId, EventData $event): EventData
    {
        $item = $this->graph($connection, 'POST', self::API . '/me/calendars/' . rawurlencode($calendarId) . '/events', [
            'json' => $this->toPayload($event, EventData::WRITABLE),
        ]);

        return $this->created($item);
    }

    /**
     * @inheritdoc
     */
    public function updateEvent(Connection $connection, string $calendarId, string $remoteId, EventData $event, array $attributes): EventData
    {
        $item = $this->graph($connection, 'PATCH', self::API . '/me/events/' . rawurlencode($remoteId), [
            'json' => $this->toPayload($event, $attributes),
        ]);

        return $this->created($item);
    }

    /**
     * @inheritdoc
     */
    public function deleteEvent(Connection $connection, string $calendarId, string $remoteId): void
    {
        try {
            $this->graph($connection, 'DELETE', self::API . '/me/events/' . rawurlencode($remoteId));
        } catch (ProviderException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * An event of Graph in the neutral form.
     *
     * @param array<string, mixed> $item
     */
    public function toEventData(array $item): ?EventData
    {
        if (empty($item['id']) || empty($item['start']['dateTime'])) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $event = new EventData();
        $event->remoteId = (string)$item['id'];
        $event->icalUid = $item['iCalUId'] ?? null;
        $event->etag = $item['changeKey'] ?? null;
        $event->title = (string)($item['subject'] ?? '');
        $description = trim((string)($item['body']['content'] ?? ''));
        if (($item['body']['contentType'] ?? 'text') === 'html') {
            $description = Text::fromHtml($description);
        }
        $event->description = $description !== '' ? $description : null;
        $location = trim((string)($item['location']['displayName'] ?? ''));
        $event->location = $location !== '' ? $location : null;
        $event->url = $item['onlineMeeting']['joinUrl'] ?? null;
        $event->status = match (true) {
            (bool)($item['isCancelled'] ?? false) => EventData::STATUS_CANCELLED,
            ($item['showAs'] ?? '') === 'tentative' => EventData::STATUS_TENTATIVE,
            default => EventData::STATUS_CONFIRMED,
        };
        $event->organizer = $item['organizer']['emailAddress']['name'] ?? $item['organizer']['emailAddress']['address'] ?? null;
        $event->recurring = in_array($item['type'] ?? '', ['occurrence', 'exception'], true) || !empty($item['seriesMasterId']);
        $event->allDay = (bool)($item['isAllDay'] ?? false);
        $event->free = ($item['showAs'] ?? '') === 'free';
        $event->private = in_array($item['sensitivity'] ?? 'normal', ['private', 'confidential'], true);
        $event->categories = array_values(array_filter(array_map('strval', (array)($item['categories'] ?? []))));
        // Graph names time zones the Windows way; only keep what PHP knows
        $original = (string)($item['originalStartTimeZone'] ?? '');
        $event->timezone = $original !== '' && in_array($original, DateTimeZone::listIdentifiers(), true) ? $original : null;

        try {
            $start = self::parse($item['start']);
            $end = isset($item['end']['dateTime']) ? self::parse($item['end']) : $start;
            if ($event->allDay) {
                // an all-day event is midnight to midnight; only the days count
                $event->start = new DateTimeImmutable(substr((string)$item['start']['dateTime'], 0, 10) . ' 00:00:00', $utc);
                $event->end = new DateTimeImmutable(substr((string)($item['end']['dateTime'] ?? $item['start']['dateTime']), 0, 10) . ' 00:00:00', $utc);
                if ($event->end <= $event->start) {
                    $event->end = $event->start->modify('+1 day');
                }
            } else {
                $event->start = $start;
                $event->end = $end < $start ? $start : $end;
            }
        } catch (\Exception) {
            return null;
        }

        return $event;
    }

    /**
     * The body of a create or change request. Graph has no place for a link, so `url` is not written.
     *
     * @param string[] $attributes
     * @return array<string, mixed>
     */
    public function toPayload(EventData $event, array $attributes): array
    {
        $payload = [];
        if (in_array('title', $attributes, true)) {
            $payload['subject'] = $event->title;
        }
        if (in_array('description', $attributes, true)) {
            $payload['body'] = ['contentType' => 'text', 'content' => Text::fromHtml((string)$event->description)];
        }
        if (in_array('location', $attributes, true)) {
            $payload['location'] = ['displayName' => (string)$event->location];
        }
        if (array_intersect(['start', 'end', 'allDay'], $attributes)) {
            $payload['isAllDay'] = $event->allDay;
            $payload['start'] = ['dateTime' => $event->start->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
            $payload['end'] = ['dateTime' => $event->end->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function graph(Connection $connection, string $method, string $url, array $options = []): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Prefer' => self::PREFER];

        return $this->api($connection, $method, $url, $options);
    }

    /**
     * @param array<string, mixed> $item
     * @throws ProviderException
     */
    private function created(array $item): EventData
    {
        $event = $this->toEventData($item);
        if ($event === null) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }

        return $event;
    }

    /**
     * `{dateTime: "2026-11-13T10:00:00.0000000", timeZone: "UTC"}` as a moment in UTC.
     *
     * @param array<string, mixed> $value
     */
    private static function parse(array $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        try {
            $timezone = new DateTimeZone((string)($value['timeZone'] ?? 'UTC'));
        } catch (\Exception) {
            $timezone = $utc;
        }

        // seven digits of a second are more than PHP reads
        return (new DateTimeImmutable(substr((string)$value['dateTime'], 0, 19), $timezone))->setTimezone($utc);
    }

    private static function utc(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
