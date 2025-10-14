<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'bravo_booking_payments';

    protected $fillable = [
        'booking_id',
        'user_id',
        'status',           // draft | completed | fail | cancel
        'payment_gateway',
        'code',
        'amount',
        'credit',
        'meta',
        'logs',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function getTableName(): string
    {
        return (new static())->getTable();
    }

    public function booking()
    {
        // Update namespace if your Booking lives elsewhere
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}