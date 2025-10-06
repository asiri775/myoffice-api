<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\Request;

class Flight extends Bookable
{
    protected $table = 'bravo_flights';
    public string $type = 'flight';

    protected $casts = [
        'service_fee' => 'array',
    ];

    public function addToCart(Request $request)
    {
        $this->addToCartValidate($request);

        $passengers = (int)$request->input('passengers', 1);
        $base = (float)($this->sale_price ?? $this->price ?? 0);
        $total = round($passengers * $base, 2);

        $booking = new Booking([
            'status' => 'draft',
            'object_id' => $this->id,
            'object_model' => 'flight',
            'vendor_id' => $this->create_user ?? 0,
            'customer_id' => auth()->id(),
            'total' => $total,
            'start_date' => $this->departure_time ?? $request->input('start_at'),
            'end_date' => $this->arrival_time ?? $request->input('end_at'),
        ]);

        $booking->addMeta('passengers', $passengers);
        $booking->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Flight added to cart',
            'data' => [
                'booking_code' => $booking->code,
                'total' => $total,
                'url' => $booking->getCheckoutUrl($request->input('platform')),
            ],
        ]);
    }
}