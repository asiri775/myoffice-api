<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddToFavourite extends Model
{
    protected $table = 'add_to_favourites';

    protected $fillable = ['user_id', 'object_id'];

    public function space()
    {

        return $this->belongsTo(Space::class, 'object_id');
    }

    public function user()
    {

        return $this->belongsTo(Users::class, 'user_id');
    }
}