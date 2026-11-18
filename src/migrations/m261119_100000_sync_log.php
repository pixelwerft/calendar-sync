<?php

namespace pixelwerft\calendarsync\migrations;

use craft\db\Migration;
use pixelwerft\calendarsync\db\Table;

/**
 * 1.1: the log of the runs.
 */
class m261119_100000_sync_log extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        Install::createRunsTable($this);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RUNS);

        return true;
    }
}
