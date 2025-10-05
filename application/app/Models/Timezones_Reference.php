<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Timezones_Reference extends Model
{
    protected $table = 'timezones_reference';

    protected $fillable = [
        'time_zone',
        'tz_code',         
        'utc',
        'zone',
        'php_time_zones',
    ];

    public $timestamps = false;
}
