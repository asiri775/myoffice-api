<?php

use App\Models\CoreSetting;

function setting_item($item, $default = '', $isArray = false)
{

    $res = CoreSetting::item($item, $default);

    if ($isArray and !is_array($res)) {
        $res = (array) json_decode($res, true);
    }

    return $res;

}

