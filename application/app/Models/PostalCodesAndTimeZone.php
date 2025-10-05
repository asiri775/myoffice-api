<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PostalCodesAndTimeZone extends Model
{
    protected $table = 'postal_codes_and_time_zone';

    protected $fillable = [
        'postalcode',
        'city',
        'province_abbr',
        'timezone',
        'latitude',
        'longitude',      
    ];

    public $timestamps = false;
}
