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
    public static function displayName(): string
    {
        return "";
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

        return Craft::$app->getView()->renderTemplate('umami-is/_widget');
    }
}
