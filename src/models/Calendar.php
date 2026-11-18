<?php

namespace pixelwerft\calendarsync\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\validators\HandleValidator;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;

/**
 * One calendar of a connection and how it is synced: in which direction, how often, over which period, and –
 * optionally – with the entries of which section and which of their fields.
 */
class Calendar extends Model
{
    public const DIRECTION_IMPORT = 'import';
    public const DIRECTION_EXPORT = 'export';
    public const DIRECTION_BOTH = 'both';

    public const WINNER_REMOTE = 'remote';
    public const WINNER_LOCAL = 'local';

    public const MISSING_DELETE = 'delete';
    public const MISSING_DISABLE = 'disable';
    public const MISSING_KEEP = 'keep';

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    public ?int $id = null;
    public ?int $connectionId = null;
    public string $name = '';
    public string $handle = '';

    /** The calendar at the provider: its ID there (Google, Microsoft) or its address (CalDAV). */
    public string $remoteId = '';
    public ?string $remoteName = null;
    public ?string $color = null;
    public ?string $timezone = null;

    public bool $enabled = true;
    public string $direction = self::DIRECTION_IMPORT;

    /** The period that is synced, in days before and after today. */
    public int $pastDays = 30;
    public int $futureDays = 365;

    /** Minutes between two runs; 0 = only by hand or by the console command. */
    public int $syncInterval = 60;

    /** The entries the events are mapped to. No section = the events are only kept by the plugin. */
    public ?int $siteId = null;
    public ?int $sectionId = null;
    public ?int $entryTypeId = null;
    public ?int $authorId = null;

    /** @var array<string, string> Event attribute => `title` or the handle of a field of the entry type */
    public array $mapping = [];

    /** Who wins if an event and its entry both changed since the last run (only when syncing both ways). */
    public string $conflictWinner = self::WINNER_REMOTE;

    /** What happens to an entry when its event is no longer in the calendar. */
    public string $missingBehavior = self::MISSING_DELETE;

    public bool $feedEnabled = false;
    public ?string $feedToken = null;

    /** Put in front of the title of every entry made from an event, e.g. «Team:». */
    public ?string $entryPrefix = null;

    /**
     * Prefixes this calendar used before. Entries may still carry one; it must not travel to the calendar.
     *
     * @var string[]
     */
    public array $retiredPrefixes = [];

    /**
     * Sets another prefix and remembers the one that was used so far.
     */
    public function changePrefix(?string $prefix): void
    {
        $old = trim((string)$this->entryPrefix);
        $new = trim((string)$prefix);
        if ($old !== '' && $old !== $new && !in_array($old, $this->retiredPrefixes, true)) {
            $this->retiredPrefixes[] = $old;
        }
        $this->retiredPrefixes = array_values(array_diff($this->retiredPrefixes, [$new]));
        $this->entryPrefix = $new !== '' ? $new : null;
    }

    /** Leave out events that are marked as private or confidential in the calendar. */
    public bool $skipPrivate = false;

    /** Words, separated by commas or lines: only events with one of them come to Craft. Empty = all. */
    public ?string $filterInclude = null;

    /** Words, separated by commas or lines: events with one of them stay out. */
    public ?string $filterExclude = null;

    /** How the description is shown: keep its line breaks, list attached documents, turn addresses into links. */
    public bool $descriptionLineBreaks = true;
    public bool $descriptionDocuments = true;
    public bool $descriptionLinks = true;

    public ?DateTime $lastSyncedAt = null;
    public ?string $lastSyncStatus = null;
    public ?string $lastSyncMessage = null;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getConnection(): ?Connection
    {
        return $this->connectionId ? CalendarSync::$plugin->connections->getConnectionById($this->connectionId) : null;
    }

    public function getSection(): ?Section
    {
        return $this->sectionId ? Craft::$app->getEntries()->getSectionById($this->sectionId) : null;
    }

    public function getEntryType(): ?EntryType
    {
        return $this->entryTypeId ? Craft::$app->getEntries()->getEntryTypeById($this->entryTypeId) : null;
    }

    public function hasFilters(): bool
    {
        return $this->skipPrivate || self::words($this->filterInclude) || self::words($this->filterExclude);
    }

