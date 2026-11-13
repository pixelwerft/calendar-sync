<?php

namespace pixelwerft\calendarsync\providers;

use DateTimeInterface;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\RemoteCalendar;

/**
 * What the sync needs from a calendar service. Implement it to add one, and register the class with
 * `Providers::EVENT_REGISTER_PROVIDERS`.
 */
interface ProviderInterface
{
    /** The key stored with a connection, e.g. `google`. */
    public static function handle(): string;

    public static function displayName(): string;

    /** Whether a connection is made by signing in at the provider (true) or by entering an address and a user. */
    public static function usesOAuth(): bool;

    /** Whether events can be written to this provider at all. A public calendar address can only be read. */
    public static function canWrite(): bool;

    /** Whether everything is set that the provider needs before anyone can connect (the OAuth app). */
    public function isConfigured(): bool;

    /**
     * Adds errors to the connection if something the provider needs is missing.
     */
    public function validateConnection(Connection $connection): void;

    /**
     * Checks that the connection works and returns who it acts as.
     *
     * @throws ProviderException
     */
    public function test(Connection $connection): ?string;

    /**
     * @return RemoteCalendar[]
     * @throws ProviderException
     */
    public function listCalendars(Connection $connection): array;

    /**
     * The events between the two moments, repeating events as their single occurrences.
     *
     * @return EventData[]
     * @throws ProviderException
     */
    public function fetchEvents(Connection $connection, string $calendarId, DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * Creates the event and returns it as the provider stored it, with its ID.
     *
     * @throws ProviderException
     */
    public function createEvent(Connection $connection, string $calendarId, EventData $event): EventData;

    /**
     * Changes the given attributes of an event and leaves everything else of it alone.
     *
     * @param string[] $attributes Of EventData::WRITABLE
     * @throws ProviderException
     */
    public function updateEvent(Connection $connection, string $calendarId, string $remoteId, EventData $event, array $attributes): EventData;

    /**
     * Removes the event. An event that is already gone is no error.
     *
     * @throws ProviderException
     */
    public function deleteEvent(Connection $connection, string $calendarId, string $remoteId): void;
}
