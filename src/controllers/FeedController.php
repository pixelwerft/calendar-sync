<?php

namespace pixelwerft\calendarsync\controllers;

use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\helpers\Ics;
use pixelwerft\calendarsync\models\Event;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The public feed of a calendar: iCalendar to subscribe to in a calendar app, JSON for a calendar script.
 *
 * A feed only exists while it is switched on for the calendar, and only under the address with its token.
 */
class FeedController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index', 'event'];

    /**
     * One event as a calendar file, for «add to calendar». The address carries a signature the site made, so it
     * only works for events a template has linked to.
     */
    public function actionEvent(string $uid, string $signature): Response
    {
        if (!hash_equals(Event::signature($uid), $signature)) {
            throw new NotFoundHttpException();
        }
        $event = CalendarSync::$plugin->events->getEventByUid($uid);
        if ($event === null || $event->getIsCancelled()) {
            throw new NotFoundHttpException();
        }

        $host = (string)parse_url((string)$this->request->getHostInfo(), PHP_URL_HOST) ?: 'calendar-sync';
        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()
            ->set('Content-Type', 'text/calendar; charset=utf-8')
            ->set('Content-Disposition', 'attachment; filename="event.ics"')
            ->set('X-Robots-Tag', 'noindex');
        $this->response->data = Ics::event([
            'uid' => $event->uid . '@' . $host,
            'event' => $event->toEventData(),
            'url' => $event->getEntry()?->getUrl() ?? $event->url,
            'modified' => $event->dateUpdated,
        ]);

        return $this->response;
    }

    public function actionIndex(string $token, string $format = 'ics'): Response
    {
        $plugin = CalendarSync::$plugin;
        $calendar = $plugin->calendars->getCalendarByFeedToken($token);
        if ($calendar === null || !$calendar->feedEnabled) {
            throw new NotFoundHttpException();
        }

        $query = $plugin->events->find()->calendar($calendar)->withEntries();
        // calendar scripts ask for the period they show
        $start = $this->request->getQueryParam('start');
        $end = $this->request->getQueryParam('end');
        if ($format === 'json' && is_string($start) && is_string($end) && $start !== '' && $end !== '') {
            $query->range($start, $end);
        }
        $events = $query->limit(5000)->all();

        $this->response->getHeaders()
            ->set('Cache-Control', 'public, max-age=300')
            ->set('X-Robots-Tag', 'noindex');

        if ($format === 'json') {
            return $this->asJson(array_map(static fn(Event $event) => $event->toFeedArray(), $events));
        }

        $host = (string)parse_url((string)$this->request->getHostInfo(), PHP_URL_HOST) ?: 'calendar-sync';
        $items = array_map(static fn(Event $event) => [
            // occurrences of one repeating event share its UID at the provider; in a flat feed each needs its own
            'uid' => $event->recurring || !$event->icalUid ? $event->uid . '@' . $host : $event->icalUid,
            'event' => $event->toEventData(),
            'url' => $event->getEntry()?->getUrl() ?? $event->url,
            'modified' => $event->dateUpdated,
        ], $events);

        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()
            ->set('Content-Type', 'text/calendar; charset=utf-8')
            ->set('Content-Disposition', sprintf('inline; filename="%s.ics"', $calendar->handle));
        $this->response->data = Ics::feed($calendar->name, $items, $calendar->color);

        return $this->response;
    }
}
