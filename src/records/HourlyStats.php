<?php

namespace szenario\craftumamiis\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $websiteId
 * @property string $date
 * @property int $hour
 * @property int $visitors
 * @property int $pageviews
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class HourlyStats extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%umami_hourly_stats}}';
    }
}
