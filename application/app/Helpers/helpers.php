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



if (!function_exists('random_code')) {
    /**
     * Simple secure random uppercase string, default 10 chars.
     */
    function random_code(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // skip confusing chars
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }



if (!function_exists('format_price')) {
    function format_price($value, $zeroIfNull = false)
    {
        if ($value === null || $value === '') {
            if ($zeroIfNull) {
                $value = 0;
            } else {
                return '-';
            }
        }
        return '$' . number_format((float)$value, 2);
    }
}

if (!function_exists('format_number')) {
    function format_number($value, $zeroIfNull = false)
    {
        if ($value === null || $value === '') {
            return $zeroIfNull ? 0 : '-';
        }
        return $value;
    }
}

}
