<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;
use szenario\craftobservatory\Observatory;

/**
 * Referrers Widget
 */
class ObservatoryReferrersWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Observatory Referrers';
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Observatory::getInstance()->userCanAccessCp();
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
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'referrers',
            'props' => [],
        ]);
    }
}
