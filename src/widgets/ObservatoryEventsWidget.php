<?php

namespace szenario\craftobservatory\widgets;

use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;
use szenario\craftobservatory\Observatory;
use Craft;

/**
 * Top Events Widget
 */
class ObservatoryEventsWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Observatory Top Events';
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
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'events',
            'props' => [],
        ]);
    }
}
