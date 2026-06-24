<?php

namespace szenario\craftobservatory\assetbundles\craftobservatorywidget;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Craft Observatory Widget asset bundle
 */
class CraftObservatoryWidgetAsset extends AssetBundle
{
    public $sourcePath = '@szenario/craftobservatory/assetbundles/craftobservatorywidget/dist';
    public $depends = [
        CpAsset::class,
    ];
    public $js = [
        'widget.js',
    ];
    public $css = [
        'frontend.css',
    ];
}
