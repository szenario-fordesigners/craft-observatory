<?php

namespace szenario\craftumamiis\assetbundles\craftumamiiswidget;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Craft Umami IS Widget asset bundle
 */
class CraftUmamiIsWidgetAsset extends AssetBundle
{
    public $sourcePath = '@szenario/craftumamiis/assetbundles/craftumamiiswidget/dist';
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
