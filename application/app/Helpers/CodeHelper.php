<?php
namespace App\Helpers;

use App\Models\Booking;
use App\Models\CouponBookings;
use App\Models\Space;

class CodeHelper
{
    /**
     * Return the full pricing breakdown for a booking
     * using your existing getSpacePrice(...) engine.
     */
    public static function getBookingPriceInfo(Booking $booking): array
    {
        $space = Space::find($booking->object_id);

        // You already have getSpacePrice in web; reuse the same signature here.
        // getSpacePrice(Space $space, string $start, string $end, int|null $bookingId)
        return self::getSpacePrice(
            $space,
            (string) $booking->start_date,
            (string) $booking->end_date,
            $booking->id
        );
    }

    /**
     * Copy pricing fields/line-items onto the booking model.
     * NO URLs, just numbers + JSON items.
     */
    public static function assignSpacePricingToBooking(Booking $booking, array $totalInfo): Booking
    {
        // Line items
        $booking->items            = json_encode($totalInfo['items']          ?? []);
        $booking->extra_fee_items  = json_encode($totalInfo['extraFeeList']   ?? []);
        $booking->guest_fee_items  = json_encode($totalInfo['guestFeeList']   ?? []);
        $booking->host_fee_items   = json_encode($totalInfo['hostFeeList']    ?? []);

        // Totals
        $booking->price           = (float)($totalInfo['price']          ?? 0);
        $booking->extra_fee       = (float)($totalInfo['extraFee']       ?? 0);
        $booking->guest_fee       = (float)($totalInfo['guestFee']       ?? 0);
        $booking->tax             = (float)($totalInfo['tax']            ?? 0);
        $booking->discount        = (float)($totalInfo['discount']       ?? 0);
        $booking->payable_amount  = (float)($totalInfo['payableAmount']  ?? 0);
        $booking->total           = (float)($totalInfo['payableAmount']  ?? 0);

        // Host/admin splits (if your engine returns them)
        $booking->host_fee    = (float)($totalInfo['hostFee']    ?? 0);
        $booking->admin_amount= (float)($totalInfo['adminAmount']?? 0);
        $booking->host_amount = (float)($totalInfo['hostAmount'] ?? 0);

        return $booking;
    }


    public static function checkIfNumValNotNull($value)
    {
        if ($value != null && $value > 0) {
            return true;
        }
        return false;
    }

    public static function getNumValueOrDefault($value, $default = null)
    {
        if (self::checkIfNumValNotNull($value)) {
            return $value;
        }
        return $default;
    }

