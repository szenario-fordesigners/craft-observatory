<?php

namespace szenario\craftobservatory\records;

use craft\db\ActiveRecord;

/**
 * Daily Events record — one row per (websiteId, date, eventName) with its total count.
 *
 * @property int $id
 * @property string $websiteId
 * @property string $date
 * @property string $eventName
 * @property int $total
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DailyEvents extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%observatory_daily_events}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['websiteId', 'date', 'eventName'], 'required'],
            [['websiteId', 'eventName'], 'string', 'max' => 255],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['total'], 'integer', 'min' => 0],
        ];
    }
}
