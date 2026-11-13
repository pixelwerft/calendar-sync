<?php

namespace pixelwerft\calendarsync\models;

use craft\base\Model;
use DateTime;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\providers\ProviderInterface;

/**
 * An account at a provider: a Google or Microsoft sign-in, or a CalDAV server with a user.
 *
 * `settings` is what may be shown (server address, user name, the account that signed in), `credentials` is what
 * may not (tokens, password) and is stored encrypted.
 */
class Connection extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';

    /** Craft reads the events and may write entries to the calendar. */
    public const ACCESS_READ_WRITE = 'readwrite';
    /** Craft reads the events and never changes the calendar. */
    public const ACCESS_READ = 'read';
    /** Craft only keeps when something takes place – no title, no place, no text. */
    public const ACCESS_BUSY = 'busy';

    public ?int $id = null;
    public string $name = '';
    public string $provider = '';

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var array<string, mixed> */
    public array $credentials = [];

    public string $status = self::STATUS_PENDING;
    public ?string $lastError = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getProvider(): ?ProviderInterface
    {
        return isset(CalendarSync::$plugin) ? CalendarSync::$plugin->providers->get($this->provider) : null;
    }

    public function getProviderName(): string
    {
        return $this->getProvider()?->displayName() ?? $this->provider;
    }

    /**
     * Who this connection acts as: the e-mail address of the account, or the CalDAV user.
     */
    public function getAccount(): ?string
    {
        $account = $this->settings['account'] ?? $this->settings['username'] ?? null;
        if (($account === null || $account === '') && !empty($this->settings['url'])) {
            // a public calendar has no account; the host says where it comes from
            $account = parse_url((string)$this->settings['url'], PHP_URL_HOST) ?: null;
        }

        return $account !== null && $account !== '' ? (string)$account : null;
    }

    public function getIsConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * What Craft may do with the calendars of this connection. A provider that cannot be written to never gives
     * more than reading.
     */
    public function getAccess(): string
    {
        $access = (string)($this->settings['access'] ?? '');
        $writable = $this->getProvider()?->canWrite() ?? false;
        if (!in_array($access, [self::ACCESS_READ_WRITE, self::ACCESS_READ, self::ACCESS_BUSY], true)) {
            return $writable ? self::ACCESS_READ_WRITE : self::ACCESS_READ;
        }

        return $access === self::ACCESS_READ_WRITE && !$writable ? self::ACCESS_READ : $access;
    }

    public function canWrite(): bool
    {
        return $this->getAccess() === self::ACCESS_READ_WRITE;
    }

    public function getIsBusyOnly(): bool
    {
        return $this->getAccess() === self::ACCESS_BUSY;
    }

    /**
     * What an event is called when only busy times are shown.
     */
    public function getBusyTitle(): string
    {
        $title = trim((string)($this->settings['busyTitle'] ?? ''));

        return $title !== '' ? $title : CalendarSync::t('Busy');
    }

    /**
     * Whether the account has to sign in again: the connection shall write, but the sign-in only asked to read.
     */
    public function getNeedsSignIn(): bool
    {
        $provider = $this->getProvider();
        if ($provider === null || !$provider::usesOAuth()) {
            return false;
        }

        return !$this->getIsConnected()
            || ($this->canWrite() && ($this->settings['grantedAccess'] ?? self::ACCESS_READ_WRITE) !== self::ACCESS_READ_WRITE);
    }

    /**
     * @inheritdoc
     */
    public function fields(): array
    {
        $fields = parent::fields();
        // tokens and passwords never leave the server
        unset($fields['credentials']);

        return $fields;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'name' => CalendarSync::t('Name'),
            'provider' => CalendarSync::t('Provider'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'provider'], 'trim'],
            [['name', 'provider'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['provider'], function(string $attribute) {
                $provider = $this->getProvider();
                if ($provider === null) {
                    $this->addError($attribute, CalendarSync::t('This provider is not available.'));
                    return;
                }
                // the provider knows what it needs: a server address, a user…
                $provider->validateConnection($this);
            }],
        ];
    }
}
