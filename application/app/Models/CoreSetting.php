<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}