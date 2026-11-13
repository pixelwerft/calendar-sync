<?php

namespace pixelwerft\calendarsync\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\errors\ProviderException;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\providers\OAuthProvider;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Signing in at Google or Microsoft: the way there and the way back.
 */
class OauthController extends Controller
{
    private const SESSION_KEY = 'calendarSync.oauth';

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
     * Sends the user to the provider. Nothing changes here; the state in the session ties the answer to this
     * browser and this connection.
     */
    public function actionConnect(): Response
    {
        [$connection, $provider] = $this->connection((int)$this->request->getParam('connectionId'));
        if (!$provider->isConfigured()) {
            $this->setFailFlash(CalendarSync::t('Enter the client ID and the client secret of {provider} in the settings first.', [
                'provider' => $provider::displayName(),
            ]));

            return $this->redirect('calendar-sync/connections/' . $connection->id);
        }

        $state = Craft::$app->getSecurity()->generateRandomString(40);
        Craft::$app->getSession()->set(self::SESSION_KEY, ['state' => $state, 'connectionId' => $connection->id]);

        return $this->redirect($provider->getAuthorizationUrl($state, CalendarSync::$plugin->getRedirectUri(), $connection->canWrite()));
    }

    /**
     * The provider sent the user back, with a code or with an error.
     */
    public function actionCallback(): Response
    {
        $session = Craft::$app->getSession();
        $pending = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        $state = (string)$this->request->getQueryParam('state');
        if (!is_array($pending) || $state === '' || !hash_equals((string)($pending['state'] ?? ''), $state)) {
            throw new BadRequestHttpException('The sign-in was not started from this browser, or it took too long. Start it again.');
        }
        [$connection, $provider] = $this->connection((int)$pending['connectionId']);
        $back = 'calendar-sync/connections/' . $connection->id;

        $error = (string)$this->request->getQueryParam('error');
        if ($error !== '') {
            $description = (string)$this->request->getQueryParam('error_description');
            $this->setFailFlash(CalendarSync::t('{provider} did not connect: {reason}', [
                'provider' => $provider::displayName(),
                'reason' => $description !== '' ? $description : $error,
            ]));

            return $this->redirect($back);
        }

        $code = (string)$this->request->getQueryParam('code');
        if ($code === '') {
            throw new BadRequestHttpException('No code');
        }

        try {
            $provider->completeAuthorization($connection, $code, CalendarSync::$plugin->getRedirectUri());
            CalendarSync::$plugin->connections->saveConnection($connection, false);
            $this->setSuccessFlash(CalendarSync::t('Connected with {provider}.', ['provider' => $provider::displayName()]));
        } catch (ProviderException $e) {
            CalendarSync::$plugin->connections->markError($connection, $e->getMessage());
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect($back);
    }

    /**
     * @return array{0: Connection, 1: OAuthProvider}
     */
    private function connection(int $id): array
    {
        $connection = CalendarSync::$plugin->connections->getConnectionById($id);
        $provider = $connection?->getProvider();
        if ($connection === null || !$provider instanceof OAuthProvider) {
            throw new NotFoundHttpException('Connection not found');
        }

        return [$connection, $provider];
    }
}
