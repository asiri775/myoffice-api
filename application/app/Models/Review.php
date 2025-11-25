<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $table = 'bravo_review';

    protected $fillable = [
        'object_id','object_model','title','content','rate_number',
        'author_ip','status','vendor_id','reference_id','review_by','review_to','create_user'
    ];

    protected $casts = [
        'rate_number' => 'float',
    ];

    public function space()
    {
        return $this->belongsTo(Space::class, 'object_id');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }
}
