<?php

use App\Models\CoreSetting;

if (! function_exists('setting_item')) {
    function setting_item($item, $default = '', $isArray = false)
    {
        $res = CoreSetting::item($item, $default);

        if ($isArray && ! is_array($res)) {
            $res = (array) json_decode($res, true);
        }

        return $res;
    }
}