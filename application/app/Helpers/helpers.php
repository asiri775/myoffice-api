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

if (!function_exists('get_bookable_services')) {
    function get_bookable_services(): array {
        return [
            'space' => \App\Models\Space::class,
            'car'   => \App\Models\Car::class,
            'hotel' => \App\Models\Hotel::class,
            'event' => \App\Models\Event::class,
            'flight'=> \App\Models\Flight::class,
        ];
    }
}