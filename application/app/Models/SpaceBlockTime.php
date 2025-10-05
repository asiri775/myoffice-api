<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceBlockTime extends Model
{
    protected $table = 'bravo_space_block_times';
    protected $fillable = ['bravo_space_id', 'from', 'to', 'data'];
}