<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\CoreSetting;

final class Settings
{
    /**
     * Minimal wrapper; feel free to add caching later.
     */
    public static function get(string $name, ?string $group = null, $default = null)
    {
        return CoreSetting::get($name, $group, $default);
    }
}
