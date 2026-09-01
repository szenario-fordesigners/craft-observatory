<?php

namespace szenario\craftobservatory\records;

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
        return '{{%observatory_daily_stats}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['websiteId', 'date'], 'required'],
            [['websiteId'], 'string', 'max' => 255],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['pageviews', 'visitors', 'visits', 'sessionDurationSeconds'], 'integer', 'min' => 0],
            [['metrics'], 'string'],
        ];
    }
}
