<?php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Coupon;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CouponController extends Controller
{
    use ApiResponse;

    public function apply(Request $r)
    {
        $v = Validator::make($r->all(), [
            'booking_code' => ['required','string'],
            'code'         => ['required','string'],
            'action'       => ['sometimes','in:add,remove'],
        ]);

        if ($v->fails()) {
            return $this->fail($v->errors(), 'Validation error'); // 422
        }

        $action  = $r->input('action','add');
        $booking = Booking::where('code', $r->input('booking_code'))->first();
        if (!$booking) return $this->notFound('Booking not found');

        $coupon  = Coupon::where('code', $r->input('code'))->first();
        if (!$coupon) return $this->notFound('Coupon not found');

        $res = $coupon->applyCoupon($booking, $action);

        if (($res['status'] ?? 0) === 1) {
            return $this->ok([
                'booking_code' => $booking->code,
                'total'        => $booking->total,
            ], $res['message'] ?? 'OK');
        }

        return $this->fail(['coupon' => [$res['message'] ?? 'Cannot apply coupon']], 'Validation error');
    }
}