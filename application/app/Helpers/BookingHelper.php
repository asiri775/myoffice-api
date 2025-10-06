<?php
declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;


final class BookingHelper
{
    /** Parse m/d/Y → Y-m-d (string|false) */
    public static function parseUsDate(?string $mdY)
    {
        if (!$mdY) return false;
        try {
            return Carbon::createFromFormat('m/d/Y', $mdY)->format('Y-m-d');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Normalize to a valid PHP timezone or fallback to UTC */
    public static function resolveTimezone(?string $tz): string
    {
        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /** Diff in whole hours (ceil by minute > 0) */
    public static function getHoursBetweenDates(string $start, string $end): int
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        $mins = $s->diffInMinutes($e);
        // treat partial hour as full hour for billing
        return (int) ceil($mins / 60);
    }

    /** Currency formatting (customize as you like) */
    public static function formatPrice(float $amount, string $currency = '$'): string
    {
        return $currency . number_format($amount, 2);
    }

    
    public static function getSpacePrice($space, $from, $to, $bookingId = null)
    {
        $items = [];
        $price = 0;

        if ($space == null) {
            // empty shell with zeros (same shape as before)
            return [
                'items' => [],
                'extraFeeList' => [],
                'guestFeeList' => [],
                'hostFeeList' => [],
                'timeInfo' => ['hours' => 0,'days' => 0,'weeks' => 0,'months' => 0],
                'price' => 0,
                'extraFee' => 0,
                'priceAfterExtraFee' => 0,
                'guestFee' => 0,
                'priceAfterGuestFee' => 0,
                'tax' => 0,
                'priceAfterTax' => 0,
                'couponType' => 0,
                'discount' => 0,
                'payableAmount' => 0,
                'hostFee' => 0,
                'adminAmount' => 0,
                'hostAmount' => 0,
                'rentalTotal' => 0,
                'subTotal' => 0,
                'total' => 0,
                'grandTotal' => 0,
            ];
        }

        // ---------- TIME BREAKDOWN ----------
        // total hours (ceil minutes to next hour so 10:00:01→17:00:00 next day = 31)
        $totalMinutes = max(0, (strtotime($to) - strtotime($from)));
        $totalHours   = (int) ceil($totalMinutes / 60);

        // "billing day" = how many hours count as one billable day
        // set this to 10 on spaces where you want 10h/day behavior
        $billingDay = (int) trim($space->hours_after_full_day ?? 24);
        if ($billingDay <= 0) $billingDay = 24;

        // month = 28 calendar days; week = 7 calendar days
        $HOURS_PER_WEEK  = 7 * 24;
        $HOURS_PER_MONTH = 28 * 24;

        // ---------- RATES (prefer discounted, fallback to base) ----------
        $monthlyPrice = self::getNumValueOrDefault($space->discounted_monthly, $space->monthly);
        $weekPrice    = self::getNumValueOrDefault($space->discounted_weekly,  $space->weekly);
        $dayPrice     = self::getNumValueOrDefault($space->discounted_daily,   $space->daily);
        $hourPrice    = self::getNumValueOrDefault($space->discounted_hourly,  $space->hourly);

        // If nothing is set, fall back to listing price as a day rate
        if (!self::checkIfNumValNotNull($monthlyPrice) &&
            !self::checkIfNumValNotNull($weekPrice) &&
            !self::checkIfNumValNotNull($dayPrice) &&
            !self::checkIfNumValNotNull($hourPrice)) {
            $listingPrice = self::getNumValueOrDefault($space->sale_price, $space->price);
            $dayPrice = $listingPrice;
        }

        // ---------- GREEDY BREAKDOWN ----------
        // months & weeks are in *calendar* hours; days are in *billingDay* hours.
        $rem = $totalHours;

        $months = 0; $weeks = 0; $days = 0; $hours = 0;

        if (self::checkIfNumValNotNull($monthlyPrice) && $rem >= $HOURS_PER_MONTH) {
            $months = intdiv($rem, $HOURS_PER_MONTH);
            $rem   -= $months * $HOURS_PER_MONTH;
            $items[] = ['type'=>'months','quantity'=>$months,'rate'=>$monthlyPrice,'total'=>$months * $monthlyPrice];
        }

        if (self::checkIfNumValNotNull($weekPrice) && $rem >= $HOURS_PER_WEEK) {
            $weeks = intdiv($rem, $HOURS_PER_WEEK);
            $rem  -= $weeks * $HOURS_PER_WEEK;
            $items[] = ['type'=>'weeks','quantity'=>$weeks,'rate'=>$weekPrice,'total'=>$weeks * $weekPrice];
        }

        if (self::checkIfNumValNotNull($dayPrice) && $rem >= $billingDay) {
            $days = intdiv($rem, $billingDay);
            $rem -= $days * $billingDay;
            $items[] = ['type'=>'days','quantity'=>$days,'rate'=>$dayPrice,'total'=>$days * $dayPrice];
        }

        // remainder in hours — if no hourly price, bump one more day if available
        if ($rem > 0) {
            if (self::checkIfNumValNotNull($hourPrice)) {
                $hours = $rem;
                $items[] = ['type'=>'hours','quantity'=>$hours,'rate'=>$hourPrice,'total'=>$hours * $hourPrice];
                $rem = 0;
            } elseif (self::checkIfNumValNotNull($dayPrice)) {
                $days += 1;
                // merge with last days line if present
                $merged = false;
                if (!empty($items) && end($items)['type']==='days') {
                    $idx = count($items)-1;
                    $items[$idx]['quantity'] += 1;
                    $items[$idx]['total']     = $items[$idx]['quantity'] * $dayPrice;
                    $merged = true;
                }
                if (!$merged) {
                    $items[] = ['type'=>'days','quantity'=>1,'rate'=>$dayPrice,'total'=>$dayPrice];
                }
                $rem = 0;
            }
        }

        // Guard: if no items got added (e.g., no rates), return zeros
        if (empty($items)) {
            return [
                'items' => [],
                'extraFeeList' => [],
                'guestFeeList' => [],
                'hostFeeList' => [],
                'timeInfo' => ['hours' => 0,'days' => 0,'weeks' => 0,'months' => 0],
                'price' => 0,
                'extraFee' => 0,
                'priceAfterExtraFee' => 0,
                'guestFee' => 0,
                'priceAfterGuestFee' => 0,
                'tax' => 0,
                'priceAfterTax' => 0,
                'couponType' => 0,
                'discount' => 0,
                'payableAmount' => 0,
                'hostFee' => 0,
                'adminAmount' => 0,
                'hostAmount' => 0,
                'rentalTotal' => 0,
                'subTotal' => 0,
                'total' => 0,
                'grandTotal' => 0,
            ];
        }

        // ---------- BASE RENTAL TOTAL ----------
        $price = array_reduce($items, fn($s,$i)=>$s + ($i['total'] ?? 0), 0.0);

        // ---------- FEES (keep your current logic) ----------
        $extraFee = 0;
        $extraFeeList = [];
        $guestFee = 0;
        $guestFeeList = [];
        $hostFee  = 0;
        $hostFeeList = [];

        // Extra prices configured on space
        $totalDaysForExtras  = $months*28*1 + $weeks*7 + $days; // extras usually use calendar days
        $totalHoursForExtras = $totalHours;

        $extraPrices = $space->extra_price;
        if (is_array($extraPrices) && count($extraPrices) > 0) {
            foreach ($extraPrices as $extraPriceItem) {
                $row = 0;
                switch ($extraPriceItem['type']) {
                    case 'one_time':
                        if ($extraPriceItem['price'] != null) $row = $extraPriceItem['price'];
                        break;
                    case 'per_hour':
                        if ($extraPriceItem['price'] != null) $row = ($totalHoursForExtras * $extraPriceItem['price']);
                        break;
                    case 'per_day':
                        if ($extraPriceItem['price'] != null) $row = ($totalDaysForExtras * $extraPriceItem['price']);
                        break;
                }
                $extraFee += $row;
                $tmp = $extraPriceItem;
                $tmp['totalAmount'] = $row;
                $extraFeeList[] = $tmp;
            }
        }

        $priceAfterExtraFee = $price + $extraFee;

        // Guest fees (buyer)
        $guestFees = json_decode(setting_item('space_booking_buyer_fees'), true);
        if (is_array($guestFees) && count($guestFees) > 0) {
            foreach ($guestFees as $gf) {
                $row = 0;
                if (($gf['unit'] ?? '') === 'fixed') {
                    $row = ($gf['price'] ?? 0) * 1;
                } elseif (($gf['unit'] ?? '') === 'percent') {
                    $row = (($gf['price'] ?? 0) * $priceAfterExtraFee) / 100;
                }
                $guestFee += $row;
                $info = $gf;
                $info['totalAmount'] = $row;
                $guestFeeList[] = $info;
            }
        }

        $priceAfterGuestFee = $priceAfterExtraFee + $guestFee;

        // Tax
        $tax = ($priceAfterGuestFee * Constants::TAX_PERCENT);
        $priceAfterTax = $priceAfterGuestFee + $tax;

        // Host fee (seller) – on priceAfterExtraFee
        $hostFees = json_decode(setting_item('space_booking_seller_fees'), true);
        if (is_array($hostFees) && count($hostFees) > 0) {
            foreach ($hostFees as $hf) {
                $row = 0;
                if (($hf['unit'] ?? '') === 'fixed') {
                    $row = ($hf['price'] ?? 0) * 1;
                } elseif (($hf['unit'] ?? '') === 'percent') {
                    $row = (($hf['price'] ?? 0) * $priceAfterExtraFee) / 100;
                }
                $hostFee += $row;
                $info = $hf;
                $info['totalAmount'] = $row;
                $hostFeeList[] = $info;
            }
        }

        // Coupons (unchanged)
        $coupon_type = "global";
        $discount = 0;
        if ($bookingId != null) {
            $bookingCoupons = \Modules\Coupon\Models\CouponBookings::where('booking_id', $bookingId)->get();
            if ($bookingCoupons != null) {
                foreach ($bookingCoupons as $bookingCoupon) {
                    $couponData = ($bookingCoupon['coupon_data']);
                    if ($couponData['object_id'] != null) $coupon_type = "space";
                    $off = 0;
                    if ($couponData['discount_type'] == "fixed") {
                        $off = $couponData['amount'];
                    } elseif ($couponData['discount_type'] == "percent") {
                        $off = ($priceAfterExtraFee * $couponData['amount']) / 100;
                    }
                    $bookingCoupon->coupon_amount = $off;
                    $bookingCoupon->save();
                    $discount += $off;
                }
            }
        }

        $payableAmount = $priceAfterTax; // before discount
        $adminAmount   = $hostFee + $tax + $guestFee;
        $hostAmount    = $payableAmount - $adminAmount;

        if ($discount > $payableAmount) $discount = $payableAmount;
        $payableAmount = $payableAmount - $discount;

        if ($coupon_type == "global") {
            $adminAmount -= $discount;
        } else {
            $hostAmount  -= $discount;
        }

        // Summary fields (same as your original keys, but **no HTML**)
        $priceDetails = [
            'items' => $items,
            'extraFeeList' => $extraFeeList,
            'guestFeeList' => $guestFeeList,
            'hostFeeList' => $hostFeeList,
            'timeInfo' => [
                'hours'  => $hours,
                'days'   => $days,
                'weeks'  => $weeks,
                'months' => $months,
            ],
            'price' => $price,                          // rental base
            'extraFee' => $extraFee,
            'priceAfterExtraFee' => $priceAfterExtraFee,
            'guestFee' => $guestFee,
            'priceAfterGuestFee' => $priceAfterGuestFee,
            'tax' => $tax,
            'priceAfterTax' => $priceAfterTax,
            'couponType' => $coupon_type === 'global' ? 0 : 1,
            'discount' => $discount,
            'payableAmount' => $payableAmount,
            'hostFee' => $hostFee,
            'adminAmount' => $adminAmount,
            'hostAmount' => $hostAmount,
        ];

        // Convenience totals (kept for compatibility)
        $priceDetails['rentalTotal'] = $priceDetails['price'] + $priceDetails['extraFee'];
        $priceDetails['subTotal']    = $priceDetails['rentalTotal'] + $priceDetails['guestFee'];
        $priceDetails['total']       = $priceDetails['subTotal'] + $priceDetails['tax'];
        $priceDetails['grandTotal']  = $priceDetails['total'] - $priceDetails['discount'];

        return $priceDetails;
    }

    /** Cast numeric or return 0 */
    private static function num($v): float
    {
        if ($v === null || $v === '' || !is_numeric($v)) return 0.0;
        return (float) $v;
    }
}
