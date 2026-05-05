<?php

namespace szenario\craftumamiis\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;

class UmamiIsSummaryWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Umami.is Summary';
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

        return Craft::$app->getView()->renderTemplate('umami-is/_widget-shell', [
            'widgetName' => 'summary',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
