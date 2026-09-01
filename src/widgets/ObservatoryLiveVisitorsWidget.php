<?php

namespace szenario\craftobservatory\widgets;

use Craft;
use craft\base\Widget;
use szenario\craftobservatory\assetbundles\craftobservatorywidget\CraftObservatoryWidgetAsset;
use szenario\craftobservatory\Observatory;

/**
 * Live Visitors Widget
 *
 * Shows the current number of active visitors, refreshed once a minute.
 */
class ObservatoryLiveVisitorsWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Observatory Live Visitors';
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
        return 'signal-stream';
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 1;
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        Craft::$app->getView()->registerAssetBundle(CraftObservatoryWidgetAsset::class);

        return Craft::$app->getView()->renderTemplate('observatory/_widget-shell', [
            'widgetName' => 'live-visitors',
            'props' => [
                'locale' => Craft::$app->getLocale()->id,
            ],
        ]);
    }
}
