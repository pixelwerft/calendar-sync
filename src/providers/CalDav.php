<?php

namespace pixelwerft\calendarsync\providers;

use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\helpers\Ics;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\RemoteCalendar;
use Psr\Http\Message\ResponseInterface;

/**
 * Any calendar server that speaks CalDAV (RFC 4791): Nextcloud, iCloud, Fastmail, mailbox.org, Zimbra, Baïkal,
 * Radicale, SOGo and others. A calendar is its address, an event the address of its .ics resource.
 */
class CalDav extends BaseProvider
{
    private const NS = 'xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/"';

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'caldav';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'CalDAV';
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
    public function validateConnection(Connection $connection): void
    {
        $url = trim((string)($connection->settings['serverUrl'] ?? ''));
        if ($url === '') {
            $connection->addError('serverUrl', CalendarSync::t('Enter the address of the server.'));
        } elseif (!preg_match('~^https?://[^\s/]+~i', $url)) {
            $connection->addError('serverUrl', CalendarSync::t('The address has to start with https:// or http://.'));
        }
        if (trim((string)($connection->settings['username'] ?? '')) === '') {
            $connection->addError('username', CalendarSync::t('Enter the user name.'));
        }
        if ((string)($connection->credentials['password'] ?? '') === '') {
            $connection->addError('password', CalendarSync::t('Enter the password.'));
        }
    }

    /**
     * @inheritdoc
     */
    public function test(Connection $connection): ?string
    {
        $this->listCalendars($connection);

        return (string)($connection->settings['username'] ?? '') ?: null;
    }

    /**
     * @inheritdoc
     */
    public function listCalendars(Connection $connection): array
    {
        $server = $this->serverUrl($connection);
        $home = $this->findHome($connection, $server);
        if ($home === null && in_array((new Uri($server))->getPath(), ['', '/'], true)) {
            // RFC 6764: a server that does not answer at its root tells here where it does
            $home = $this->findHome($connection, self::resolve($server, '/.well-known/caldav'), true);
        }

        // no home found: the address may be a calendar itself, or the folder that holds the calendars
        [$xpath, $responses] = $this->propfind($connection, $home ?? $server, '<d:resourcetype/><d:displayname/><a:calendar-color/><c:supported-calendar-component-set/><d:current-user-privilege-set/>', 1);

        $calendars = [];
        foreach ($responses as [$url, $node]) {
            if (!$xpath->evaluate('count(d:propstat/d:prop/d:resourcetype/c:calendar) > 0', $node)) {
                continue;
            }
            $components = [];
            foreach ($xpath->query('d:propstat/d:prop/c:supported-calendar-component-set/c:comp/@name', $node) ?: [] as $attribute) {
                $components[] = strtoupper((string)$attribute->nodeValue);
            }
            if ($components && !in_array('VEVENT', $components, true)) {
                // a task list or a journal
                continue;
            }
            $name = trim((string)$xpath->evaluate('string(d:propstat/d:prop/d:displayname)', $node));
            $color = trim((string)$xpath->evaluate('string(d:propstat/d:prop/a:calendar-color)', $node));
            $privileges = 'd:propstat/d:prop/d:current-user-privilege-set/d:privilege';
            $readOnly = $xpath->evaluate("count($privileges) > 0", $node)
                && !$xpath->evaluate("count($privileges/d:write | $privileges/d:write-content | $privileges/d:bind | $privileges/d:all) > 0", $node);

            $calendars[] = new RemoteCalendar(
                id: $url,
                name: $name !== '' ? $name : rawurldecode(basename(rtrim((new Uri($url))->getPath(), '/'))),
                color: preg_match('~^#[0-9a-f]{6}~i', $color) ? substr($color, 0, 7) : null,
                readOnly: (bool)$readOnly,
            );
        }

        return $calendars;
    }

