<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\Request;

class Hotel extends Bookable
{
    protected $table = 'bravo_hotels';
    public string $type = 'hotel';

    protected $casts = [
        'service_fee' => 'array',
    ];

    public function addToCart(Request $request)
    {
        $this->addToCartValidate($request);

        $startAt = $request->input('start_at');
        $endAt   = $request->input('end_at');

        if (!$startAt || !$endAt) {
            return response()->json(['status' => 'error', 'message' => 'Missing booking dates'], 422);
        }

        $days = max(1, ceil((strtotime($endAt) - strtotime($startAt)) / 86400));
        $rate = (float)($this->discounted_daily ?? $this->price ?? 0);
        $total = $days * $rate;

        $booking = new Booking([
            'status' => 'draft',
            'object_id' => $this->id,
            'object_model' => 'hotel',
            'vendor_id' => $this->create_user ?? 0,
            'customer_id' => auth()->id(),
            'total' => $total,
            'start_date' => $startAt,
            'end_date' => $endAt,
        ]);

        $booking->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Hotel added to cart',
            'data' => [
                'booking_code' => $booking->code,
                'total' => $total,
                'url' => $booking->getCheckoutUrl($request->input('platform')),
            ],
        ]);
    }
}
