<?php

namespace szenario\craftumamiis\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;

/**
 * Umami Is Widget widget type
 */
class UmamiIsWidget extends Widget
{
    /**
     * @var string
     */
    public string $defaultPeriod = '24h';

    public static function displayName(): string
    {
        return Craft::t('umami-is', 'Umami Is Widget');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['defaultPeriod'], 'string'];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'umami-is/_widget-settings',
            ['widget' => $this]
        );
    }

    public static function isSelectable(): bool
    {
        return true;
    }

    public static function icon(): ?string
    {
        return 'chart-line';
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftUmamiIsWidgetAsset::class);

        \szenario\craftumamiis\UmamiIs::getInstance()->sync->autoSyncMissingDays();

        // Snap to end of today
        $endAt = strtotime('today 23:59:59') * 1000;
        $unit = 'hour';
        $timeOffset = '-24 hours';

        switch ($this->defaultPeriod) {
            case 'today':
                $timeOffset = 'today';
                break;
            case '24h':
                $timeOffset = '-1 day'; // Snap to start of yesterday to act like 24h day period
                break;
            case 'this_week':
                $timeOffset = 'monday this week';
                $unit = 'day';
                break;
            case '7d':
                $timeOffset = '-6 days'; // Last 7 days including today is today - 6
                $unit = 'day';
                break;
            case 'this_month':
                $timeOffset = 'first day of this month';
                $unit = 'day';
                break;
            case '30d':
                $timeOffset = '-29 days';
                $unit = 'day';
                break;
            case '90d':
                $timeOffset = '-89 days';
                $unit = 'day';
                break;
            case 'this_year':
                $timeOffset = 'first day of january this year';
                $unit = 'month';
                break;
            case '6m':
                $timeOffset = '-5 months';
                $timeOffset = date('Y-m-01', strtotime($timeOffset)); // Snap to start of month
                $unit = 'month';
                break;
            case '12m':
                $timeOffset = '-11 months';
                $timeOffset = date('Y-m-01', strtotime($timeOffset)); // Snap to start of month
                $unit = 'month';
                break;
            case 'all':
                $timeOffset = '1970-01-01';
                $unit = 'month';
                break;
        }

        if (in_array($this->defaultPeriod, ['6m', '12m', 'all', 'this_year'])) {
            // For month views, start at the exact start of the day
            $startAt = strtotime(is_string($timeOffset) ? $timeOffset . ' 00:00:00' : '00:00:00') * 1000;
        } else {
            // For day views, start at midnight of the calculated offset
            $startAt = strtotime(date('Y-m-d 00:00:00', strtotime($timeOffset))) * 1000;
        }

        $pageviews = \szenario\craftumamiis\UmamiIs::getInstance()->client->getPageviews($startAt, $endAt, $unit);

        return Craft::$app->getView()->renderTemplate(
            'umami-is/_widget',
            [
                'pageviews' => $pageviews,
                'defaultPeriod' => $this->defaultPeriod,
            ]
        );
    }
}
