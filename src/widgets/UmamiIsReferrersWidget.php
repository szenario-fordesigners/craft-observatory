<?php

namespace szenario\craftumamiis\widgets;

use craft\base\Widget;
use szenario\craftumamiis\assetbundles\craftumamiiswidget\CraftUmamiIsWidgetAsset;
use szenario\craftumamiis\UmamiIs;

/**
 * Referrers Widget
 */
class UmamiIsReferrersWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Umami.is Referrers';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'share';
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
        \Craft::$app->getView()->registerAssetBundle(CraftUmamiIsWidgetAsset::class);

        return \Craft::$app->getView()->renderTemplate('umami-is/_widget-shell', [
            'widgetName' => 'referrers',
            'props' => [],
        ]);
    }
}
