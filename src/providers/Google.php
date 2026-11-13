<?php

namespace pixelwerft\calendarsync\providers;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\RemoteCalendar;

/**
 * Google Calendar, over the Calendar API v3.
 */
class Google extends OAuthProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'google';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Google Calendar';
    }

    protected function clientId(): string
    {
        return CalendarSync::$plugin->getSettings()->getGoogleClientId();
    }

    protected function clientSecret(): string
    {
        return CalendarSync::$plugin->getSettings()->getGoogleClientSecret();
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(bool $write): array
    {
        $scopes = [
            'openid',
            'email',
            // list the calendars of the account and read their events
            'https://www.googleapis.com/auth/calendar.readonly',
        ];
        if ($write) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar.events';
        }

        return $scopes;
    }

    protected function authorizeParams(): array
    {
        // offline + consent: Google only hands out a refresh token on a consent screen
        return ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];
    }

    protected function account(Connection $connection): ?string
    {
        $data = $this->api($connection, 'GET', 'https://openidconnect.googleapis.com/v1/userinfo');

        return isset($data['email']) ? (string)$data['email'] : null;
    }

    /**
     * @inheritdoc
     */
    public function listCalendars(Connection $connection): array
    {
        $calendars = [];
        $query = ['maxResults' => 250, 'minAccessRole' => 'reader'];
        do {
            $data = $this->api($connection, 'GET', self::API . '/users/me/calendarList', ['query' => $query]);
            foreach ($data['items'] ?? [] as $item) {
                $calendars[] = new RemoteCalendar(
                    id: (string)$item['id'],
                    name: (string)($item['summaryOverride'] ?? $item['summary'] ?? $item['id']),
                    color: $item['backgroundColor'] ?? null,
                    timezone: $item['timeZone'] ?? null,
                    readOnly: !in_array($item['accessRole'] ?? '', ['owner', 'writer'], true),
                    primary: (bool)($item['primary'] ?? false),
                );
            }
            $query['pageToken'] = $data['nextPageToken'] ?? null;
        } while ($query['pageToken']);

        return $calendars;
    }

    /**
     * @inheritdoc
     */
    public function fetchEvents(Connection $connection, string $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $events = [];
        $query = [
            // repeating events as their single occurrences
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'timeMin' => self::rfc3339($from),
            'timeMax' => self::rfc3339($to),
            'maxResults' => 2500,
            'showDeleted' => 'false',
        ];
        do {
            $data = $this->api($connection, 'GET', $this->eventsUrl($calendarId), ['query' => $query]);
            foreach ($data['items'] ?? [] as $item) {
                $event = $this->toEventData($item);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
            $query['pageToken'] = $data['nextPageToken'] ?? null;
        } while ($query['pageToken']);

        return $events;
    }

    /**
     * @inheritdoc
     */
    public function createEvent(Connection $connection, string $calendarId, EventData $event): EventData
    {
        $item = $this->api($connection, 'POST', $this->eventsUrl($calendarId), [
            'json' => $this->toPayload($event, EventData::WRITABLE, true),
        ]);

        return $this->created($item);
    }

    /**
     * @inheritdoc
     */
    public function updateEvent(Connection $connection, string $calendarId, string $remoteId, EventData $event, array $attributes): EventData
    {
        // PATCH: only what is sent changes, guests, reminders and the like stay
        $item = $this->api($connection, 'PATCH', $this->eventsUrl($calendarId) . '/' . rawurlencode($remoteId), [
            'json' => $this->toPayload($event, $attributes, false),
        ]);

        return $this->created($item);
    }

    /**
     * @inheritdoc
     */
    public function deleteEvent(Connection $connection, string $calendarId, string $remoteId): void
    {
        try {
            $this->api($connection, 'DELETE', $this->eventsUrl($calendarId) . '/' . rawurlencode($remoteId));
        } catch (ProviderException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * An event of the API in the neutral form. Null for what is no event with a start.
     *
     * @param array<string, mixed> $item
     */
    public function toEventData(array $item): ?EventData
    {
        if (empty($item['id']) || empty($item['start'])) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $event = new EventData();
        $event->remoteId = (string)$item['id'];
        $event->icalUid = $item['iCalUID'] ?? null;
        $event->etag = $item['etag'] ?? null;
        $event->title = (string)($item['summary'] ?? '');
        $event->description = $item['description'] ?? null;
        $event->location = $item['location'] ?? null;
        $event->url = $item['source']['url'] ?? $item['hangoutLink'] ?? null;
        $event->status = match ($item['status'] ?? '') {
            'cancelled' => EventData::STATUS_CANCELLED,
            'tentative' => EventData::STATUS_TENTATIVE,
            default => EventData::STATUS_CONFIRMED,
        };
        $event->organizer = $item['organizer']['displayName'] ?? $item['organizer']['email'] ?? null;
        $event->recurring = !empty($item['recurringEventId']);
        $event->free = ($item['transparency'] ?? 'opaque') === 'transparent';
        foreach ($item['attachments'] ?? [] as $attachment) {
            if (!empty($attachment['fileUrl'])) {
                $event->attachments[] = [
                    'title' => (string)($attachment['title'] ?? $attachment['fileUrl']),
                    'url' => (string)$attachment['fileUrl'],
                ];
            }
        }
        $event->timezone = $item['start']['timeZone'] ?? null;

        try {
            if (isset($item['start']['date'])) {
                $event->allDay = true;
                $event->start = new DateTimeImmutable($item['start']['date'] . ' 00:00:00', $utc);
                $event->end = new DateTimeImmutable(($item['end']['date'] ?? $item['start']['date']) . ' 00:00:00', $utc);
                if ($event->end <= $event->start) {
                    $event->end = $event->start->modify('+1 day');
                }
            } else {
                $event->start = (new DateTimeImmutable((string)$item['start']['dateTime']))->setTimezone($utc);
                $event->end = isset($item['end']['dateTime'])
                    ? (new DateTimeImmutable((string)$item['end']['dateTime']))->setTimezone($utc)
                    : $event->start;
                if ($event->end < $event->start) {
                    $event->end = $event->start;
                }
            }
        } catch (\Exception) {
            return null;
        }

        return $event;
    }

    /**
     * The body of a create or change request.
     *
     * @param string[] $attributes
     * @return array<string, mixed>
     */
    public function toPayload(EventData $event, array $attributes, bool $create): array
    {
        $payload = [];
        if (in_array('title', $attributes, true)) {
            $payload['summary'] = $event->title;
        }
        if (in_array('description', $attributes, true)) {
            $payload['description'] = (string)$event->description;
        }
        if (in_array('location', $attributes, true)) {
            $payload['location'] = (string)$event->location;
        }
        if (in_array('url', $attributes, true)) {
            if ($event->url) {
                $payload['source'] = ['url' => $event->url, 'title' => $event->title ?: $event->url];
            } elseif (!$create) {
                $payload['source'] = null;
            }
        }
        if (array_intersect(['start', 'end', 'allDay'], $attributes)) {
            // a date and a time exclude each other: the one not used is sent empty so a change of kind works
            if ($event->allDay) {
                $payload['start'] = ['date' => $event->start->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null];
                $payload['end'] = ['date' => $event->end->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null];
            } else {
                $timezone = $event->timezone ?: 'UTC';
                $payload['start'] = ['dateTime' => self::rfc3339($event->start), 'timeZone' => $timezone, 'date' => null];
                $payload['end'] = ['dateTime' => self::rfc3339($event->end), 'timeZone' => $timezone, 'date' => null];
            }
            if ($create) {
                $payload['start'] = array_filter($payload['start']);
                $payload['end'] = array_filter($payload['end']);
            }
        }

        return $payload;
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

    private function eventsUrl(string $calendarId): string
    {
        return self::API . '/calendars/' . rawurlencode($calendarId) . '/events';
    }

    private static function rfc3339(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
