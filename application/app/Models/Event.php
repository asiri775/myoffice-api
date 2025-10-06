<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\Request;

class Event extends Bookable
{
    protected $table = 'bravo_events';
    public string $type = 'event';

    protected $casts = [
        'service_fee' => 'array',
    ];

    public function addToCart(Request $request)
    {
        $this->addToCartValidate($request);

        $qty  = (int)$request->input('ticket_qty', 1);
        $rate = (float)($this->sale_price ?? $this->price ?? 0);
        $total = $qty * $rate;

        $booking = new Booking([
            'status' => 'draft',
            'object_id' => $this->id,
            'object_model' => 'event',
            'vendor_id' => $this->create_user ?? 0,
            'customer_id' => auth()->id(),
            'total' => $total,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]);

        $booking->addMeta('tickets', $qty);
        $booking->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Event ticket added to cart',
            'data' => [
                'booking_code' => $booking->code,
                'total' => $total,
                'url' => $booking->getCheckoutUrl($request->input('platform')),
            ],
        ]);
    }
}