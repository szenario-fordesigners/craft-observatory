<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;

class ObservatoryVisitorsWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Observatory Visitors';
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
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'visitors',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
