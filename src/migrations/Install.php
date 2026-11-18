<?php

namespace pixelwerft\calendarsync\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use pixelwerft\calendarsync\db\Table;

/**
 * The three tables of the plugin: connections, calendars, events.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTable(Table::CONNECTIONS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'provider' => $this->string(50)->notNull(),
            'settings' => $this->text(),
            // encrypted with the security key of the site
            'credentials' => $this->text(),
            'status' => $this->string(20)->notNull()->defaultValue('pending'),
            'lastError' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CALENDARS, [
            'id' => $this->primaryKey(),
            'connectionId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string(64)->notNull(),
            'remoteId' => $this->text()->notNull(),
            'remoteName' => $this->string(),
            'color' => $this->string(20),
            'timezone' => $this->string(100),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'direction' => $this->string(10)->notNull()->defaultValue('import'),
            'pastDays' => $this->integer()->notNull()->defaultValue(30),
            'futureDays' => $this->integer()->notNull()->defaultValue(365),
            'syncInterval' => $this->integer()->notNull()->defaultValue(60),
            'siteId' => $this->integer(),
            'sectionId' => $this->integer(),
            'entryTypeId' => $this->integer(),
            'authorId' => $this->integer(),
            'mapping' => $this->text(),
            'conflictWinner' => $this->string(10)->notNull()->defaultValue('remote'),
            'missingBehavior' => $this->string(10)->notNull()->defaultValue('delete'),
            'feedEnabled' => $this->boolean()->notNull()->defaultValue(false),
            'feedToken' => $this->string(64),
            // what needs no column of its own: the prefix of entries, how the description is formatted
            'options' => $this->text(),
            'lastSyncedAt' => $this->dateTime(),
            'lastSyncStatus' => $this->string(10),
            'lastSyncMessage' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::EVENTS, [
            'id' => $this->primaryKey(),
            'calendarId' => $this->integer()->notNull(),
            // the IDs of the providers can be long (Microsoft) or addresses (CalDAV): the key is their SHA-1
            'remoteKey' => $this->char(40)->notNull(),
            'remoteId' => $this->text()->notNull(),
            'icalUid' => $this->text(),
            'etag' => $this->string(),
            'title' => $this->string(500)->notNull()->defaultValue(''),
            'description' => $this->mediumText(),
            'location' => $this->text(),
            'url' => $this->text(),
            'attachments' => $this->text(),
            // UTC; for an all-day event the dates at 00:00 with an exclusive end
            'startDate' => $this->dateTime()->notNull(),
            'endDate' => $this->dateTime()->notNull(),
            'allDay' => $this->boolean()->notNull()->defaultValue(false),
            'timezone' => $this->string(100),
            'status' => $this->string(20)->notNull()->defaultValue('confirmed'),
            'organizer' => $this->string(500),
            'recurring' => $this->boolean()->notNull()->defaultValue(false),
            'origin' => $this->string(10)->notNull()->defaultValue('remote'),
            'entryId' => $this->integer(),
            'remoteHash' => $this->char(32),
            'entryHash' => $this->char(32),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        self::createRunsTable($this);

        $this->createIndex(null, Table::CALENDARS, ['handle'], true);
        $this->createIndex(null, Table::CALENDARS, ['feedToken']);
        $this->createIndex(null, Table::CALENDARS, ['sectionId']);
        $this->createIndex(null, Table::EVENTS, ['calendarId', 'remoteKey'], true);
        $this->createIndex(null, Table::EVENTS, ['calendarId', 'startDate']);
        $this->createIndex(null, Table::EVENTS, ['startDate']);
        $this->createIndex(null, Table::EVENTS, ['endDate']);
        $this->createIndex(null, Table::EVENTS, ['entryId']);

        $this->addForeignKey(null, Table::CALENDARS, ['connectionId'], Table::CONNECTIONS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::CALENDARS, ['siteId'], CraftTable::SITES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::CALENDARS, ['sectionId'], CraftTable::SECTIONS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::CALENDARS, ['entryTypeId'], CraftTable::ENTRYTYPES, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::CALENDARS, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');
        $this->addForeignKey(null, Table::EVENTS, ['calendarId'], Table::CALENDARS, ['id'], 'CASCADE');
        $this->addForeignKey(null, Table::EVENTS, ['entryId'], CraftTable::ELEMENTS, ['id'], 'SET NULL');

        return true;
    }

    /**
     * The log: one row per run of a calendar. Shared with the migration that adds it to sites installed before 1.1.
     */
    public static function createRunsTable(Migration $migration): void
    {
        if ($migration->db->tableExists(Table::RUNS)) {
            return;
        }
        $migration->createTable(Table::RUNS, [
            'id' => $migration->primaryKey(),
            'calendarId' => $migration->integer()->notNull(),
            // what started the run: schedule, manual, save, console, entry
            'trigger' => $migration->string(20)->notNull()->defaultValue('manual'),
            'status' => $migration->string(10)->notNull()->defaultValue('ok'),
            'dateStarted' => $migration->dateTime()->notNull(),
            // milliseconds
            'duration' => $migration->integer()->notNull()->defaultValue(0),
            // the numbers, the errors and the single changes of the run, as JSON
            'result' => $migration->mediumText(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, Table::RUNS, ['calendarId', 'dateStarted']);
        $migration->createIndex(null, Table::RUNS, ['dateStarted']);
        $migration->addForeignKey(null, Table::RUNS, ['calendarId'], Table::CALENDARS, ['id'], 'CASCADE');
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RUNS);
        $this->dropTableIfExists(Table::EVENTS);
        $this->dropTableIfExists(Table::CALENDARS);
        $this->dropTableIfExists(Table::CONNECTIONS);

        return true;
    }
}
