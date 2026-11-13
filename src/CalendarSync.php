<?php

namespace pixelwerft\calendarsync;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use pixelwerft\calendarsync\gql\Schema;
use pixelwerft\calendarsync\models\Settings;
use pixelwerft\calendarsync\services\Blueprint;
use pixelwerft\calendarsync\services\Calendars;
use pixelwerft\calendarsync\services\Connections;
use pixelwerft\calendarsync\services\Events;
use pixelwerft\calendarsync\services\Mapper;
use pixelwerft\calendarsync\services\Providers;
use pixelwerft\calendarsync\services\Sync;
use pixelwerft\calendarsync\variables\CalendarSyncVariable;
use pixelwerft\calendarsync\widgets\Agenda;
use pixelwerft\calendarsync\widgets\Counter;
use Throwable;
use yii\base\Event;

/**
 * Calendar Sync: connects Google Calendar, Microsoft 365 / Outlook and CalDAV calendars to Craft, syncs their
 * events in either direction, maps them to the fields of entries and presents them with Twig and feeds.
 *
 * @property-read Providers $providers
 * @property-read Connections $connections
 * @property-read Calendars $calendars
 * @property-read Events $events
 * @property-read Mapper $mapper
 * @property-read Sync $sync
 * @property-read Blueprint $blueprint
 * @method Settings getSettings()
 */
class CalendarSync extends Plugin
{
    public const PERMISSION_CONNECTIONS = 'calendar-sync:connections';
    public const PERMISSION_CALENDARS = 'calendar-sync:calendars';
    public const PERMISSION_SYNC = 'calendar-sync:sync';

    public static CalendarSync $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'providers' => Providers::class,
                'connections' => Connections::class,
                'calendars' => Calendars::class,
                'events' => Events::class,
                'mapper' => Mapper::class,
                'sync' => Sync::class,
                'blueprint' => Blueprint::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'pixelwerft\\calendarsync\\console\\controllers';
        }

        $this->registerUrlRules();
        $this->registerPermissions();
        $this->registerVariable();
        $this->registerWidgets();
        $this->registerGraphQl();

        // Everything below needs the tables of the plugin
        if (!$this->isInstalled) {
            return;
        }
        $this->registerEntryListeners();
        Craft::$app->onInit(function() {
            $this->queueDueCalendars();
        });
    }

    public static function t(string $message, array $params = []): string
    {
        return Craft::t('calendar-sync', $message, $params);
    }

    /**
     * The address a provider sends people back to after signing in. It has to be entered in the OAuth app.
     */
    public function getRedirectUri(): string
    {
        return UrlHelper::cpUrl('calendar-sync/oauth/callback');
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();
        $item['subnav'] = [
            'calendars' => ['label' => self::t('Calendars'), 'url' => 'calendar-sync/calendars'],
            'events' => ['label' => self::t('Events'), 'url' => 'calendar-sync/events'],
        ];
        // the providers and their connections live in the settings – also where admin changes are switched off
        if ($user->getIsAdmin() || $user->checkPermission(self::PERMISSION_CONNECTIONS)) {
            $item['subnav']['settings'] = ['label' => self::t('Settings'), 'url' => 'calendar-sync/settings'];
        }

        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The settings have a page of their own, next to the calendars.
     *
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('calendar-sync/settings'));
    }

    private function registerUrlRules(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['calendar-sync'] = 'calendar-sync/calendars/index';
            $event->rules['calendar-sync/calendars'] = 'calendar-sync/calendars/index';
            $event->rules['calendar-sync/calendars/new'] = 'calendar-sync/calendars/edit';
            $event->rules['calendar-sync/calendars/<calendarId:\d+>'] = 'calendar-sync/calendars/edit';
            $event->rules['calendar-sync/events'] = 'calendar-sync/events/index';
            $event->rules['calendar-sync/settings'] = 'calendar-sync/settings/index';
            $event->rules['calendar-sync/connections'] = 'calendar-sync/connections/index';
            $event->rules['calendar-sync/connections/new'] = 'calendar-sync/connections/edit';
            $event->rules['calendar-sync/connections/<connectionId:\d+>'] = 'calendar-sync/connections/edit';
            $event->rules['calendar-sync/oauth/connect'] = 'calendar-sync/oauth/connect';
            $event->rules['calendar-sync/oauth/callback'] = 'calendar-sync/oauth/callback';
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['calendar-sync/feed/<token:[A-Za-z0-9_-]+>.<format:(ics|json)>'] = 'calendar-sync/feed/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => self::t('Calendar Sync'),
                'permissions' => [
                    self::PERMISSION_SYNC => ['label' => self::t('Start a sync')],
                    self::PERMISSION_CALENDARS => ['label' => self::t('Set up calendars and their field mapping')],
                    self::PERMISSION_CONNECTIONS => ['label' => self::t('Manage the connections to Google, Microsoft and CalDAV')],
                ],
            ];
        });
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('calendarSync', CalendarSyncVariable::class);
        });
    }

    private function registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Counter::class;
            $event->types[] = Agenda::class;
        });
    }

    /**
     * Events and calendars as GraphQL queries, for the schemas that are allowed to see them.
     */
    private function registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[self::t('Calendar Sync')] = [
                Schema::COMPONENT . ':read' => ['label' => self::t('Query the events and calendars')],
            ];
        });
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, Schema::queries());
        });
    }

    /**
     * Entries of a section that goes to a calendar are sent there when they change. Whatever goes wrong here must
     * never stop an entry from being saved.
     */
    private function registerEntryListeners(): void
    {
        $handler = function(ElementEvent $event) {
            try {
                $this->sync->handleEntryChange($event->element);
            } catch (Throwable $e) {
                Craft::error('Entry not sent to the calendar: ' . $e->getMessage(), 'calendar-sync');
            }
        };
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $handler);
    }

    /**
     * Craft has no clock of its own: while people use the site, the plugin looks once a minute whether a calendar
     * is due and puts it on the queue. Sites without visitors at night use the console command in a cron job.
     */
    private function queueDueCalendars(): void
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !$this->getSettings()->autoSync || Craft::$app->getIsInMaintenanceMode()) {
            return;
        }
        if (!Craft::$app->getIsInstalled() || Craft::$app->getUpdates()->getIsCraftUpdatePending()) {
            return;
        }

        try {
            $cache = Craft::$app->getCache();
            if ($cache->get('calendar-sync:checked')) {
                return;
            }
            $cache->set('calendar-sync:checked', true, 60);
            $this->sync->queueDue();
        } catch (Throwable $e) {
            Craft::error('Due calendars not queued: ' . $e->getMessage(), 'calendar-sync');
        }
    }
}
