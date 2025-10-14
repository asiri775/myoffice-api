<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Coupon extends Model
{
    protected $table = 'bravo_coupons';

    protected $casts = [
        'services' => 'array',   
    ];

    /**
     * Apply or remove a coupon for a Booking.
     * $action: 'add' | 'remove'
     */
    public function applyCoupon(Booking $booking, string $action = 'add'): array
    {
        $res = $this->applyCouponValidate($booking, $action);
        if ($res !== true) {
            return $res; // ['status'=>0,'message'=>...,'type'=>?]
        }

        if ($action === 'add') {
            $this->add($booking);
        } else {
            $this->remove($booking);
        }

        // Recalculate booking totals in mobile:
        $this->reloadMobileBookingTotals($booking);

        return ['status' => 1, 'message' => __('Coupon code is applied successfully!')];
    }

    public function applyCouponValidate(Booking $booking, string $action)
    {
        if ($action === 'remove') {
            return true;
        }

        // Already attached?
        $dup = CouponBookings::where('coupon_code', $this->code)
            ->where('booking_id', $booking->id)->exists();
        if ($dup) {
            return ['status' => 0, 'message' => __('Coupon code is added already!')];
        }

        // Expiry
        if (!empty($this->end_date)) {
            $today = strtotime('today');
            if (strtotime($this->end_date) < $today) {
                return ['status' => 0, 'message' => __('This coupon code has expired!')];
            }
        }

        // Service restrictions
        if (!empty($this->services)) {
            // You use a pivot `coupon_services` in web; in mobile, validate minimal:
            // If you actually have a pivot table, check it; otherwise accept all hotel bookings:
            if (!($booking->object_model === 'hotel')) {
                return ['status' => 0, 'type' => 'invalid_space', 'message' => __('Coupon code is not applied to this product!')];
            }
        }

        // Only for user
        if (!empty($this->only_for_user)) {
            $uid = Auth::id();
            if (!$uid) {
                return ['status' => 0, 'message' => __('You need to log in to use the coupon code!')];
            }
            if ((int)$uid !== (int)$this->only_for_user) {
                return ['status' => 0, 'message' => __('Coupon code is not applied to your account!')];
            }
        }

        // Quantity limit (total uses, excluding draft/unpaid/cancelled)
        if (!empty($this->quantity_limit)) {
            $count = CouponBookings::where('coupon_code', $this->code)
                ->whereNotIn('booking_status', ['draft','unpaid','cancelled'])
                ->count();
            if ($this->quantity_limit <= $count) {
                return ['status' => 0, 'message' => __('This coupon code has been used up!')];
            }
        }

        // Per-user limit
        if (!empty($this->limit_per_user)) {
            $uid = Auth::id();
            if (!$uid) {
                return ['status' => 0, 'message' => __('You need to log in to use the coupon code!')];
            }
            $count = CouponBookings::where('coupon_code', $this->code)
                ->where('create_user', $uid)                 // if column exists; else remove this filter
                ->whereNotIn('booking_status', ['draft','unpaid','cancelled'])
                ->count();
            if ($this->limit_per_user <= $count) {
                return ['status' => 0, 'message' => __('This coupon code has been used up!')];
            }
        }

        return true;
    }

    public function add(Booking $booking): void
    {
        // Amount: fixed or percent
        $couponAmount = (float)$this->amount;
        if ($this->discount_type === 'percent') {
            // Mobile booking has no `total_before_discount`; use current total as base
            $couponAmount = max(0, (float)$booking->total) * ((float)$this->amount / 100);
        }

        // Remove any old coupon rows for this booking (single coupon policy)
        CouponBookings::where('booking_id', $booking->id)->delete();

        CouponBookings::create([
            'booking_id'     => $booking->id,
            'booking_status' => $booking->status,
            'object_id'      => $booking->object_id,
            'object_model'   => $booking->object_model,
            'coupon_code'    => $this->code,
            'coupon_amount'  => $couponAmount,
            'coupon_data'    => $this->toArray(),
            // 'create_user'  => Auth::id(), // keep only if column exists
        ]);
    }

    public function remove(Booking $booking): void
    {
        CouponBookings::where('coupon_code', $this->code)
            ->where('booking_id', $booking->id)
            ->delete();
    }

    /**
     * Minimal mobile re-calc:
     * - subtract sum(coupon_amount) from booking.total (floor at 0)
     * - store snapshot in meta (optional)
     */
    protected function reloadMobileBookingTotals(Booking $booking): void
    {
        $sum = CouponBookings::where('booking_id', $booking->id)->sum('coupon_amount');

        $newTotal = max(0, (float)$booking->total - (float)$sum);
        $booking->total = $newTotal;
        $booking->save();

        if (method_exists($booking, 'addMeta')) {
            $booking->addMeta('coupon_summary', [
                'codes' => CouponBookings::where('booking_id', $booking->id)
                            ->pluck('coupon_code')->values(),
                'total_discount' => (float)$sum,
            ]);
        }
    }
}
