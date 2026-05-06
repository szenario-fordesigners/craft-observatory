<?php

namespace szenario\craftumamiis\widgets;

use craft\base\Widget;

/**
 * Legacy widget class to prevent Craft CMS from crashing if this widget
 * is still saved in the user's dashboard database.
 * 
 * Please remove this widget from your Craft Dashboard and then this file can be deleted.
 */
class UmamiIsWidget extends Widget
{
    public static function displayName(): string
    {
        return 'Umami.is (Legacy - Please Remove)';
    }

    public function getBodyHtml(): ?string
    {
        return '<div style="padding: 20px; color: red;">This is an old widget that has been replaced. Please delete this widget from your dashboard and add the new "Umami.is Summary", "World Map", or "Referrers" widgets instead.</div>';
    }
}
