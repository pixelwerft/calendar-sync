<?php

namespace pixelwerft\calendarsync\providers;

use Craft;
use DateTimeInterface;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\helpers\Ics;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\RemoteCalendar;

/**
 * A calendar that is public at an address (iCalendar, `.ics`) – the «public address in iCal format» of a Google
 * calendar, a published Outlook calendar, or any other calendar file on the web. Nobody signs in, and nothing can
 * be written: the address is read, that is all.
 */
class IcsFeed extends BaseProvider
{
    /** The address holds one calendar; this is its ID. */
    public const CALENDAR_ID = 'feed';

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'ics';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        // without Craft – in a unit test – the name stays as it is written here
        return Craft::$app !== null ? CalendarSync::t('Public calendar (iCal address)') : 'Public calendar (iCal address)';
    }

    /**
     * @inheritdoc
     */
    public static function usesOAuth(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function canWrite(): bool
    {
        return false;
    }

    /**
     * The address as it is requested: `webcal://` is https, and the bare ID of a Google calendar becomes the public
     * address of that calendar.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('~^webcals?://~i', $url)) {
            return 'https://' . preg_replace('~^webcals?://~i', '', $url);
        }
        if ($url !== '' && !str_contains($url, '://') && !str_contains($url, '/') && preg_match('~^[^@\s]+@[^@\s]+\.[^@\s]+$~', $url)) {
            return sprintf('https://calendar.google.com/calendar/ical/%s/public/basic.ics', rawurlencode($url));
        }

        return $url;
    }

    /**
     * @inheritdoc
     */
    public function validateConnection(Connection $connection): void
    {
        $url = (string)($connection->settings['url'] ?? '');
        if ($url === '') {
            $connection->addError('url', CalendarSync::t('Enter the address of the calendar.'));
        } elseif (!preg_match('~^https?://[^\s/]+~i', $url)) {
            $connection->addError('url', CalendarSync::t('The address has to start with https:// or http://.'));
        }
    }

    /**
     * @inheritdoc
     */
    public function test(Connection $connection): ?string
    {
        $this->fetch($connection);

        return $connection->getAccount();
    }

    /**
     * @inheritdoc
     */
    public function listCalendars(Connection $connection): array
    {
        $info = Ics::feedInfo($this->fetch($connection)) ?? ['name' => null, 'timezone' => null];

        return [
            new RemoteCalendar(
                id: self::CALENDAR_ID,
                name: $info['name'] ?? $connection->name,
                timezone: $info['timezone'],
                readOnly: true,
                primary: true,
            ),
        ];
    }

    /**
     * @inheritdoc
     */
    public function fetchEvents(Connection $connection, string $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $ics = $this->fetch($connection);
        $info = Ics::feedInfo($ics);
        $timezone = !empty($info['timezone']) ? new DateTimeZone($info['timezone']) : null;
        $events = Ics::parseFeed($ics, $from, $to, $timezone);
        if ($events === null) {
            // nothing is known – which must never look like an empty calendar
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }

        return $events;
    }

    /**
     * @inheritdoc
     */
    public function createEvent(Connection $connection, string $calendarId, EventData $event): EventData
    {
        throw $this->readOnly();
    }

    /**
     * @inheritdoc
     */
    public function updateEvent(Connection $connection, string $calendarId, string $remoteId, EventData $event, array $attributes): EventData
    {
        throw $this->readOnly();
    }

    /**
     * @inheritdoc
     */
    public function deleteEvent(Connection $connection, string $calendarId, string $remoteId): void
    {
        throw $this->readOnly();
    }

    /**
     * The calendar file behind the address.
     *
     * @throws ProviderException
     */
    private function fetch(Connection $connection): string
    {
        $response = $this->send('GET', (string)($connection->settings['url'] ?? ''), [
            'headers' => ['Accept' => 'text/calendar, text/plain;q=0.8, */*;q=0.5'],
        ]);
        $body = (string)$response->getBody();
        if (!str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('This address does not return a calendar. Is the calendar public, and is it the address in iCal format?')));
        }

        return $body;
    }

    private function readOnly(): ProviderException
    {
        return new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('A public calendar address can only be read.')));
    }
}
