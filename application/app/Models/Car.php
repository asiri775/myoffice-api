<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\Request;

class Car extends Bookable
{
    protected $table = 'bravo_cars';
    public string $type = 'car';

    protected $casts = [
        'extra_price' => 'array',
        'service_fee' => 'array',
    ];

    public function addToCart(Request $request)
    {
        $this->addToCartValidate($request);

        $startAt = $request->input('start_at');
        $endAt   = $request->input('end_at');
        if (!$startAt || !$endAt) {
            return response()->json(['status' => 'error', 'message' => 'Start and end times required'], 422);
        }

        $hours = max(1, ceil((strtotime($endAt) - strtotime($startAt)) / 3600));
        $hourRate = (float)($this->discounted_hourly ?? $this->hourly ?? 0);
        $dayRate  = (float)($this->discounted_daily ?? $this->daily ?? 0);

        $base = $hourRate > 0 ? $hours * $hourRate : ceil($hours / 24) * $dayRate;
        $base = round($base, 2);

        $booking = new Booking([
            'status' => 'draft',
            'object_id' => $this->id,
            'object_model' => 'car',
            'vendor_id' => $this->create_user ?? 0,
            'customer_id' => auth()->id(),
            'total' => $base,
            'start_date' => $startAt,
            'end_date' => $endAt,
        ]);

        $booking->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Car added to cart',
            'data' => [
                'booking_code' => $booking->code,
                'total' => $base,
                'url' => $booking->getCheckoutUrl($request->input('platform'))
            ],
        ]);
    }
}
