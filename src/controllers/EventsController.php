<?php

namespace pixelwerft\calendarsync\controllers;

use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use yii\web\Response;

/**
 * The synced events, to look at what a calendar brought.
 */
class EventsController extends Controller
{
    private const PER_PAGE = 50;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-calendar-sync');
        $plugin = CalendarSync::$plugin;

        $handle = (string)$this->request->getQueryParam('calendar');
        $period = (string)$this->request->getQueryParam('period', 'upcoming');
        $search = trim((string)$this->request->getQueryParam('q'));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));

        $query = $plugin->events->find()->status(null)->search($search)->withEntries();
        if ($handle !== '') {
            $query->calendar($handle);
        }
        match ($period) {
            'past' => $query->past(),
            'all' => $query->orderBy('startDate desc'),
            default => $query->upcoming(),
        };

        $total = $query->count();
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return $this->renderTemplate('calendar-sync/events/index', [
            'events' => $query->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)->all(),
            'calendars' => $plugin->calendars->getAllCalendars(),
            'selectedCalendar' => $handle,
            'period' => in_array($period, ['past', 'all'], true) ? $period : 'upcoming',
            'search' => $search,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
