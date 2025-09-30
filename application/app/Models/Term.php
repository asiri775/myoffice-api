<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    // If your table name differs, change here (often 'bravo_terms')
    protected $table = 'bravo_terms';

    protected $fillable = ['name','slug','attr_id','status'];

    public function spaceTerms()
    {
        return $this->hasMany(SpaceTerm::class, 'term_id');
    }
}