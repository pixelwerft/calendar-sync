<?php

namespace pixelwerft\calendarsync\db;

use ArrayIterator;
use Countable;
use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use IteratorAggregate;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\models\EventData;
use Traversable;

/**
 * Finds synced events, for templates and PHP:
 *
 * ```twig
 * {% for event in craft.calendarSync.events().calendar('team').upcoming().limit(5) %}
 * ```
 *
 * Cancelled events are left out unless `status()` asks for them.
 *
 * @implements IteratorAggregate<int, Event>
 */
class EventQuery implements IteratorAggregate, Countable
{
    /** @var int[]|null */
    private ?array $calendarIds = null;
    private ?DateTime $after = null;
    private ?DateTime $before = null;
    private ?DateTime $endedBefore = null;
    private ?string $search = null;

    /** @var string[]|null */
    private ?array $status = [EventData::STATUS_CONFIRMED, EventData::STATUS_TENTATIVE];
    private ?bool $allDay = null;
    private ?bool $recurring = null;
    private ?string $origin = null;
    private ?bool $hasEntry = null;

    /** @var int[]|null */
    private ?array $entryIds = null;

    /** @var int[]|null */
    private ?array $ids = null;

    /** @var array<string, int> */
    private array $orderBy = ['startDate' => SORT_ASC, 'id' => SORT_ASC];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $withEntries = false;

    /**
     * Sets several criteria at once: `{calendar: 'team', upcoming: true, limit: 5}`.
     *
     * @param array<string, mixed> $criteria
     */
    public function configure(array $criteria): static
    {
        foreach ($criteria as $name => $value) {
            if (!method_exists($this, $name) || in_array($name, ['all', 'one', 'count', 'exists', 'byDay', 'configure', 'getIterator'], true)) {
                throw new \InvalidArgumentException("Unknown event criterion: $name");
            }
            if (in_array($name, ['upcoming', 'past', 'today'], true)) {
                if ($value) {
                    $this->$name();
                }
                continue;
            }
            $this->$name($value);
        }

        return $this;
    }

    /**
     * Events of these calendars: a handle, an ID, a calendar, or a list of them.
     */
    public function calendar(mixed $value): static
    {
        $this->calendarIds = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            $calendar = match (true) {
                $item instanceof Calendar => $item,
                is_numeric($item) => CalendarSync::$plugin->calendars->getCalendarById((int)$item),
                is_string($item) => CalendarSync::$plugin->calendars->getCalendarByHandle($item),
                default => null,
            };
            if ($calendar?->id) {
                $this->calendarIds[] = $calendar->id;
            }
        }

