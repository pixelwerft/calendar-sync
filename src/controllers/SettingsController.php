<?php

namespace pixelwerft\calendarsync\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Settings;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The settings of the plugin: the providers with their connections and the way to their credentials, how the sync
 * behaves, and the example model.
 *
 * It is a page of its own rather than Craft's plugin settings page: connections have to be made on the live site
 * too, where admin changes are switched off.
 */
class SettingsController extends Controller
{
    public function actionIndex(?Settings $settings = null): Response
    {
        $plugin = CalendarSync::$plugin;
        $user = Craft::$app->getUser();
        $isAdmin = $user->getIsAdmin();
        $canConnect = $user->checkPermission(CalendarSync::PERMISSION_CONNECTIONS);
        if (!$isAdmin && !$canConnect) {
            throw new ForbiddenHttpException('User is not permitted to see the settings.');
        }

        $connections = [];
        foreach ($plugin->connections->getAllConnections() as $connection) {
            $connections[$connection->provider][] = $connection;
        }
        $calendars = [];
        foreach ($plugin->calendars->getAllCalendars() as $calendar) {
            $calendars[$calendar->connectionId][] = $calendar;
        }

        return $this->renderTemplate('calendar-sync/settings/index', [
            'settings' => $settings ?? $plugin->getSettings(),
            'overridden' => array_keys(Craft::$app->getConfig()->getConfigFromFile($plugin->id)),
            'redirectUri' => $plugin->getRedirectUri(),
            'intervals' => Settings::intervals(),
            'providers' => $plugin->providers->all(),
            'connections' => $connections,
            'calendars' => $calendars,
            'isAdmin' => $isAdmin,
            'canConnect' => $canConnect,
            // settings are project config: they can only be changed where admin changes are allowed
            'canEdit' => $isAdmin && Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'model' => [
                'canInstall' => $isAdmin && $plugin->blueprint->canInstall(),
                'section' => $plugin->blueprint->getSection(),
                'parts' => $plugin->blueprint->describe(),
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $plugin = CalendarSync::$plugin;

        $posted = (array)$this->request->getBodyParam('settings', []);
        // what the config file sets cannot be changed here
        $overridden = array_keys(Craft::$app->getConfig()->getConfigFromFile($plugin->id));
        $posted = array_diff_key($posted, array_flip($overridden));

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $posted)) {
            $this->setFailFlash(Craft::t('app', 'Couldn’t save plugin settings.'));
            Craft::$app->getUrlManager()->setRouteParams(['settings' => $plugin->getSettings()]);

            return null;
        }
        $this->setSuccessFlash(Craft::t('app', 'Plugin settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Creates the example model: a section, an entry type and the fields an event needs.
     */
    public function actionInstallModel(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $blueprint = CalendarSync::$plugin->blueprint;
        if (!$blueprint->canInstall()) {
            throw new ForbiddenHttpException('Admin changes are not allowed here.');
        }

        try {
            $model = $blueprint->install();
            $this->setSuccessFlash(CalendarSync::t('The section «{name}» is ready. Choose it in a calendar – the field mapping is filled in.', [
                'name' => Craft::t('site', $model['section']->name),
            ]));
        } catch (Throwable $e) {
            Craft::error('Example model: ' . $e->getMessage(), 'calendar-sync');
            $this->setFailFlash($e->getMessage());
        }

        return $this->redirect('calendar-sync/settings#model');
    }
}