    /* ---------------------------------------------------------
       If this project doesn’t already have this method in mobile,
       keep this stub or port your web version here.
       --------------------------------------------------------- */
       public static function getSpacePrice($space, $from, $to, $bookingId = null): array
       {
           // ---- guards -------------------------------------------------------------
           if (!$space || !$from || !$to) {
               return [
                   'items'          => [],
                   'extraFeeList'   => [],
                   'guestFeeList'   => [],
                   'hostFeeList'    => [],
                   'timeInfo'       => ['hours'=>0,'days'=>0,'weeks'=>0,'months'=>0],
                   'price'          => 0,
                   'extraFee'       => 0,
                   'priceAfterExtraFee' => 0,
                   'guestFee'       => 0,
                   'priceAfterGuestFee' => 0,
                   'tax'            => 0,
                   'priceAfterTax'  => 0,
                   'couponType'     => 'global',
                   'discount'       => 0,
                   'payableAmount'  => 0,
                   'hostFee'        => 0,
                   'adminAmount'    => 0,
                   'hostAmount'     => 0,
                   'rentalTotal'    => 0,
                   'subTotal'       => 0,
                   'total'          => 0,
                   'grandTotal'     => 0,
               ];
           }

           // ---- duration decomposition (28-day months; hours rounded up by minutes) -
           $start = new \DateTime($from);
           $end   = new \DateTime($to);
           if ($end <= $start) {
               // nothing/invalid → zeroed result
               return [
                   'items'          => [],
                   'extraFeeList'   => [],
                   'guestFeeList'   => [],
                   'hostFeeList'    => [],
                   'timeInfo'       => ['hours'=>0,'days'=>0,'weeks'=>0,'months'=>0],
                   'price'          => 0,
                   'extraFee'       => 0,
                   'priceAfterExtraFee' => 0,
                   'guestFee'       => 0,
                   'priceAfterGuestFee' => 0,
                   'tax'            => 0,
                   'priceAfterTax'  => 0,
                   'couponType'     => 'global',
                   'discount'       => 0,
                   'payableAmount'  => 0,
                   'hostFee'        => 0,
                   'adminAmount'    => 0,
                   'hostAmount'     => 0,
                   'rentalTotal'    => 0,
                   'subTotal'       => 0,
                   'total'          => 0,
                   'grandTotal'     => 0,
               ];
           }

           $diffSecs = max(0, $end->getTimestamp() - $start->getTimestamp());
           $totalMinutes = (int) ceil($diffSecs / 60);       // round up to the minute
           $roundedHours = (int) ceil($totalMinutes / 60);   // round up to the hour
           $totalDaysAbs = (int) floor($roundedHours / 24);  // full days contained in total hours
           $remainderHours = $roundedHours % 24;

           // 28-day month decomposition
           $months = (int) floor($totalDaysAbs / 28);
           $remDaysAfterMonths = $totalDaysAbs % 28;
           $weeks  = (int) floor($remDaysAfterMonths / 7);
           $days   = (int) ($remDaysAfterMonths % 7);
           $hours  = (int) $remainderHours;

           // carry hours to days if they exceed allowed daily cap
           $maxHoursPerDay = trim((string) ($space->hours_after_full_day ?? ''));
           $maxHoursPerDay = $maxHoursPerDay !== '' ? (int)$maxHoursPerDay : 24;
           if ($maxHoursPerDay < 1) $maxHoursPerDay = 24;

           if ($hours >= $maxHoursPerDay) {
               $extraDays = (int) floor($hours / $maxHoursPerDay);
               $hours = $hours % $maxHoursPerDay;
               $days += $extraDays;

               // normalize again for 7-day weeks and 28-day months after carrying
               if ($days >= 7) {
                   $weeks += (int) floor($days / 7);
                   $days   = $days % 7;
               }
               if ($weeks >= 4) {
                   $months += (int) floor($weeks / 4); // 4 weeks ~ 28 days
                   $weeks   = $weeks % 4;
               }
           }

           // helpers for extra fees
           $totalDaysCount  = ($months * 28) + ($weeks * 7) + $days;
           $totalHoursCount = ($totalDaysCount * 24) + $hours;

           // ---- pricing inputs -----------------------------------------------------
           $listingPrice = self::getNumValueOrDefault($space->sale_price, $space->price); // fallback base/day price

           $monthlyPrice = self::getNumValueOrDefault($space->discounted_monthly, $space->monthly);
           $weekPrice    = self::getNumValueOrDefault($space->discounted_weekly,  $space->weekly);
           $dayPrice     = self::getNumValueOrDefault($space->discounted_daily,   $space->daily);
           $hourPrice    = self::getNumValueOrDefault($space->discounted_hourly,  $space->hourly);

           $items = [];
           $price = 0.0;

           // If all granular prices exist (hour/day/week/month) → use decomposed pricing
           if (
               self::checkIfNumValNotNull($monthlyPrice) &&
               self::checkIfNumValNotNull($weekPrice)    &&
               self::checkIfNumValNotNull($dayPrice)     &&
               self::checkIfNumValNotNull($hourPrice)
           ) {
               if ($months > 0) {
                   $row = $monthlyPrice * $months;
                   $items[] = ['type'=>'months','quantity'=>$months,'rate'=>$monthlyPrice,'total'=>$row];
                   $price += $row;
               }
               if ($weeks > 0) {
                   $row = $weekPrice * $weeks;
                   $items[] = ['type'=>'weeks','quantity'=>$weeks,'rate'=>$weekPrice,'total'=>$row];
                   $price += $row;
               }
               if ($days > 0) {
                   $row = $dayPrice * $days;
                   $items[] = ['type'=>'days','quantity'=>$days,'rate'=>$dayPrice,'total'=>$row];
                   $price += $row;
               }
               if ($hours > 0) {
                   $row = $hourPrice * $hours;
                   $items[] = ['type'=>'hours','quantity'=>$hours,'rate'=>$hourPrice,'total'=>$row];
                   $price += $row;
               }

               // up-caps: if only hours present, cap to a day; if only days present (no weeks/months) cap to a week; etc.
               if ($months === 0 && $weeks === 0 && $days === 0 && $hours > 0 && self::checkIfNumValNotNull($dayPrice)) {
                   $price = min($price, (float)$dayPrice);
               }
               if ($months === 0 && $weeks === 0 && $days > 0 && self::checkIfNumValNotNull($weekPrice)) {
                   $price = min($price, (float)$weekPrice);
               }
               if ($months === 0 && $weeks > 0 && self::checkIfNumValNotNull($monthlyPrice)) {
                   $price = min($price, (float)$monthlyPrice);
               }
           } else {
               // fallback: charge by (rounded-up) days at listingPrice
               $chargeDays = max(1, (int) ceil($diffSecs / 86400));
               $row = $listingPrice * $chargeDays;
               $items[] = ['type'=>'days','quantity'=>$chargeDays,'rate'=>$listingPrice,'total'=>$row];
               $price = $row;
           }

           // ---- extra fees (space->extra_price) -----------------------------------
           $extraFeeList = [];
           $extraFee = 0.0;
           $extraPrices = is_array($space->extra_price) ? $space->extra_price : [];

           foreach ($extraPrices as $ep) {
               if (!isset($ep['type']) || !isset($ep['price'])) continue;

               $rowTotal = 0.0;
               switch ($ep['type']) {
                   case 'one_time':
                       $rowTotal = (float) $ep['price'];
                       break;
                   case 'per_hour':
                       $rowTotal = (float) $ep['price'] * $totalHoursCount;
                       break;
                   case 'per_day':
                       $rowTotal = (float) $ep['price'] * max(1, $totalDaysCount);
                       break;
               }
               $tmp = $ep;
               $tmp['totalAmount'] = $rowTotal;
               $extraFeeList[] = $tmp;
               $extraFee += $rowTotal;
           }
           $priceAfterExtraFee = $price + $extraFee;

           // ---- guest fees (buyer fees) -------------------------------------------
           $guestFeeList = [];
           $guestFee = 0.0;
           $guestFees = json_decode(setting_item('space_booking_buyer_fees'), true);
           if (is_array($guestFees)) {
               foreach ($guestFees as $gf) {
                   if (!isset($gf['unit'], $gf['price'])) continue;
                   $row = 0.0;
                   if ($gf['unit'] === 'fixed') {
                       $row = (float)$gf['price'];
                   } elseif ($gf['unit'] === 'percent') {
                       $row = ((float)$gf['price'] * $priceAfterExtraFee) / 100;
                   }
                   $guestFee += $row;
                   $gfi = $gf;
                   $gfi['totalAmount'] = $row;
                   $guestFeeList[] = $gfi;
               }
           }
           $priceAfterGuestFee = $priceAfterExtraFee + $guestFee;

           // ---- tax ----------------------------------------------------------------
           $tax = (float) ($priceAfterGuestFee * 0.13);
           $priceAfterTax = $priceAfterGuestFee + $tax;

           // ---- host fees (seller fees) (computed on priceAfterExtraFee) ----------
           $hostFeeList = [];
           $hostFee = 0.0;
           $hostFees = json_decode(setting_item('space_booking_seller_fees'), true);
           if (is_array($hostFees)) {
               foreach ($hostFees as $hf) {
                   if (!isset($hf['unit'], $hf['price'])) continue;
                   $row = 0.0;
                   if ($hf['unit'] === 'fixed') {
                       $row = (float)$hf['price'];
                   } elseif ($hf['unit'] === 'percent') {
                       $row = ((float)$hf['price'] * $priceAfterExtraFee) / 100;
                   }
                   $hostFee += $row;
                   $hfi = $hf;
                   $hfi['totalAmount'] = $row;
                   $hostFeeList[] = $hfi;
               }
           }

           // ---- coupons (from bravo_booking_coupons) -------------------------------
           $discount = 0.0;
           $couponType = 'global'; // 'global' or 'space'
           if ($bookingId) {
               $bookingCoupons = CouponBookings::where('booking_id', $bookingId)->get();
               foreach ($bookingCoupons as $bc) {
                   $data = is_array($bc->coupon_data) ? $bc->coupon_data : (array) $bc->coupon_data;
                   if (!empty($data['object_id'])) {
                       $couponType = 'space';
                   }
                   $cut = 0.0;
                   if (($data['discount_type'] ?? '') === 'fixed') {
                       $cut = (float) ($data['amount'] ?? 0);
                   } elseif (($data['discount_type'] ?? '') === 'percent') {
                       $cut = ($priceAfterExtraFee * (float) ($data['amount'] ?? 0)) / 100;
                   }
                   // store back on row for transparency
                   $bc->coupon_amount = $cut;
                   $bc->save();

                   $discount += $cut;
               }
           }

           // cap discount at priceAfterTax
           $discount = min($discount, $priceAfterTax);

           // ---- totals -------------------------------------------------------------
           $payableAmount = max(0.0, $priceAfterTax - $discount);

           $adminAmount = $hostFee + $tax + $guestFee;
           $hostAmount  = $payableAmount - $adminAmount;

           // If coupon is global → discount hits admin; if space-scoped → host side.
           if ($discount > 0) {
               if ($couponType === 'global') {
                   $adminAmount = max(0.0, $adminAmount - $discount);
               } else {
                   $hostAmount = max(0.0, $hostAmount - $discount);
               }
           }

           $rentalTotal = $price + $extraFee;
           $subTotal    = $rentalTotal + $guestFee;
           $total       = $subTotal + $tax;
           $grandTotal  = max(0.0, $total - $discount);

           return [
               'items'               => $items,

               'extraFeeList'        => $extraFeeList,
               'guestFeeList'        => $guestFeeList,
               'hostFeeList'         => $hostFeeList,

               'timeInfo'            => [
                   'hours'  => $hours,
                   'days'   => $days,
                   'weeks'  => $weeks,
                   'months' => $months,
               ],

               'price'               => (float) $price,

               'extraFee'            => (float) $extraFee,
               'priceAfterExtraFee'  => (float) $priceAfterExtraFee,

               'guestFee'            => (float) $guestFee,
               'priceAfterGuestFee'  => (float) $priceAfterGuestFee,

               'tax'                 => (float) $tax,
               'priceAfterTax'       => (float) $priceAfterTax,

               'couponType'          => $couponType,
               'discount'            => (float) $discount,
               'payableAmount'       => (float) $payableAmount,

               'hostFee'             => (float) $hostFee,

               'adminAmount'         => (float) $adminAmount,
               'hostAmount'          => (float) $hostAmount,

               'rentalTotal'         => (float) $rentalTotal,
               'subTotal'            => (float) $subTotal,
               'total'               => (float) $total,
               'grandTotal'          => (float) $grandTotal,
           ];
       }

}