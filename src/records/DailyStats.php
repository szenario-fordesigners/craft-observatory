<?php

namespace szenario\craftumamiis\records;

use craft\db\ActiveRecord;

/**
 * Daily Stats record
 *
 * @property int $id
 * @property string $websiteId
 * @property string $date
 * @property int $pageviews
 * @property int $visitors
 * @property int $visits
 * @property int $sessionDurationSeconds
 * @property string|null $metrics
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DailyStats extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%umami_daily_stats}}';
    }
}
