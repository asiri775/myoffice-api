<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class CoreSetting extends Model
{
    protected $table = 'core_settings';
    public $timestamps = false;
    protected $guarded = [];

    public static function get(string $name, ?string $group = null, $default = null)
    {
        $q = static::query()->where('name', $name);
        if ($group !== null) {
            $q->where('group', $group);
        }
        $row = $q->first();
        return $row ? $row->val : $default;
    }


    public static function item($item, $default = false)
    {
        $value = Cache::rememberForever('setting_' . $item, function () use ($item ,$default) {
            $val = CoreSetting::where('name', $item)->first();
            return ($val and $val['val'] != null) ? $val['val'] : $default;
        });
        return $value;
    }
}