        return $this;
    }

    /**
     * Events that are not over at this moment: running ones and later ones.
     */
    public function after(mixed $date): static
    {
        $this->after = self::date($date);

        return $this;
    }

    /**
     * Events that start before this moment.
     */
    public function before(mixed $date): static
    {
        $this->before = self::date($date);

        return $this;
    }

    /**
     * Events that touch the period between the two moments.
     */
    public function range(mixed $from, mixed $to): static
    {
        return $this->after($from)->before($to);
    }

    /**
     * Running and coming events, the next one first.
     */
    public function upcoming(): static
    {
        return $this->after(new DateTime());
    }

    /**
     * Events that are over, the latest first.
     */
    public function past(): static
    {
        $this->endedBefore = new DateTime();
        $this->after = null;
        $this->orderBy = ['endDate' => SORT_DESC, 'id' => SORT_DESC];

        return $this;
    }

    /**
     * Events of one day, in the time zone of the site.
     */
    public function day(mixed $date): static
    {
        $day = (self::date($date) ?? new DateTime())->setTimezone(Event::displayTimeZone())->setTime(0, 0);

        return $this->range($day, (clone $day)->modify('+1 day'));
    }

    public function today(): static
    {
        return $this->day(new DateTime());
    }

    /**
     * Events of the month a date lies in – what a month view needs.
     */
    public function month(mixed $date): static
    {
        $first = (self::date($date) ?? new DateTime())->setTimezone(Event::displayTimeZone())->modify('first day of this month')->setTime(0, 0);

        return $this->range($first, (clone $first)->modify('+1 month'));
    }

    /**
     * Events with this text in title, description or location.
     */
    public function search(?string $term): static
    {
        $term = trim((string)$term);
        $this->search = $term !== '' ? $term : null;

        return $this;
    }

    /**
     * `confirmed`, `tentative`, `cancelled`, a list of them, or null for any.
     *
     * @param string|string[]|null $status
     */
    public function status(string|array|null $status): static
    {
        $this->status = $status === null ? null : (array)$status;

        return $this;
    }

    public function allDay(?bool $value = true): static
    {
        $this->allDay = $value;

        return $this;
    }

    /**
     * Only occurrences of repeating events (true) or only single events (false).
     */
    public function recurring(?bool $value = true): static
    {
        $this->recurring = $value;

        return $this;
    }

    /**
     * `remote` for events made in the calendar, `craft` for events made from entries.
     */
    public function origin(?string $value): static
    {
        $this->origin = $value;

        return $this;
    }

    public function hasEntry(?bool $value = true): static
    {
        $this->hasEntry = $value;

        return $this;
    }

    /**
     * The events of these entries.
     *
     * @param int|int[]|null $value
     */
    public function entryId(int|array|null $value): static
    {
        $this->entryIds = $value === null ? null : array_map('intval', (array)$value);

        return $this;
    }

    /**
     * @param int|int[]|null $value
     */
    public function id(int|array|null $value): static
    {
        $this->ids = $value === null ? null : array_map('intval', (array)$value);

        return $this;
    }

    /**
     * `startDate`, `endDate`, `title`, `dateCreated` or `dateUpdated`, with `asc` or `desc`: `'startDate desc'`.
     */
    public function orderBy(string $value): static
    {
        $orderBy = [];
        foreach (explode(',', $value) as $part) {
            [$column, $direction] = array_pad(preg_split('~\s+~', trim($part)) ?: [], 2, 'asc');
            if (in_array($column, ['startDate', 'endDate', 'title', 'dateCreated', 'dateUpdated'], true)) {
                $orderBy[$column] = strtolower((string)$direction) === 'desc' ? SORT_DESC : SORT_ASC;
            }
        }
        if ($orderBy) {
            $this->orderBy = $orderBy + ['id' => SORT_ASC];
        }

        return $this;
    }

    /**
     * Loads the entries of the events along, for lists that show `event.entry`.
     */
    public function withEntries(bool $value = true): static
    {
        $this->withEntries = $value;

        return $this;
    }

    public function limit(?int $value): static
    {
        $this->limit = $value;

        return $this;
    }

    public function offset(?int $value): static
    {
        $this->offset = $value;

        return $this;
    }

    /**
     * @return Event[]
     */
    public function all(): array
    {
        $service = CalendarSync::$plugin->events;
        $events = array_map(static fn(array $row) => $service->populate($row), $this->query()->all());
        if ($this->withEntries) {
            $service->eagerLoadEntries($events);
        }

        return $events;
    }

    public function one(): ?Event
    {
        $row = $this->query()->limit(1)->one();

        return $row ? CalendarSync::$plugin->events->populate($row) : null;
    }

    public function count(): int
    {
        return (int)$this->query()->limit(null)->offset(null)->orderBy([])->count();
    }

    public function exists(): bool
    {
        return $this->query()->exists();
    }

    /**
     * The events by the day they start on (`Y-m-d`), for lists with a heading per day.
     *
     * @return array<string, Event[]>
     */
    public function byDay(): array
    {
        $days = [];
        foreach ($this->all() as $event) {
            $days[$event->startDate->format('Y-m-d')][] = $event;
        }

        return $days;
    }

    /**
     * @return Traversable<int, Event>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    private function query(): Query
    {
        $query = (new Query())->from(Table::EVENTS)->orderBy($this->orderBy);

        if ($this->calendarIds !== null) {
            // an unknown handle finds nothing rather than everything
            $query->andWhere(['calendarId' => $this->calendarIds ?: [0]]);
        }
        if ($this->ids !== null) {
            $query->andWhere(['id' => $this->ids ?: [0]]);
        }
        if ($this->entryIds !== null) {
            $query->andWhere(['entryId' => $this->entryIds ?: [0]]);
        }
        if ($this->after !== null) {
            [$moment, $day] = self::bounds($this->after);
            // a point in time (start = end) counts as not over at its own moment
            $query->andWhere([
                'or',
                ['and', ['allDay' => false], ['or', ['>', 'endDate', $moment], ['>=', 'startDate', $moment]]],
                ['and', ['allDay' => true], ['>', 'endDate', $day]],
            ]);
        }
        if ($this->before !== null) {
            [$moment, $day] = self::bounds($this->before);
            $query->andWhere([
                'or',
                ['and', ['allDay' => false], ['<', 'startDate', $moment]],
                ['and', ['allDay' => true], ['<', 'startDate', $day]],
            ]);
        }
        if ($this->endedBefore !== null) {
            [$moment, $day] = self::bounds($this->endedBefore);
            $query->andWhere([
                'or',
                ['and', ['allDay' => false], ['<=', 'endDate', $moment]],
                ['and', ['allDay' => true], ['<=', 'endDate', $day]],
            ]);
        }
        if ($this->search !== null) {
            // PostgreSQL tells upper from lower case unless asked not to
            $like = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
            $query->andWhere(['or', [$like, 'title', $this->search], [$like, 'description', $this->search], [$like, 'location', $this->search]]);
        }
        if ($this->status !== null) {
            $query->andWhere(['status' => $this->status]);
        }
        if ($this->allDay !== null) {
            $query->andWhere(['allDay' => $this->allDay]);
        }
        if ($this->recurring !== null) {
            $query->andWhere(['recurring' => $this->recurring]);
        }
        if ($this->origin !== null) {
            $query->andWhere(['origin' => $this->origin]);
        }
        if ($this->hasEntry !== null) {
            $query->andWhere($this->hasEntry ? ['not', ['entryId' => null]] : ['entryId' => null]);
        }
        if ($this->limit !== null) {
            $query->limit($this->limit);
        }
        if ($this->offset !== null) {
            $query->offset($this->offset);
        }

        return $query;
    }

    /**
     * A moment the two ways events are stored: as UTC for events with a time, and as the wall clock of the site
     * for all-day events, which are days and not moments.
     *
     * @return array{0: string, 1: string}
     */
    private static function bounds(DateTime $date): array
    {
        return [
            (clone $date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            (clone $date)->setTimezone(Event::displayTimeZone())->format('Y-m-d H:i:s'),
        ];
    }

    private static function date(mixed $value): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            return DateTime::createFromInterface($value);
        }

        return DateTimeHelper::toDateTime($value, true) ?: null;
    }
}
