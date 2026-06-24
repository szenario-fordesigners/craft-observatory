<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;

class ObservatoryWorldMapWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Observatory World Map';
    }

    public static function isSelectable(): bool
    {
        return true;
    }

    public static function icon(): ?string
    {
        return 'globe'; // using 'globe' icon, common in craft
    }

    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'world-map',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
