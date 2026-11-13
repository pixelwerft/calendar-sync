<?php

namespace pixelwerft\calendarsync\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\EventData;
use pixelwerft\calendarsync\models\Settings;
use pixelwerft\calendarsync\services\Mapper;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The synced calendars: list, set up, sync, remove.
 */
class CalendarsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('accessPlugin-calendar-sync');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = CalendarSync::$plugin;
        $user = Craft::$app->getUser();
        $counts = [];
        foreach ($plugin->calendars->getAllCalendars() as $calendar) {
            $counts[$calendar->id] = $plugin->events->find()->calendar($calendar)->count();
        }

        return $this->renderTemplate('calendar-sync/calendars/index', [
            'calendars' => $plugin->calendars->getAllCalendars(),
            'counts' => $counts,
            'connections' => $plugin->connections->getAllConnections(),
            'intervals' => Settings::intervals(),
            'autoSync' => $plugin->getSettings()->autoSync,
            'canManage' => $user->checkPermission(CalendarSync::PERMISSION_CALENDARS),
            'canSync' => $user->checkPermission(CalendarSync::PERMISSION_SYNC),
            'canConnect' => $user->checkPermission(CalendarSync::PERMISSION_CONNECTIONS),
        ]);
    }

    public function actionEdit(?int $calendarId = null, ?Calendar $calendar = null): Response
    {
        $this->requirePermission(CalendarSync::PERMISSION_CALENDARS);
        $plugin = CalendarSync::$plugin;
        $settings = $plugin->getSettings();

        if ($calendar === null) {
            if ($calendarId !== null) {
                $calendar = $plugin->calendars->getCalendarById($calendarId);
                if ($calendar === null) {
                    throw new NotFoundHttpException('Calendar not found');
                }
            } else {
                $calendar = new Calendar([
                    'syncInterval' => $settings->defaultInterval,
                    'pastDays' => $settings->defaultPastDays,
                    'futureDays' => $settings->defaultFutureDays,
                    'mapping' => ['title' => Mapper::TARGET_TITLE],
                ]);
                $connectionId = (int)$this->request->getQueryParam('connectionId');
                $calendar->connectionId = $plugin->connections->getConnectionById($connectionId)?->id;
            }
        }

        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sites[] = ['value' => $site->id, 'label' => Craft::t('site', $site->getName())];
        }

        return $this->renderTemplate('calendar-sync/calendars/_edit', [
            'calendar' => $calendar,
            'connections' => $plugin->connections->getAllConnections(),
            'sections' => $this->sections(),
            'sites' => $sites,
            'attributes' => $plugin->mapper->attributes(),
            'kinds' => Mapper::KINDS,
            'intervals' => Settings::intervals(),
            'blueprint' => [
                'canInstall' => $plugin->blueprint->canInstall() && Craft::$app->getUser()->getIsAdmin(),
                'exists' => $plugin->blueprint->getSection() !== null,
            ],
            'eventCount' => $calendar->id ? $plugin->events->find()->calendar($calendar)->status(null)->count() : 0,
            'canSync' => Craft::$app->getUser()->checkPermission(CalendarSync::PERMISSION_SYNC),
        ]);
    }

    /**
     * The calendars of a connection, for the select of the form.
     */
    public function actionRemoteCalendars(): Response
    {
        $this->requirePermission(CalendarSync::PERMISSION_CALENDARS);
        $this->requireAcceptsJson();

        $connection = CalendarSync::$plugin->connections->getConnectionById((int)$this->request->getParam('connectionId'));
        $provider = $connection?->getProvider();
        if ($connection === null || $provider === null) {
            return $this->asFailure(CalendarSync::t('This connection does not exist any more.'));
        }
        if (!$connection->getIsConnected()) {
            return $this->asFailure(CalendarSync::t('This connection is not signed in. Connect it first.'));
        }

        try {
            $calendars = array_map(static fn($calendar) => $calendar->toArray(), $provider->listCalendars($connection));
        } catch (ProviderException $e) {
            return $this->asFailure($e->getMessage());
        }
        usort($calendars, static fn(array $a, array $b) => [$b['primary'], strtolower($a['name'])] <=> [$a['primary'], strtolower($b['name'])]);

        return $this->asJson(['calendars' => $calendars]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CalendarSync::PERMISSION_CALENDARS);
        $plugin = CalendarSync::$plugin;
        $request = $this->request;

        $calendarId = $request->getBodyParam('calendarId');
        if ($calendarId) {
            $calendar = $plugin->calendars->getCalendarById((int)$calendarId);
            if ($calendar === null) {
                throw new NotFoundHttpException('Calendar not found');
            }
        } else {
            $calendar = new Calendar();
        }
        $signature = static fn(Calendar $c) => [
            $c->sectionId, $c->entryTypeId, $c->mapping, $c->direction,
            $c->entryPrefix, $c->descriptionLineBreaks, $c->descriptionDocuments,
        ];
        $before = $calendar->id ? $signature($calendar) : null;

        $calendar->name = (string)$request->getBodyParam('name');
        $calendar->handle = (string)$request->getBodyParam('handle');
        $calendar->connectionId = (int)$request->getBodyParam('connectionId') ?: null;
        $calendar->remoteId = (string)$request->getBodyParam('remoteId');
        $calendar->remoteName = (string)$request->getBodyParam('remoteName') ?: null;
        $color = strtolower(trim((string)$request->getBodyParam('color')));
        $calendar->color = $color !== '' ? (str_starts_with($color, '#') ? $color : '#' . $color) : null;
        $calendar->timezone = (string)$request->getBodyParam('timezone') ?: null;
        $calendar->enabled = (bool)$request->getBodyParam('enabled');
        $calendar->direction = (string)$request->getBodyParam('direction', Calendar::DIRECTION_IMPORT);
        $calendar->pastDays = (int)$request->getBodyParam('pastDays');
        $calendar->futureDays = (int)$request->getBodyParam('futureDays');
        $calendar->syncInterval = (int)$request->getBodyParam('syncInterval');
        $calendar->sectionId = (int)$request->getBodyParam('sectionId') ?: null;
        $calendar->entryTypeId = $calendar->sectionId ? ((int)$request->getBodyParam('entryTypeId') ?: null) : null;
        $calendar->siteId = (int)$request->getBodyParam('siteId') ?: null;
        $calendar->conflictWinner = (string)$request->getBodyParam('conflictWinner', Calendar::WINNER_REMOTE);
        $calendar->missingBehavior = (string)$request->getBodyParam('missingBehavior', Calendar::MISSING_DELETE);
        $calendar->feedEnabled = (bool)$request->getBodyParam('feedEnabled');
        $calendar->changePrefix((string)$request->getBodyParam('entryPrefix'));
        $calendar->descriptionLineBreaks = (bool)$request->getBodyParam('descriptionLineBreaks');
        $calendar->descriptionDocuments = (bool)$request->getBodyParam('descriptionDocuments');
        $calendar->descriptionLinks = (bool)$request->getBodyParam('descriptionLinks');
        // entries made from events belong to whoever set the calendar up
        $calendar->authorId ??= Craft::$app->getUser()->getId();

        $mapping = [];
        if ($calendar->sectionId) {
            foreach ((array)$request->getBodyParam('mapping', []) as $attribute => $target) {
                if (in_array($attribute, EventData::ATTRIBUTES, true) && is_string($target) && $target !== '') {
                    $mapping[$attribute] = $target;
                }
            }
        }
        $calendar->mapping = $mapping;

        if (!$plugin->calendars->saveCalendar($calendar)) {
            return $this->asModelFailure($calendar, CalendarSync::t('Couldn’t save the calendar.'), 'calendar');
        }

        if ($calendar->enabled) {
            // a new mapping only reaches the entries that exist if every one of them is written again
            $remapped = $before !== null && $before !== $signature($calendar);
            $plugin->sync->queue($calendar, $remapped, true);
        }

        return $this->asModelSuccess($calendar, CalendarSync::t('Calendar saved. The sync has been started.'), 'calendar');
    }

    public function actionSync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CalendarSync::PERMISSION_SYNC);
        $plugin = CalendarSync::$plugin;
        $force = (bool)$this->request->getBodyParam('force');

        $calendarId = (int)$this->request->getBodyParam('calendarId');
        if ($calendarId) {
            $calendar = $plugin->calendars->getCalendarById($calendarId);
            if ($calendar === null) {
                throw new NotFoundHttpException('Calendar not found');
            }
            $calendars = [$calendar];
        } else {
            $calendars = array_filter($plugin->calendars->getAllCalendars(), static fn(Calendar $calendar) => $calendar->enabled);
        }

        foreach ($calendars as $calendar) {
            $plugin->sync->queue($calendar, $force, true);
        }
        $this->setSuccessFlash(CalendarSync::t('{n, plural, =0{No calendar to sync.} =1{The sync has been started.} other{The sync of # calendars has been started.}}', ['n' => count($calendars)]));

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CalendarSync::PERMISSION_CALENDARS);
        $id = (int)($this->request->getBodyParam('calendarId') ?? $this->request->getBodyParam('id'));
        CalendarSync::$plugin->calendars->deleteCalendarById($id);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }
        $this->setSuccessFlash(CalendarSync::t('Calendar removed.'));

        return $this->redirect('calendar-sync/calendars');
    }

    /**
     * Creates the example model and hands back what the form needs to use it.
     */
    public function actionInstallBlueprint(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireAdmin();
        $blueprint = CalendarSync::$plugin->blueprint;
        if (!$blueprint->canInstall()) {
            throw new ForbiddenHttpException('Admin changes are not allowed here.');
        }

        try {
            $model = $blueprint->install();
        } catch (Throwable $e) {
            Craft::error('Example model: ' . $e->getMessage(), 'calendar-sync');

            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'sections' => $this->sections(),
            'sectionId' => $model['section']->id,
            'entryTypeId' => $model['entryType']->id,
            'mapping' => $model['mapping'],
            'message' => CalendarSync::t('The section «{name}» is ready and mapped. Save the calendar to use it.', ['name' => $model['section']->name]),
        ]);
    }

    /**
     * The sections with their entry types and the fields a mapping can use – what the form needs to offer the right
     * fields without loading again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(): array
    {
        $mapper = CalendarSync::$plugin->mapper;
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $types = [];
            foreach ($section->getEntryTypes() as $entryType) {
                $types[] = [
                    'id' => $entryType->id,
                    'name' => Craft::t('site', $entryType->name),
                    'fields' => $mapper->fieldOptions($entryType),
                ];
            }
            $sections[] = [
                'id' => $section->id,
                'name' => Craft::t('site', $section->name),
                'sites' => array_map('intval', array_keys($section->getSiteSettings())),
                'types' => $types,
            ];
        }

        return Json::decode(Json::encode($sections));
    }
}
