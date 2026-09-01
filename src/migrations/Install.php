<?php

namespace szenario\craftobservatory\migrations;

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
        $schema = Craft::$app->db->schema;

        if ($schema->getTableSchema('{{%observatory_daily_stats}}') === null) {
            $this->createTable('{{%observatory_daily_stats}}', [
                'id' => $this->primaryKey(),
                'websiteId' => $this->string()->notNull(),
                'date' => $this->date()->notNull(),
                'pageviews' => $this->integer()->notNull()->defaultValue(0),
                'visitors' => $this->integer()->notNull()->defaultValue(0),
                'visits' => $this->integer()->notNull()->defaultValue(0),
                'sessionDurationSeconds' => $this->integer()->notNull()->defaultValue(0),
                // Holds a JSON map of per-type breakdowns (one top-100 list per dimension
                // in Observatory::MIRRORED_METRIC_TYPES). mediumText, not text: a dozen
                // dimensions of long URLs blows past TEXT's 64KB ceiling, which MySQL
                // truncates silently and corrupts the JSON.
                'metrics' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%observatory_daily_stats}}', ['websiteId', 'date'], true);
        }

        if ($schema->getTableSchema('{{%observatory_daily_events}}') === null) {
            // Analytics event names are case-sensitive; MySQL's default utf8mb4_*_ci
            // collation would collapse "LoginSuccess" and "loginsuccess" into one
            // row under the unique index, so force a binary collation there.
            // Postgres is case-sensitive by default and has no equivalent knob.
            $eventName = $this->string()->notNull();
            if (Craft::$app->db->getIsMysql()) {
                $eventName->append('COLLATE utf8mb4_bin');
            }

            $this->createTable('{{%observatory_daily_events}}', [
                'id' => $this->primaryKey(),
                'websiteId' => $this->string()->notNull(),
                'date' => $this->date()->notNull(),
                'eventName' => $eventName,
                'total' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%observatory_daily_events}}', ['websiteId', 'date', 'eventName'], true);
        }

        if ($schema->getTableSchema('{{%observatory_sync_state}}') === null) {
            // Per-day, per-facet completeness marker (daily / breakdowns / hourly / events).
            // Replaces "a DailyStats row exists" so partial failures can be retried per
            // facet, bounded by an attempt cap, instead of freezing the whole day.
            $this->createTable('{{%observatory_sync_state}}', [
                'id' => $this->primaryKey(),
                'websiteId' => $this->string()->notNull(),
                'date' => $this->date()->notNull(),
                'facet' => $this->string()->notNull(),
                'status' => $this->string()->notNull(),
                'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%observatory_sync_state}}', ['websiteId', 'date', 'facet'], true);
        }

        if (Craft::$app->db->schema->getTableSchema('{{%observatory_hourly_stats}}') === null) {
            $this->createTable('{{%observatory_hourly_stats}}', [
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

            $this->createIndex(null, '{{%observatory_hourly_stats}}', ['websiteId', 'date', 'hour'], true);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%observatory_daily_events}}');
        $this->dropTableIfExists('{{%observatory_hourly_stats}}');
        $this->dropTableIfExists('{{%observatory_sync_state}}');
        $this->dropTableIfExists('{{%observatory_daily_stats}}');
        return true;
    }
}
