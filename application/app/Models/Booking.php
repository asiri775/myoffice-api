<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bravo_bookings';

    protected $fillable = [
        'code','object_id','object_model','vendor_id','customer_id',
        'status','total','total_before_fees','total_before_tax','total_before_discount',
        'buyer_fees','vendor_service_fee','vendor_service_fee_amount',
        'start_date','end_date','total_guests','host_amount','paid'
    ];

    protected $casts = [
        'buyer_fees' => 'array',
        'vendor_service_fee' => 'array',
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
        'total'      => 'float',
        'host_amount'=> 'float',
    ];

    public function space()
    {
        return $this->belongsTo(Space::class, 'object_id')
                    ->where('object_model','space');
    }

    public function scopeAccepted($q)
    {
        // Adjust the excluded statuses if your app uses different constants
        return $q->whereNotIn('status', ['draft','failed','pending']);
    }
}
