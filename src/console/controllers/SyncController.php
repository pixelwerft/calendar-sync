<?php

namespace pixelwerft\calendarsync\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use pixelwerft\calendarsync\CalendarSync;
use yii\console\ExitCode;

/**
 * Syncs calendars from the command line – for a cron job.
 */
class SyncController extends Controller
{
    /** @var bool Run every enabled calendar, not only those that are due by their interval. */
    public bool $all = false;

    /** @var bool Write every entry again, also for events that did not change. */
    public bool $force = false;

    /** @var bool Put the runs on the queue instead of running them here. */
    public bool $queue = false;

    public $defaultAction = 'index';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['all', 'force', 'queue']);
    }

    /**
     * Syncs the calendars that are due, or the one with the given handle.
     *
     * @param string|null $handle The handle of one calendar
     */
    public function actionIndex(?string $handle = null): int
    {
        $plugin = CalendarSync::$plugin;

        if ($handle !== null) {
            $calendar = $plugin->calendars->getCalendarByHandle($handle);
            if ($calendar === null) {
                $this->stderr("There is no calendar «{$handle}»." . PHP_EOL, Console::FG_RED);
                return ExitCode::USAGE;
            }
            $calendars = [$calendar];
        } elseif ($this->all) {
            $calendars = array_filter($plugin->calendars->getAllCalendars(), static fn($calendar) => $calendar->enabled);
        } else {
            $calendars = $plugin->calendars->getDueCalendars();
        }

        if (!$calendars) {
            $this->stdout('No calendar is due.' . PHP_EOL);
            return ExitCode::OK;
        }

        $failed = false;
        foreach ($calendars as $calendar) {
            if ($this->queue) {
                $plugin->sync->queue($calendar, $this->force, true);
                $this->stdout("{$calendar->handle}: queued" . PHP_EOL);
                continue;
            }
            $this->stdout("{$calendar->handle}: ", Console::BOLD);
            $result = $plugin->sync->syncCalendar($calendar, $this->force);
            $this->stdout($result->summary() . PHP_EOL, $result->hasErrors() ? Console::FG_RED : Console::FG_GREEN);
            $failed = $failed || $result->hasErrors();
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Lists the calendars with their state.
     */
    public function actionStatus(): int
    {
        foreach (CalendarSync::$plugin->calendars->getAllCalendars() as $calendar) {
            $this->stdout(sprintf(
                "%-24s %-8s %-8s %s\n",
                $calendar->handle,
                $calendar->enabled ? $calendar->direction : 'off',
                $calendar->lastSyncStatus ?? '–',
                $calendar->lastSyncedAt?->format('Y-m-d H:i') ?? 'never',
            ));
        }

        return ExitCode::OK;
    }
}
