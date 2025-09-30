<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpaceTerm extends Model
{
    protected $table = 'bravo_space_term';
    public $timestamps = false;

    protected $fillable = ['term_id','target_id'];

    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    public function space()
    {
        return $this->belongsTo(Space::class, 'target_id');
    }
}
