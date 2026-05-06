<?php

namespace szenario\craftumamiis\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;

class UmamiIsWorldMapWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Umami.is World Map';
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
        Craft::$app->getView()->registerAssetBundle(CraftUmamiIsWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('umami-is/_widget-shell', [
            'widgetName' => 'world-map',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
