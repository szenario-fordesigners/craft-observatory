<?php

namespace szenario\craftobservatory\records;

use craft\db\ActiveRecord;

/**
 * Per-day, per-facet sync state.
 *
 * Replaces "a DailyStats row exists" as the completeness marker. Each (websiteId, date,
 * facet) row records whether that facet finished and how many times it has been tried,
 * so the coordinator can retry just the parts that failed — bounded by an attempt cap —
 * instead of freezing a whole day on the first partial failure. Absence of a row means
 * the facet has never been attempted.
 *
 * @property int $id
 * @property string $websiteId
 * @property string $date
 * @property string $facet One of SyncCoordinator::FACET_*.
 * @property string $status SyncCoordinator::STATUS_DONE | STATUS_FAILED.
 * @property int $attempts
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SyncState extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%observatory_sync_state}}';
    }
}