    /**
     * Whether an event of the calendar passes the filters. The words are looked for in the title, the description,
     * the location and the categories; upper and lower case make no difference.
     */
    public function accepts(EventData $data): bool
    {
        if ($this->skipPrivate && $data->private) {
            return false;
        }
        $text = mb_strtolower(implode("\n", array_merge(
            [$data->title, (string)$data->description, (string)$data->location],
            $data->categories,
        )));
        foreach (self::words($this->filterExclude) as $word) {
            if (str_contains($text, $word)) {
                return false;
            }
        }
        $include = self::words($this->filterInclude);
        if (!$include) {
            return true;
        }
        foreach ($include as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function words(?string $list): array
    {
        $words = preg_split('~[,\r\n]+~', mb_strtolower((string)$list)) ?: [];

        return array_values(array_filter(array_map('trim', $words), static fn(string $word) => $word !== ''));
    }

    /** Events of the calendar come to Craft. */
    public function imports(): bool
    {
        return $this->direction !== self::DIRECTION_EXPORT;
    }

    /** Entries of the section go to the calendar. */
    public function exports(): bool
    {
        return $this->direction !== self::DIRECTION_IMPORT;
    }

    /** Events are mapped to entries, not only kept by the plugin. */
    public function hasEntries(): bool
    {
        return $this->sectionId !== null && $this->entryTypeId !== null;
    }

    public function getSiteId(): int
    {
        return $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * The synced period: from the start of the first day to the end of the last, in UTC.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public function window(?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0, 0);

        return [
            $today->modify(sprintf('-%d days', $this->pastDays)),
            $today->modify(sprintf('+%d days', $this->futureDays + 1)),
        ];
    }

    /** Whether the calendar is due, by its interval. */
    public function isDue(?DateTime $now = null): bool
    {
        if (!$this->enabled || $this->syncInterval <= 0) {
            return false;
        }
        if ($this->lastSyncedAt === null) {
            return true;
        }
        $now ??= new DateTime();

        return $this->lastSyncedAt->getTimestamp() + $this->syncInterval * 60 <= $now->getTimestamp();
    }

    /**
     * What the last run did, in the language of whoever reads it.
     */
    public function getLastSyncSummary(): ?string
    {
        return SyncResult::fromJson($this->lastSyncMessage)?->summary() ?? $this->lastSyncMessage;
    }

    public function getNextSync(): ?DateTime
    {
        if (!$this->enabled || $this->syncInterval <= 0) {
            return null;
        }
        if ($this->lastSyncedAt === null) {
            return new DateTime();
        }

        return (clone $this->lastSyncedAt)->modify(sprintf('+%d minutes', $this->syncInterval));
    }

    /**
     * The address of the public feed, or null while the feed is switched off.
     *
     * @param string $format `ics` (to subscribe to) or `json` (for a calendar script)
     */
    public function getFeedUrl(string $format = 'ics'): ?string
    {
        if (!$this->feedEnabled || !$this->feedToken) {
            return null;
        }

        return UrlHelper::siteUrl(sprintf('calendar-sync/feed/%s.%s', $this->feedToken, $format === 'json' ? 'json' : 'ics'));
    }

    public function getCpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('calendar-sync/calendars/' . $this->id) : null;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'name' => CalendarSync::t('Name'),
            'handle' => CalendarSync::t('Handle'),
            'connectionId' => CalendarSync::t('Connection'),
            'remoteId' => CalendarSync::t('Calendar'),
            'sectionId' => CalendarSync::t('Section'),
            'entryTypeId' => CalendarSync::t('Entry type'),
            'mapping' => CalendarSync::t('Field mapping'),
            'pastDays' => CalendarSync::t('Days back'),
            'futureDays' => CalendarSync::t('Days ahead'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'remoteId'], 'trim'],
            [['name', 'handle', 'connectionId', 'remoteId'], 'required'],
            [['name', 'remoteName'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'title', 'all']],
            [['handle'], 'string', 'max' => 64],
            [['handle'], function(string $attribute) {
                $other = CalendarSync::$plugin->calendars->getCalendarByHandle($this->handle);
                if ($other !== null && $other->id !== $this->id) {
                    $this->addError($attribute, CalendarSync::t('Another calendar already uses this handle.'));
                }
            }],
            [['connectionId'], function(string $attribute) {
                if ($this->getConnection() === null) {
                    $this->addError($attribute, CalendarSync::t('This connection does not exist any more.'));
                }
            }],
            [['direction'], 'in', 'range' => [self::DIRECTION_IMPORT, self::DIRECTION_EXPORT, self::DIRECTION_BOTH]],
            [['direction'], function(string $attribute) {
                if ($this->exports() && $this->getConnection()?->canWrite() === false) {
                    $this->addError($attribute, CalendarSync::t('This connection only reads. Choose «Calendar → Craft».'));
                }
            }],
            [['conflictWinner'], 'in', 'range' => [self::WINNER_REMOTE, self::WINNER_LOCAL]],
            [['missingBehavior'], 'in', 'range' => [self::MISSING_DELETE, self::MISSING_DISABLE, self::MISSING_KEEP]],
            [['syncInterval'], 'in', 'range' => array_keys(Settings::intervals())],
            [['pastDays', 'futureDays'], 'integer', 'min' => 0, 'max' => 3650],
            [['enabled', 'feedEnabled', 'descriptionLineBreaks', 'descriptionDocuments', 'descriptionLinks', 'skipPrivate'], 'boolean'],
            [['filterInclude', 'filterExclude'], 'trim'],
            [['filterInclude', 'filterExclude'], 'string', 'max' => 1000],
            [['entryPrefix'], 'trim'],
            [['entryPrefix'], 'string', 'max' => 100],
            [['color'], 'match', 'pattern' => '/^#[0-9a-f]{6}$/i', 'message' => CalendarSync::t('Enter a colour like #2d6cdf.')],
            [['sectionId'], function(string $attribute) {
                if ($this->exports() && !$this->hasEntries()) {
                    $this->addError($attribute, CalendarSync::t('Choose the section whose entries go to the calendar.'));
                }
                if ($this->sectionId !== null && $this->getSection() === null) {
                    $this->addError($attribute, CalendarSync::t('This section does not exist any more.'));
                }
            }, 'skipOnEmpty' => false],
            [['entryTypeId'], function(string $attribute) {
                $section = $this->getSection();
                if ($section === null) {
                    return;
                }
                $ids = array_map(static fn(EntryType $type) => $type->id, $section->getEntryTypes());
                if (!in_array($this->entryTypeId, $ids, true)) {
                    $this->addError($attribute, CalendarSync::t('Choose an entry type of this section.'));
                }
            }, 'skipOnEmpty' => false],
            [['mapping'], function(string $attribute) {
                if (!$this->hasEntries()) {
                    return;
                }
                if (empty($this->mapping['start'])) {
                    $this->addError($attribute, CalendarSync::t('Choose the date field that holds the start of an event.'));
                }
                $used = array_filter($this->mapping);
                if (count($used) !== count(array_unique($used))) {
                    $this->addError($attribute, CalendarSync::t('A field can only be mapped once.'));
                }
            }, 'skipOnEmpty' => false],
        ];
    }
}
