<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;

class ObservatoryHeatmapWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Observatory Traffic Patterns';
    }

    public static function isSelectable(): bool
    {
        return true;
    }

    public static function icon(): ?string
    {
        return 'chart-bar';
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'heatmap',
            'props' => [],
        ]);
    }
}
