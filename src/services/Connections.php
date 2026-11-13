<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\db\Table;
use pixelwerft\calendarsync\models\Connection;
use yii\base\Component;

/**
 * The connections to the providers. Tokens and passwords are encrypted with the security key of the site before
 * they reach the database.
 */
class Connections extends Component
{
    /** @var array<int, Connection>|null */
    private ?array $connections = null;

    /**
     * @return Connection[]
     */
    public function getAllConnections(): array
    {
        if ($this->connections === null) {
            $this->connections = [];
            $rows = (new Query())->from(Table::CONNECTIONS)->orderBy(['name' => SORT_ASC])->all();
            foreach ($rows as $row) {
                $this->connections[(int)$row['id']] = $this->populate($row);
            }
        }

        return array_values($this->connections);
    }

    public function getConnectionById(int $id): ?Connection
    {
        $this->getAllConnections();

        return $this->connections[$id] ?? null;
    }

    public function saveConnection(Connection $connection, bool $runValidation = true): bool
    {
        if ($runValidation && !$connection->validate()) {
            return false;
        }

        $values = [
            'name' => $connection->name,
            'provider' => $connection->provider,
            'settings' => Json::encode($connection->settings),
            'credentials' => $this->encrypt($connection->credentials),
            'status' => $connection->status,
            'lastError' => $connection->lastError,
        ];

        if ($connection->id) {
            Db::update(Table::CONNECTIONS, $values, ['id' => $connection->id]);
        } else {
            $connection->uid ??= StringHelper::UUID();
            Db::insert(Table::CONNECTIONS, $values + ['uid' => $connection->uid]);
            $connection->id = (int)Craft::$app->getDb()->getLastInsertID(Table::CONNECTIONS);
        }
        $this->connections = null;

        return true;
    }

    /**
     * Stores renewed tokens without touching anything else.
     */
    public function saveCredentials(Connection $connection): void
    {
        if (!$connection->id) {
            return;
        }
        Db::update(Table::CONNECTIONS, [
            'credentials' => $this->encrypt($connection->credentials),
            'status' => Connection::STATUS_CONNECTED,
            'lastError' => null,
        ], ['id' => $connection->id]);
        $connection->status = Connection::STATUS_CONNECTED;
        $connection->lastError = null;
        $this->connections = null;
    }

    public function markError(Connection $connection, string $message): void
    {
        if (!$connection->id) {
            return;
        }
        Db::update(Table::CONNECTIONS, [
            'status' => Connection::STATUS_ERROR,
            'lastError' => $message,
        ], ['id' => $connection->id]);
        $connection->status = Connection::STATUS_ERROR;
        $connection->lastError = $message;
        $this->connections = null;
    }

    /**
     * Removes the connection with its calendars and their events. Entries stay.
     */
    public function deleteConnectionById(int $id): bool
    {
        $deleted = Db::delete(Table::CONNECTIONS, ['id' => $id]);
        $this->connections = null;
        // its calendars went with it
        CalendarSync::$plugin->calendars->forget();

        return $deleted > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function populate(array $row): Connection
    {
        return new Connection([
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'provider' => (string)$row['provider'],
            'settings' => Json::decodeIfJson($row['settings'] ?: '[]') ?: [],
            'credentials' => $this->decrypt($row['credentials']),
            'status' => (string)$row['status'],
            'lastError' => $row['lastError'],
            'dateCreated' => DateTimeHelper::toDateTime($row['dateCreated']) ?: null,
            'dateUpdated' => DateTimeHelper::toDateTime($row['dateUpdated']) ?: null,
            'uid' => $row['uid'],
        ]);
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function encrypt(array $credentials): ?string
    {
        if (!$credentials) {
            return null;
        }

        return base64_encode(Craft::$app->getSecurity()->encryptByKey(Json::encode($credentials)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decrypt(?string $value): array
    {
        if (!$value) {
            return [];
        }
        try {
            $json = Craft::$app->getSecurity()->decryptByKey((string)base64_decode($value, true));
        } catch (\Throwable) {
            $json = false;
        }
        // a database from a site with another security key: nothing to read, the connection has to be made again
        $credentials = $json ? Json::decodeIfJson($json) : null;

        return is_array($credentials) ? $credentials : [];
    }
}
