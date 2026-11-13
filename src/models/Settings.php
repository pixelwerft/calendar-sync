<?php

namespace pixelwerft\calendarsync\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings: the OAuth apps of the two cloud providers and the defaults of the sync.
 *
 * The four credential values may be environment variables (`$CRAFT_GOOGLE_CLIENT_ID`), so they stay out of project config.
 */
class Settings extends Model
{
    public string $googleClientId = '';
    public string $googleClientSecret = '';

    public string $microsoftClientId = '';
    public string $microsoftClientSecret = '';

    /** `common` (any account), `organizations`, `consumers` or the ID of one tenant. */
    public string $microsoftTenant = 'common';

    /** Queue calendars that are due while people use the site. Switch off if a cron job runs `calendar-sync/sync`. */
    public bool $autoSync = true;

    /** Send an entry to its calendar right after it was saved, instead of waiting for the next run. */
    public bool $syncOnSave = true;

    /** Defaults of a new calendar. */
    public int $defaultInterval = 60;
    public int $defaultPastDays = 30;
    public int $defaultFutureDays = 365;

    /** Minutes an event lasts when an entry has a start but no end. */
    public int $defaultEventDuration = 60;

    /** Seconds to wait for an answer of a provider. */
    public int $requestTimeout = 30;

    public function getGoogleClientId(): string
    {
        return (string)App::parseEnv($this->googleClientId);
    }

    public function getGoogleClientSecret(): string
    {
        return (string)App::parseEnv($this->googleClientSecret);
    }

    public function getMicrosoftClientId(): string
    {
        return (string)App::parseEnv($this->microsoftClientId);
    }

    public function getMicrosoftClientSecret(): string
    {
        return (string)App::parseEnv($this->microsoftClientSecret);
    }

    public function getMicrosoftTenant(): string
    {
        return (string)App::parseEnv($this->microsoftTenant) ?: 'common';
    }

    /**
     * The intervals a calendar can be synced at, in minutes. 0 = only by hand or by the console command.
     *
     * @return array<int, string>
     */
    public static function intervals(): array
    {
        return [
            0 => 'Only manually',
            5 => 'Every 5 minutes',
            15 => 'Every 15 minutes',
            30 => 'Every 30 minutes',
            60 => 'Every hour',
            180 => 'Every 3 hours',
            360 => 'Every 6 hours',
            720 => 'Every 12 hours',
            1440 => 'Once a day',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['googleClientId', 'googleClientSecret', 'microsoftClientId', 'microsoftClientSecret', 'microsoftTenant'], 'trim'],
            [['microsoftTenant'], 'default', 'value' => 'common'],
            [['autoSync', 'syncOnSave'], 'boolean'],
            [['defaultInterval'], 'in', 'range' => array_keys(self::intervals())],
            [['defaultPastDays', 'defaultFutureDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['defaultEventDuration'], 'integer', 'min' => 1, 'max' => 1440],
            [['requestTimeout'], 'integer', 'min' => 5, 'max' => 300],
        ];
    }
}
