<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use pixelwerft\calendarsync\db\Table;
use pixelwerft\calendarsync\models\Calendar;
use yii\base\Component;

/**
 * The synced calendars and their settings.
 */
class Calendars extends Component
{
    /** @var array<int, Calendar>|null */
    private ?array $calendars = null;

    /**
     * @return Calendar[]
     */
    public function getAllCalendars(): array
    {
        if ($this->calendars === null) {
            $this->calendars = [];
            $rows = (new Query())->from(Table::CALENDARS)->orderBy(['name' => SORT_ASC])->all();
            foreach ($rows as $row) {
                $this->calendars[(int)$row['id']] = $this->populate($row);
            }
        }

        return array_values($this->calendars);
    }

    public function getCalendarById(int $id): ?Calendar
    {
        $this->getAllCalendars();

        return $this->calendars[$id] ?? null;
    }

    public function getCalendarByHandle(string $handle): ?Calendar
    {
        foreach ($this->getAllCalendars() as $calendar) {
            if ($calendar->handle === $handle) {
                return $calendar;
            }
        }

        return null;
    }

    public function getCalendarByFeedToken(string $token): ?Calendar
    {
        foreach ($this->getAllCalendars() as $calendar) {
            if ($calendar->feedToken !== null && hash_equals($calendar->feedToken, $token)) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * @return Calendar[]
     */
    public function getCalendarsByConnectionId(int $connectionId): array
    {
        return array_values(array_filter($this->getAllCalendars(), static fn(Calendar $c) => $c->connectionId === $connectionId));
    }

    /**
     * The calendars that are due by their interval.
     *
     * @return Calendar[]
     */
    public function getDueCalendars(?DateTime $now = null): array
    {
        return array_values(array_filter($this->getAllCalendars(), static fn(Calendar $c) => $c->isDue($now)));
    }

    /**
     * The calendars the entries of a section go to.
     *
     * @return Calendar[]
     */
    public function getExportCalendarsBySectionId(int $sectionId): array
    {
        return array_values(array_filter(
            $this->getAllCalendars(),
            static fn(Calendar $c) => $c->enabled && $c->exports() && $c->sectionId === $sectionId && $c->entryTypeId !== null,
        ));
    }

    /**
     * Forgets what was read: the calendars are read again when they are needed next.
     */
    public function forget(): void
    {
        $this->calendars = null;
    }

    public function saveCalendar(Calendar $calendar, bool $runValidation = true): bool
    {
        if ($runValidation && !$calendar->validate()) {
            return false;
        }

        // the token is kept while the feed is off, so switching it on again gives the same address
        $calendar->feedToken ??= Craft::$app->getSecurity()->generateRandomString(32);

        $values = [
            'connectionId' => $calendar->connectionId,
            'name' => $calendar->name,
            'handle' => $calendar->handle,
            'remoteId' => $calendar->remoteId,
            'remoteName' => $calendar->remoteName,
            'color' => $calendar->color,
            'timezone' => $calendar->timezone,
            'enabled' => $calendar->enabled,
            'direction' => $calendar->direction,
            'pastDays' => $calendar->pastDays,
            'futureDays' => $calendar->futureDays,
            'syncInterval' => $calendar->syncInterval,
            'siteId' => $calendar->siteId,
            'sectionId' => $calendar->sectionId,
            'entryTypeId' => $calendar->entryTypeId,
            'authorId' => $calendar->authorId,
            'mapping' => Json::encode((object)$calendar->mapping),
            'conflictWinner' => $calendar->conflictWinner,
            'missingBehavior' => $calendar->missingBehavior,
            'feedEnabled' => $calendar->feedEnabled,
            'feedToken' => $calendar->feedToken,
            'options' => Json::encode([
                'entryPrefix' => $calendar->entryPrefix,
                'retiredPrefixes' => $calendar->retiredPrefixes,
                'descriptionLineBreaks' => $calendar->descriptionLineBreaks,
                'descriptionDocuments' => $calendar->descriptionDocuments,
                'descriptionLinks' => $calendar->descriptionLinks,
                'skipPrivate' => $calendar->skipPrivate,
                'filterInclude' => $calendar->filterInclude,
                'filterExclude' => $calendar->filterExclude,
            ]),
        ];

        if ($calendar->id) {
            Db::update(Table::CALENDARS, $values, ['id' => $calendar->id]);
        } else {
            $calendar->uid ??= StringHelper::UUID();
            Db::insert(Table::CALENDARS, $values + ['uid' => $calendar->uid]);
            $calendar->id = (int)Craft::$app->getDb()->getLastInsertID(Table::CALENDARS);
        }
        $this->calendars = null;

        return true;
    }

    /**
     * Notes how the last run ended.
     */
    public function markSynced(Calendar $calendar, string $status, ?string $message): void
    {
        $now = new DateTime();
        Db::update(Table::CALENDARS, [
            'lastSyncedAt' => Db::prepareDateForDb($now),
            'lastSyncStatus' => $status,
            'lastSyncMessage' => $message,
        ], ['id' => $calendar->id], updateTimestamp: false);
        $calendar->lastSyncedAt = $now;
        $calendar->lastSyncStatus = $status;
        $calendar->lastSyncMessage = $message;
        $this->calendars = null;
    }

    /**
     * Removes the calendar and its events from Craft. Entries stay, and so does everything at the provider.
     */
    public function deleteCalendarById(int $id): bool
    {
        $deleted = Db::delete(Table::CALENDARS, ['id' => $id]);
        $this->calendars = null;

        return $deleted > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function populate(array $row): Calendar
    {
        $mapping = Json::decodeIfJson($row['mapping'] ?: '{}');
        $options = Json::decodeIfJson(($row['options'] ?? null) ?: '{}');
        $options = is_array($options) ? $options : [];

        return new Calendar([
            'id' => (int)$row['id'],
            'connectionId' => (int)$row['connectionId'],
            'name' => (string)$row['name'],
            'handle' => (string)$row['handle'],
            'remoteId' => (string)$row['remoteId'],
            'remoteName' => $row['remoteName'],
            'color' => $row['color'],
            'timezone' => $row['timezone'],
            'enabled' => (bool)$row['enabled'],
            'direction' => (string)$row['direction'],
            'pastDays' => (int)$row['pastDays'],
            'futureDays' => (int)$row['futureDays'],
            'syncInterval' => (int)$row['syncInterval'],
            'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
            'sectionId' => $row['sectionId'] !== null ? (int)$row['sectionId'] : null,
            'entryTypeId' => $row['entryTypeId'] !== null ? (int)$row['entryTypeId'] : null,
            'authorId' => $row['authorId'] !== null ? (int)$row['authorId'] : null,
            'mapping' => is_array($mapping) ? array_filter($mapping) : [],
            'conflictWinner' => (string)$row['conflictWinner'],
            'missingBehavior' => (string)$row['missingBehavior'],
            'feedEnabled' => (bool)$row['feedEnabled'],
            'feedToken' => $row['feedToken'],
            'entryPrefix' => isset($options['entryPrefix']) && $options['entryPrefix'] !== '' ? (string)$options['entryPrefix'] : null,
            'retiredPrefixes' => array_values(array_filter(array_map('strval', (array)($options['retiredPrefixes'] ?? [])))),
            'descriptionLineBreaks' => (bool)($options['descriptionLineBreaks'] ?? true),
            'descriptionDocuments' => (bool)($options['descriptionDocuments'] ?? true),
            'descriptionLinks' => (bool)($options['descriptionLinks'] ?? true),
            'skipPrivate' => (bool)($options['skipPrivate'] ?? false),
            'filterInclude' => isset($options['filterInclude']) && $options['filterInclude'] !== '' ? (string)$options['filterInclude'] : null,
            'filterExclude' => isset($options['filterExclude']) && $options['filterExclude'] !== '' ? (string)$options['filterExclude'] : null,
            'lastSyncedAt' => DateTimeHelper::toDateTime($row['lastSyncedAt']) ?: null,
            'lastSyncStatus' => $row['lastSyncStatus'],
            'lastSyncMessage' => $row['lastSyncMessage'],
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated']) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']) ?: null,
            'uid' => $row['uid'],
        ]);
    }
}
