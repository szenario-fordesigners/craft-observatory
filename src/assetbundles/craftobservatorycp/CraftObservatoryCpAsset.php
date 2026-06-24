<?php

namespace szenario\craftobservatory\assetbundles\craftobservatorycp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Craft Observatory CP asset bundle
 */
class CraftObservatoryCpAsset extends AssetBundle
{
    public $sourcePath = '@szenario/craftobservatory/assetbundles/craftobservatorycp/dist';
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
