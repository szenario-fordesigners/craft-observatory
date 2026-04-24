<?php

namespace szenario\craftumamiis\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%umami_daily_stats}}');
        if ($tableSchema === null) {
            $this->createTable(
                '{{%umami_daily_stats}}',
                [
                    'id' => $this->primaryKey(),
                    'websiteId' => $this->string()->notNull(),
                    'date' => $this->date()->notNull(),
                    'pageviews' => $this->integer()->notNull()->defaultValue(0),
                    'visitors' => $this->integer()->notNull()->defaultValue(0),
                    'visits' => $this->integer()->notNull()->defaultValue(0),
                    'bounces' => $this->integer()->notNull()->defaultValue(0),
                    'totaltime' => $this->integer()->notNull()->defaultValue(0),
                    'metrics' => $this->text(),
                    'dateCreated' => $this->dateTime()->notNull(),
                    'dateUpdated' => $this->dateTime()->notNull(),
                    'uid' => $this->uid(),
                ]
            );

            // Add a unique index on websiteId and date for fast lookups
            // The combination must be unique (only one row per day per website)
            $this->createIndex(
                null,
                '{{%umami_daily_stats}}',
                ['websiteId', 'date'],
                true
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%umami_daily_stats}}');
        return true;
    }
}
