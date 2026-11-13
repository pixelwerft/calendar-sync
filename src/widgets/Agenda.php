<?php

namespace pixelwerft\calendarsync\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\web\assets\widgets\WidgetsAsset;

/**
 * Dashboard widget: the events of a day, a week or a month.
 */
class Agenda extends Widget
{
    public const VIEWS = ['day', 'week', 'month'];

    /** Handle of the calendar to show; empty = all calendars. */
    public ?string $calendar = null;

    /** The view the widget opens with. */
    public string $view = 'week';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return CalendarSync::t('Calendar Sync: events');
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

        return $calendar !== null ? $calendar->name : CalendarSync::t('Events');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('calendar-sync/widgets/_settings', [
            'widget' => $this,
            'calendars' => CalendarSync::$plugin->calendars->getAllCalendars(),
            'views' => [
                'day' => CalendarSync::t('Day'),
                'week' => CalendarSync::t('Week'),
                'month' => CalendarSync::t('Month'),
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(WidgetsAsset::class);

        $id = 'cs-agenda-' . StringHelper::randomString(8);
        $user = Craft::$app->getUser()->getIdentity();
        $settings = [
            'calendar' => $this->calendar() !== null ? $this->calendar : null,
            'view' => in_array($this->view, self::VIEWS, true) ? $this->view : 'week',
            'today' => (new DateTimeImmutable('today', Event::displayTimeZone()))->format('Y-m-d'),
            'weekStart' => (int)($user?->getPreference('weekStartDay') ?? Craft::$app->getConfig()->getGeneral()->defaultWeekStartDay),
            'locale' => Craft::$app->getFormattingLocale()->id,
            'messages' => [
                'day' => CalendarSync::t('Day'),
                'week' => CalendarSync::t('Week'),
                'month' => CalendarSync::t('Month'),
                'today' => CalendarSync::t('Today'),
                'previous' => CalendarSync::t('Back'),
                'next' => CalendarSync::t('Next'),
                'allDay' => CalendarSync::t('All day'),
                'none' => CalendarSync::t('No events'),
                'failed' => CalendarSync::t('The events could not be loaded.'),
                'view' => CalendarSync::t('View'),
            ],
        ];
        $view->registerJs(sprintf('new CalendarSync.Agenda(%s, %s);', Json::encode('#' . $id), Json::encode($settings)));

        return sprintf('<div class="csw csw-agenda" id="%s"></div>', $id);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['calendar'], 'string', 'max' => 64];
        $rules[] = [['view'], 'in', 'range' => self::VIEWS];

        return $rules;
    }

    private function calendar(): ?Calendar
    {
        return $this->calendar ? CalendarSync::$plugin->calendars->getCalendarByHandle($this->calendar) : null;
    }
}
