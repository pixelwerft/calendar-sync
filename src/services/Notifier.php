<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Connection;
use pixelwerft\calendarsync\models\SyncResult;
use Throwable;
use yii\base\Component;

/**
 * Tells people when the sync needs them: by e-mail when a calendar starts to fail, and as a notice in the control
 * panel while something is wrong.
 */
class Notifier extends Component
{
    /** Days before the Microsoft client secret runs out at which the warning starts. */
    public const SECRET_WARNING_DAYS = 30;

    /**
     * A run of a calendar is over. One message when the calendar starts to fail, and one a day while it keeps
     * failing – not one for every run.
     */
    public function afterRun(Calendar $calendar, SyncResult $result, ?string $statusBefore): void
    {
        $cache = Craft::$app->getCache();
        $key = 'calendar-sync:notified:' . $calendar->id;
        if (!$result->hasErrors()) {
            $cache->delete($key);
            return;
        }
        if (!CalendarSync::$plugin->getSettings()->notifyOnError) {
            return;
        }
        if ($statusBefore === Calendar::STATUS_ERROR && $cache->get($key)) {
            return;
        }

        $sent = $this->send(
            CalendarSync::t('Calendar Sync: «{name}» could not be synced', ['name' => $calendar->name]),
            array_merge(
                [CalendarSync::t('The last run of the calendar «{name}» ended with errors:', ['name' => $calendar->name]), ''],
                array_map(static fn(string $error) => '– ' . $error, $result->errors),
            ),
            UrlHelper::cpUrl('calendar-sync/log', ['calendar' => $calendar->handle]),
        );
        if ($sent) {
            $cache->set($key, true, 86400);
        }
    }

    /**
     * Warns by e-mail before the client secret of the Microsoft app runs out – once a week from thirty days before.
     */
    public function checkSecret(): void
    {
        $settings = CalendarSync::$plugin->getSettings();
        $days = $settings->getMicrosoftSecretDaysLeft();
        if (!$settings->notifyOnError || $days === null || $days > self::SECRET_WARNING_DAYS) {
            return;
        }
        $cache = Craft::$app->getCache();
        if ($cache->get('calendar-sync:secret-warned')) {
            return;
        }
        $sent = $this->send(
            CalendarSync::t('Calendar Sync: the Microsoft client secret runs out soon'),
            [$this->secretMessage($days)],
            UrlHelper::cpUrl('calendar-sync/settings'),
        );
        if ($sent) {
            $cache->set('calendar-sync:secret-warned', true, 7 * 86400);
        }
    }

    /**
     * Who gets the messages: the addresses of the settings, or the address the site sends its mail from.
     *
     * @return string[]
     */
    public function recipients(): array
    {
        $recipients = CalendarSync::$plugin->getSettings()->getNotificationEmails();
        if (!$recipients) {
            $from = App::parseEnv(App::mailSettings()->fromEmail);
            $recipients = is_string($from) && $from !== '' ? [$from] : [];
        }

        return $recipients;
    }

    /**
     * Sends a message to the recipients. False if there is nobody to send it to, or the mailer refused.
     *
     * @param string[] $lines
     */
    public function send(string $subject, array $lines, ?string $url = null): bool
    {
        $recipients = $this->recipients();
        if (!$recipients) {
            return false;
        }
        $text = implode("\n", $lines) . ($url ? "\n\n" . $url : '');
        $html = implode('', array_map(static fn(string $line) => $line === '' ? '' : Html::tag('p', Html::encode($line)), $lines))
            . ($url ? Html::tag('p', Html::a(Html::encode($url), $url)) : '');

        try {
            return Craft::$app->getMailer()->compose()
                ->setTo($recipients)
                ->setSubject($subject)
                ->setTextBody($text)
                ->setHtmlBody($html)
                ->send();
        } catch (Throwable $e) {
            Craft::error('Notification not sent: ' . $e->getMessage(), 'calendar-sync');

            return false;
        }
    }

    /**
     * What the control panel shows on top of every page while something is wrong – to those who can do something
     * about it.
     *
     * @return string[] HTML
     */
    public function alerts(): array
    {
        $user = Craft::$app->getUser();
        if (!$user->checkPermission('accessPlugin-calendar-sync')) {
            return [];
        }
        $plugin = CalendarSync::$plugin;
        $alerts = [];

        $failed = array_filter(
            $plugin->calendars->getAllCalendars(),
            static fn(Calendar $calendar) => $calendar->enabled && $calendar->lastSyncStatus === Calendar::STATUS_ERROR,
        );
        if ($failed) {
            $alerts[] = Html::encode(CalendarSync::t('{n, plural, =1{# calendar could not be synced.} other{# calendars could not be synced.}}', ['n' => count($failed)]))
                . ' ' . Html::a(Html::encode(CalendarSync::t('Show the log')), UrlHelper::cpUrl('calendar-sync/log', ['status' => Calendar::STATUS_ERROR]));
        }

        if ($user->getIsAdmin() || $user->checkPermission(CalendarSync::PERMISSION_CONNECTIONS)) {
            $broken = array_filter(
                $plugin->connections->getAllConnections(),
                static fn(Connection $connection) => $connection->status === Connection::STATUS_ERROR,
            );
            if ($broken) {
                $alerts[] = Html::encode(CalendarSync::t('{n, plural, =1{# calendar connection needs attention.} other{# calendar connections need attention.}}', ['n' => count($broken)]))
                    . ' ' . Html::a(Html::encode(CalendarSync::t('Open the settings')), UrlHelper::cpUrl('calendar-sync/settings'));
            }
            $days = $plugin->getSettings()->getMicrosoftSecretDaysLeft();
            if ($days !== null && $days <= self::SECRET_WARNING_DAYS) {
                $alerts[] = Html::encode($this->secretMessage($days))
                    . ' ' . Html::a(Html::encode(CalendarSync::t('Open the settings')), UrlHelper::cpUrl('calendar-sync/settings'));
            }
        }

        return $alerts;
    }

    private function secretMessage(int $days): string
    {
        return $days < 0
            ? CalendarSync::t('The client secret of the Microsoft app has run out. Create a new one, enter it in the settings and set its date.')
            : CalendarSync::t('{n, plural, =0{The client secret of the Microsoft app runs out today.} =1{The client secret of the Microsoft app runs out tomorrow.} other{The client secret of the Microsoft app runs out in # days.}} Create a new one in good time and enter it in the settings.', ['n' => $days]);
    }
}
