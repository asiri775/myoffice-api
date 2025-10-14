<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelRoomBooking extends Model
{
    protected $table = 'bravo_hotel_room_bookings';

    protected $fillable = [
        'room_id',
        'parent_id',   // hotel id
        'start_date',
        'end_date',
        'number',      // qty selected
        'booking_id',
        'price',       // per-night rate captured at time of booking
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
