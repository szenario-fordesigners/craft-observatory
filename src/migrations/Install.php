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
        if (Craft::$app->db->schema->getTableSchema('{{%umami_daily_stats}}') === null) {
            $this->createTable('{{%umami_daily_stats}}', [
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
            ]);

            $this->createIndex(null, '{{%umami_daily_stats}}', ['websiteId', 'date'], true);
        }

        if (Craft::$app->db->schema->getTableSchema('{{%umami_hourly_stats}}') === null) {
            $this->createTable('{{%umami_hourly_stats}}', [
                'id' => $this->primaryKey(),
                'websiteId' => $this->string()->notNull(),
                'date' => $this->date()->notNull(),
                'hour' => $this->tinyInteger()->unsigned()->notNull(),
                'visitors' => $this->integer()->notNull()->defaultValue(0),
                'pageviews' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%umami_hourly_stats}}', ['websiteId', 'date', 'hour'], true);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%umami_hourly_stats}}');
        $this->dropTableIfExists('{{%umami_daily_stats}}');
        return true;
    }
}
