<?php

namespace szenario\craftumamiis\models;

use craft\base\Model;

/**
 * umami settings
 */
class Settings extends Model
{
    public $umamiUrl = 'https://api.umami.is';
    public $umamiApiKey = '';
    public $umamiWebsiteId = '';

    public function rules(): array
    {
        return [
            [['umamiUrl', 'umamiApiKey', 'umamiWebsiteId'], 'required'],
        ];
    }
}
