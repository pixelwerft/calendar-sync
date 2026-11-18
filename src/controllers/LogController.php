<?php

namespace pixelwerft\calendarsync\controllers;

use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Calendar;
use yii\web\Response;

/**
 * The log: what the runs of the calendars did.
 */
class LogController extends Controller
{
    private const PER_PAGE = 50;

    public function actionIndex(): Response
    {
        $this->requirePermission('accessPlugin-calendar-sync');
        $plugin = CalendarSync::$plugin;

        $handle = (string)$this->request->getQueryParam('calendar');
        $status = (string)$this->request->getQueryParam('status');
        $page = max(1, (int)$this->request->getQueryParam('page', 1));
        $calendar = $handle !== '' ? $plugin->calendars->getCalendarByHandle($handle) : null;
        $criteria = [
            'calendarId' => $calendar?->id,
            'status' => in_array($status, [Calendar::STATUS_OK, Calendar::STATUS_ERROR], true) ? $status : null,
        ];
        $total = $plugin->runs->count($criteria);

        return $this->renderTemplate('calendar-sync/log/index', [
            'runs' => $plugin->runs->find($criteria, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'calendars' => $plugin->calendars->getAllCalendars(),
            'calendar' => $calendar,
            'status' => $criteria['status'],
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / self::PER_PAGE)),
            'logDays' => $plugin->getSettings()->logDays,
        ]);
    }
}
