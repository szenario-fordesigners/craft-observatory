<?php

namespace szenario\craftumamiis\widgets;

use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;
use Craft;

/**
 * Top Events Widget
 */
class UmamiIsEventsWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Umami.is Top Events';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'bolt';
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 3;
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftUmamiIsWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('umami-is/_widget-shell', [
            'widgetName' => 'events',
            'props' => [],
        ]);
    }
}
