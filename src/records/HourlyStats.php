<?php

namespace szenario\craftobservatory\records;

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
        return '{{%observatory_hourly_stats}}';
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
            [['hour'], 'integer', 'min' => 0, 'max' => 23],
            [['visitors', 'pageviews'], 'integer', 'min' => 0],
        ];
    }
}
