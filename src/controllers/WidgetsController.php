<?php

namespace pixelwerft\calendarsync\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use DateTimeImmutable;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Event;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * What the dashboard widgets load.
 */
class WidgetsController extends Controller
{
    /** No widget shows more than six weeks; more is a mistake. */
    private const MAX_DAYS = 62;

    /**
     * The events that touch the days from `from` up to, not including, `to` – as days and ready-made times, in
     * the time zone of the site, so the widget has nothing to work out.
     */
    public function actionEvents(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('accessPlugin-calendar-sync');

        $zone = Event::displayTimeZone();
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$this->request->getQueryParam('from'), $zone);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$this->request->getQueryParam('to'), $zone);
        if (!$from || !$to || $to <= $from || $from->diff($to)->days > self::MAX_DAYS) {
            throw new BadRequestHttpException('Invalid period');
        }

        $query = CalendarSync::$plugin->events->find()->range($from, $to)->withEntries()->limit(1000);
        $handle = (string)$this->request->getQueryParam('calendar');
        if ($handle !== '') {
            $calendar = CalendarSync::$plugin->calendars->getCalendarByHandle($handle);
            if ($calendar === null) {
                return $this->asJson(['events' => []]);
            }
            $query->calendar($calendar);
        }

        $formatter = Craft::$app->getFormatter();
        $events = [];
        foreach ($query->all() as $event) {
            $last = $event->endDate;
            if (!$event->allDay && $last > $event->startDate && $last->format('H:i:s') === '00:00:00') {
                // ends at midnight: the last day is the one before
                $last = (clone $last)->modify('-1 second');
            }
            $calendar = $event->getCalendar();
            $entry = $event->getEntry();
            $events[] = [
                'id' => $event->id,
                'title' => $event->title,
                'start' => $event->startDate->format('Y-m-d'),
                'end' => $last->format('Y-m-d'),
                'allDay' => $event->allDay,
                'time' => $event->allDay ? null : $this->time($formatter, $event->startDate, $event->endDate),
                'sort' => $event->allDay ? '' : $event->startDate->format('H:i'),
                'location' => $event->location,
                'calendar' => $calendar?->name,
                'color' => $calendar?->color,
                'tentative' => $event->status === 'tentative',
                'url' => $entry?->getCpEditUrl(),
            ];
        }

        return $this->asJson(['events' => $events]);
    }

    private function time(\craft\i18n\Formatter $formatter, DateTime $start, DateTime $end): string
    {
        $from = $formatter->asTime($start, 'short');
        $until = $formatter->asTime($end, 'short');

        return $end > $start ? $from . ' – ' . $until : $from;
    }
}
