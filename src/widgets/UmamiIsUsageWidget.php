<?php

namespace szenario\craftumamiis\widgets;

use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;
use Craft;

/**
 * Usage Widget
 *
 * Shows 7-day views and average visit duration with a daily views bar chart.
 */
class UmamiIsUsageWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Umami.is Usage';
    }

    public static function isSelectable(): bool
    {
        return true;
    }

    public static function icon(): ?string
    {
        return 'chart-column';
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftUmamiIsWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('umami-is/_widget-shell', [
            'widgetName' => 'usage',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
