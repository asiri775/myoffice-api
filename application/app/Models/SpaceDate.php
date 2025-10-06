<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceDate extends Model
{
    protected $table = 'bravo_space_dates'; // match your table
    protected $guarded = [];
    public $timestamps = true;
}
