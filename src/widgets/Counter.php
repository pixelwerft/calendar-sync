<?php

namespace pixelwerft\calendarsync\widgets;

use Craft;
use craft\base\Widget;
use DateTimeImmutable;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\web\assets\widgets\WidgetsAsset;

/**
 * Dashboard widget: how many calendars and events there are, and how the last sync went.
 */
class Counter extends Widget
{
    /** Handle of the calendar to count; empty = all calendars. */
    public ?string $calendar = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return CalendarSync::t('Calendar Sync: numbers');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('accessPlugin-calendar-sync');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        $calendar = $this->calendar();

        return $calendar !== null ? $calendar->name : CalendarSync::t('Calendars and events');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('calendar-sync/widgets/_settings', [
            'widget' => $this,
            'calendars' => CalendarSync::$plugin->calendars->getAllCalendars(),
            'views' => null,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $plugin = CalendarSync::$plugin;
        $calendar = $this->calendar();
        $calendars = $calendar !== null ? [$calendar] : $plugin->calendars->getAllCalendars();
        $query = static fn() => $calendar !== null ? $plugin->events->find()->calendar($calendar) : $plugin->events->find();

        $today = new DateTimeImmutable('today', Event::displayTimeZone());
        $lastSync = null;
        $failed = 0;
        foreach ($calendars as $item) {
            if ($item->lastSyncedAt !== null && ($lastSync === null || $item->lastSyncedAt > $lastSync)) {
                $lastSync = $item->lastSyncedAt;
            }
            if ($item->lastSyncStatus === Calendar::STATUS_ERROR) {
                $failed++;
            }
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(WidgetsAsset::class);

        return $view->renderTemplate('calendar-sync/widgets/counter', [
            'single' => $calendar,
            'calendarCount' => count($calendars),
            'eventCount' => $query()->count(),
            'todayCount' => $query()->today()->count(),
            'weekCount' => $query()->range($today, $today->modify('+7 days'))->count(),
            'upcomingCount' => $query()->upcoming()->count(),
            'lastSync' => $lastSync,
            'failed' => $failed,
        ]);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['calendar'], 'string', 'max' => 64];

        return $rules;
    }

    private function calendar(): ?Calendar
    {
        return $this->calendar ? CalendarSync::$plugin->calendars->getCalendarByHandle($this->calendar) : null;
    }
}
