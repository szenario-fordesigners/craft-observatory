<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;
use szenario\craftobservatory\Observatory;

/**
 * Usage Widget
 *
 * Shows 7-day views and average visit duration with a daily views bar chart.
 */
class ObservatoryUsageWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Observatory Usage';
    }

    public static function isSelectable(): bool
    {
        return Observatory::getInstance()->userCanAccessCp();
    }

    public static function icon(): ?string
    {
        return 'chart-column';
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'usage',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