    /**
     * @inheritdoc
     */
    public function fetchEvents(Connection $connection, string $calendarId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $utc = new DateTimeZone('UTC');
        $body = sprintf(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<c:calendar-query %s><d:prop><d:getetag/><c:calendar-data/></d:prop>'
            . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . '<c:time-range start="%s" end="%s"/>'
            . '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>',
            self::NS,
            DateTimeImmutable::createFromInterface($from)->setTimezone($utc)->format('Ymd\THis\Z'),
            DateTimeImmutable::createFromInterface($to)->setTimezone($utc)->format('Ymd\THis\Z'),
        );
        $response = $this->dav($connection, 'REPORT', $calendarId, $body, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
        [$xpath, $responses] = $this->multistatus($response, $calendarId);

        $timezone = isset(CalendarSync::$plugin) ? new DateTimeZone(\Craft::$app->getTimeZone()) : $utc;
        $events = [];
        foreach ($responses as [$url, $node]) {
            $ics = (string)$xpath->evaluate('string(d:propstat/d:prop/c:calendar-data)', $node);
            if (trim($ics) === '') {
                continue;
            }
            $etag = trim((string)$xpath->evaluate('string(d:propstat/d:prop/d:getetag)', $node)) ?: null;
            foreach (Ics::parse($ics, $url, $from, $to, $timezone) as $event) {
                $event->etag = $etag;
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @inheritdoc
     */
    public function createEvent(Connection $connection, string $calendarId, EventData $event): EventData
    {
        $uid = StringHelper::UUID();
        $url = rtrim($calendarId, '/') . '/' . $uid . '.ics';
        $ics = Ics::build($event, $uid);
        $response = $this->dav($connection, 'PUT', $url, $ics, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // never overwrite what is there
            'If-None-Match' => '*',
        ]);

        return $this->stored($ics, $url, $response);
    }

    /**
     * @inheritdoc
     */
    public function updateEvent(Connection $connection, string $calendarId, string $remoteId, EventData $event, array $attributes): EventData
    {
        $url = self::resource($remoteId);
        $current = $this->dav($connection, 'GET', $url);
        // only the mapped properties change; alarms, guests and whatever else the resource holds stay
        $ics = Ics::patch((string)$current->getBody(), $event, $attributes);
        if ($ics === null) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($current->hasHeader('ETag')) {
            // refuse if someone changed the event in the meantime; the next run sorts it out
            $headers['If-Match'] = $current->getHeaderLine('ETag');
        }
        $response = $this->dav($connection, 'PUT', $url, $ics, $headers);

        return $this->stored($ics, $url, $response);
    }

    /**
     * @inheritdoc
     */
    public function deleteEvent(Connection $connection, string $calendarId, string $remoteId): void
    {
        try {
            $this->dav($connection, 'DELETE', self::resource($remoteId));
        } catch (ProviderException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
        }
    }

    /**
     * What was just written, read the way the next run will read it.
     *
     * @throws ProviderException
     */
    private function stored(string $ics, string $url, ResponseInterface $response): EventData
    {
        $far = new DateTimeZone('UTC');
        $events = Ics::parse($ics, $url, new DateTimeImmutable('1970-01-01', $far), new DateTimeImmutable('2100-01-01', $far), $far);
        if (!$events) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }
        $event = $events[0];
        $event->remoteId = $url;
        $event->etag = $response->hasHeader('ETag') ? $response->getHeaderLine('ETag') : null;

        return $event;
    }

    /**
     * The folder that holds the calendars of the user: the principal of the user says where it is.
     *
     * @throws ProviderException
     */
    private function findHome(Connection $connection, string $url, bool $optional = false): ?string
    {
        try {
            $principal = $this->hrefProperty($connection, $url, '<d:current-user-principal/>', 'd:current-user-principal');

            return $principal !== null
                ? $this->hrefProperty($connection, $principal, '<c:calendar-home-set/>', 'c:calendar-home-set')
                : null;
        } catch (ProviderException $e) {
            // a wrong password is a wrong password everywhere; anything else just means: not here
            if ($e->isUnauthorized() || !($optional || $e->statusCode >= 400)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @throws ProviderException
     */
    private function hrefProperty(Connection $connection, string $url, string $property, string $path): ?string
    {
        [$xpath, $responses] = $this->propfind($connection, $url, $property, 0);
        foreach ($responses as [, $node]) {
            $href = trim((string)$xpath->evaluate("string(d:propstat/d:prop/$path/d:href)", $node));
            if ($href !== '') {
                return self::resolve($url, $href);
            }
        }

        return null;
    }

    /**
     * @return array{0: DOMXPath, 1: array<int, array{0: string, 1: DOMElement}>}
     * @throws ProviderException
     */
    private function propfind(Connection $connection, string $url, string $properties, int $depth): array
    {
        $body = sprintf('<?xml version="1.0" encoding="utf-8"?><d:propfind %s><d:prop>%s</d:prop></d:propfind>', self::NS, $properties);
        $response = $this->dav($connection, 'PROPFIND', $url, $body, [
            'Depth' => (string)$depth,
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);

        return $this->multistatus($response, $url);
    }

    /**
     * The answers of a multi-status reply, each with its address made absolute.
     *
     * @return array{0: DOMXPath, 1: array<int, array{0: string, 1: DOMElement}>}
     * @throws ProviderException
     */
    private function multistatus(ResponseInterface $response, string $requestUrl): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML((string)$response->getBody(), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new ProviderException(sprintf('%s: %s', static::displayName(), CalendarSync::t('The answer could not be read.')));
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $xpath->registerNamespace('a', 'http://apple.com/ns/ical/');

        $responses = [];
        foreach ($xpath->query('/d:multistatus/d:response') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim((string)$xpath->evaluate('string(d:href)', $node));
            if ($href !== '') {
                $responses[] = [self::resolve($requestUrl, $href), $node];
            }
        }

        return [$xpath, $responses];
    }

    /**
     * @param array<string, string> $headers
     * @throws ProviderException
     */
    private function dav(Connection $connection, string $method, string $url, ?string $body = null, array $headers = []): ResponseInterface
    {
        $auth = [(string)($connection->settings['username'] ?? ''), (string)($connection->credentials['password'] ?? '')];
        if (($connection->settings['authType'] ?? 'basic') === 'digest') {
            $auth[] = 'digest';
        }
        $options = [
            'auth' => $auth,
            'headers' => $headers,
            // strict: a redirected PROPFIND stays a PROPFIND
            'allow_redirects' => ['max' => 5, 'strict' => true, 'referer' => false, 'protocols' => ['https', 'http']],
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        return $this->send($method, $url, $options);
    }

    private function serverUrl(Connection $connection): string
    {
        return trim((string)($connection->settings['serverUrl'] ?? ''));
    }

    /** An occurrence of a repeating event is its resource plus `#` and its start. */
    private static function resource(string $remoteId): string
    {
        return explode('#', $remoteId, 2)[0];
    }

    private static function resolve(string $base, string $href): string
    {
        return (string)UriResolver::resolve(new Uri($base), new Uri($href));
    }
}
