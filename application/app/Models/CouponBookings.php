<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponBookings extends Model
{
    protected $table = 'bravo_booking_coupons';   // ✅ same table
    protected $fillable = [
        'booking_id','booking_status','object_id','object_model',
        'coupon_code','coupon_amount','coupon_data',
        // 'create_user', // only if the column exists
    ];
    protected $casts = [
        'coupon_data'   => 'array',
        'coupon_amount' => 'float',
    ];
}
