<?php

namespace szenario\craftumamiis\assetbundles\craftumamiiscp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Craft Umami IS CP asset bundle
 */
class CraftUmamiIsCpAsset extends AssetBundle
{
    public $sourcePath = '@szenario/craftumamiis/assetbundles/craftumamiiscp/dist';
    public $depends = [
        CpAsset::class,
    ];
    public $js = [
        'cp.js',
    ];
    public $css = [
        'frontend.css',
    ];
}
