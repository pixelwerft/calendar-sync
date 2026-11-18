<?php

namespace pixelwerft\calendarsync\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\Run;
use pixelwerft\calendarsync\providers\CalDav;
use pixelwerft\calendarsync\providers\IcsFeed;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The connections to the providers: list, create, edit, test, remove.
 */
class ConnectionsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission(CalendarSync::PERMISSION_CONNECTIONS);

        return true;
    }

    /**
     * The connections are listed with their providers, in the settings.
     */
    public function actionIndex(): Response
    {
        return $this->redirect('calendar-sync/settings');
    }

    public function actionEdit(?int $connectionId = null, ?Connection $connection = null): Response
    {
        $plugin = CalendarSync::$plugin;
        if ($connection === null) {
            if ($connectionId !== null) {
                $connection = $plugin->connections->getConnectionById($connectionId);
                if ($connection === null) {
                    throw new NotFoundHttpException('Connection not found');
                }
            } else {
                $connection = new Connection();
                $provider = (string)$this->request->getQueryParam('provider');
                $connection->provider = $plugin->providers->get($provider) ? $provider : 'google';
            }
        }

        return $this->renderTemplate('calendar-sync/connections/_edit', [
            'connection' => $connection,
            'providers' => $plugin->providers->all(),
            'calendars' => $connection->id ? $plugin->calendars->getCalendarsByConnectionId($connection->id) : [],
            'redirectUri' => $plugin->getRedirectUri(),
            'isAdmin' => Craft::$app->getUser()->getIsAdmin(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $plugin = CalendarSync::$plugin;

        $connectionId = $this->request->getBodyParam('connectionId');
        if ($connectionId) {
            $connection = $plugin->connections->getConnectionById((int)$connectionId);
            if ($connection === null) {
                throw new NotFoundHttpException('Connection not found');
            }
        } else {
            $connection = new Connection();
            // the provider is chosen once: a connection cannot move to another service
            $connection->provider = (string)$this->request->getBodyParam('provider');
        }

        $connection->name = (string)$this->request->getBodyParam('name');
        $provider = $connection->getProvider();
        $accessBefore = $connection->id ? $connection->getAccess() : null;
        $settings = (array)$this->request->getBodyParam('settings', []);

        if ($provider instanceof CalDav) {
            $connection->settings = [
                'serverUrl' => trim((string)($settings['serverUrl'] ?? '')),
                'username' => trim((string)($settings['username'] ?? '')),
                'authType' => ($settings['authType'] ?? 'basic') === 'digest' ? 'digest' : 'basic',
            ];
            // an empty password field keeps the stored password
            $password = (string)$this->request->getBodyParam('password');
            if ($password !== '') {
                $connection->credentials = ['password' => $password];
            }
        } elseif ($provider instanceof IcsFeed) {
            $connection->settings = ['url' => IcsFeed::normalizeUrl((string)($settings['url'] ?? ''))];
        }

        // what Craft may do with the calendars of this connection
        $access = (string)($settings['access'] ?? '');
        if (in_array($access, [Connection::ACCESS_READ_WRITE, Connection::ACCESS_READ, Connection::ACCESS_BUSY], true)) {
            $connection->settings['access'] = $access;
        }
        $connection->settings['access'] = $connection->getAccess();
        $busyTitle = trim((string)($settings['busyTitle'] ?? ''));
        // kept as text, in the language of whoever set it: the title must not change with the reader
        $connection->settings['busyTitle'] = mb_substr($busyTitle !== '' ? $busyTitle : $connection->getBusyTitle(), 0, 100);

        if (!$connection->validate()) {
            return $this->asModelFailure($connection, CalendarSync::t('Couldn’t save the connection.'), 'connection');
        }

        if ($provider !== null && !$provider::usesOAuth()) {
            // an address and a password are only worth saving if they work
            try {
                $provider->test($connection);
                $connection->status = Connection::STATUS_CONNECTED;
                $connection->lastError = null;
            } catch (ProviderException $e) {
                $connection->addError($provider instanceof IcsFeed ? 'url' : 'serverUrl', $e->getMessage());

                return $this->asModelFailure($connection, CalendarSync::t('The server did not accept the connection.'), 'connection');
            }
        }

        $plugin->connections->saveConnection($connection, false);
        $this->applyAccess($connection, $accessBefore);

        if ($provider !== null && $provider::usesOAuth() && $connection->getNeedsSignIn() && $provider->isConfigured()) {
            // straight on to the sign-in – also when the connection shall write and was only allowed to read
            return $this->redirect('calendar-sync/oauth/connect?connectionId=' . $connection->id);
        }

        return $this->asModelSuccess($connection, CalendarSync::t('Connection saved.'), 'connection', [], 'calendar-sync/connections/' . $connection->id);
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $connection = $this->connection();

        try {
            $account = $connection->getProvider()?->test($connection);
            if ($account && $connection->getProvider()::usesOAuth()) {
                $connection->settings['account'] = $account;
            }
            $connection->status = Connection::STATUS_CONNECTED;
            $connection->lastError = null;
            CalendarSync::$plugin->connections->saveConnection($connection, false);
            $this->setSuccessFlash(CalendarSync::t('The connection works.'));
        } catch (ProviderException $e) {
            CalendarSync::$plugin->connections->markError($connection, $e->getMessage());
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect('calendar-sync/connections/' . $connection->id);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $connection = $this->connection();
        CalendarSync::$plugin->connections->deleteConnectionById((int)$connection->id);

        if ($this->request->getAcceptsJson()) {
            return $this->asSuccess();
        }
        $this->setSuccessFlash(CalendarSync::t('Connection removed.'));

        return $this->redirect('calendar-sync/settings');
    }

    /**
     * A connection that only reads has no calendar that writes. And when what Craft may see changed, every
     * calendar is read again from the start.
     */
    private function applyAccess(Connection $connection, ?string $before): void
    {
        $plugin = CalendarSync::$plugin;
        foreach ($plugin->calendars->getCalendarsByConnectionId((int)$connection->id) as $calendar) {
            if (!$connection->canWrite() && $calendar->exports()) {
                $calendar->direction = Calendar::DIRECTION_IMPORT;
                $plugin->calendars->saveCalendar($calendar, false);
            }
            if ($before !== null && $before !== $connection->getAccess() && $calendar->enabled && $connection->getIsConnected()) {
                $plugin->sync->queue($calendar, true, true, Run::TRIGGER_SAVE);
            }
        }
    }

    private function connection(): Connection
    {
        $id = (int)($this->request->getBodyParam('connectionId') ?? $this->request->getBodyParam('id'));
        $connection = CalendarSync::$plugin->connections->getConnectionById($id);
        if ($connection === null) {
            throw new NotFoundHttpException('Connection not found');
        }

        return $connection;
    }
}
