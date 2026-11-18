<?php

namespace pixelwerft\calendarsync\services;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\db\Table;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Run;
use pixelwerft\calendarsync\models\SyncResult;
use yii\base\Component;

/**
 * The log: what every run of a calendar did, kept for as many days as the settings say.
 */
class Runs extends Component
{
    /**
     * Notes a run.
     *
     * @param float $started `microtime(true)` at the start of the run
     */
    public function record(Calendar $calendar, SyncResult $result, float $started, string $trigger): void
    {
        if (!$calendar->id || CalendarSync::$plugin->getSettings()->logDays <= 0) {
            return;
        }
        Db::insert(Table::RUNS, [
            'calendarId' => $calendar->id,
            'trigger' => $trigger,
            'status' => $result->hasErrors() ? Calendar::STATUS_ERROR : Calendar::STATUS_OK,
            'dateStarted' => Db::prepareDateForDb((new DateTime())->setTimestamp((int)$started)),
            'duration' => max(0, (int)round((microtime(true) - $started) * 1000)),
            'result' => $result->toJson(),
        ]);
    }

    /**
     * The runs, the latest first.
     *
     * @param array{calendarId?: int|null, status?: string|null} $criteria
     * @return Run[]
     */
    public function find(array $criteria = [], int $limit = 50, int $offset = 0): array
    {
        $rows = $this->query($criteria)
            ->orderBy(['dateStarted' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(fn(array $row) => $this->populate($row), $rows);
    }

    /**
     * @param array{calendarId?: int|null, status?: string|null} $criteria
     */
    public function count(array $criteria = []): int
    {
        return (int)$this->query($criteria)->count();
    }

    public function getLastRun(int $calendarId): ?Run
    {
        return $this->find(['calendarId' => $calendarId], 1)[0] ?? null;
    }

    /**
     * Removes what is older than the settings keep. Returns how many runs went.
     */
    public function prune(): int
    {
        $days = CalendarSync::$plugin->getSettings()->logDays;
        $before = (new DateTime())->modify(sprintf('-%d days', max(0, $days)));

        return Db::delete(Table::RUNS, ['<', 'dateStarted', Db::prepareDateForDb($before)]);
    }

    /**
     * @param array{calendarId?: int|null, status?: string|null} $criteria
     */
    private function query(array $criteria): Query
    {
        $query = (new Query())->from(Table::RUNS);
        if (!empty($criteria['calendarId'])) {
            $query->andWhere(['calendarId' => (int)$criteria['calendarId']]);
        }
        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => (string)$criteria['status']]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function populate(array $row): Run
    {
        return new Run([
            'id' => (int)$row['id'],
            'calendarId' => (int)$row['calendarId'],
            'trigger' => (string)$row['trigger'],
            'status' => (string)$row['status'],
            'dateStarted' => DateTimeHelper::toDateTime($row['dateStarted']) ?: null,
            'duration' => (int)$row['duration'],
            'result' => SyncResult::fromJson($row['result']),
        ]);
    }
}
