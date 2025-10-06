<?php

namespace App\Services;

use App\Models\Space;
use DateTime;

class PriceService
{
    /**
     * Calculate space price between two datetimes.
     * Mirrors your web logic:
     *  - start->modify('-1 seconds'), end->modify('+1 seconds')
     *  - round minutes up to the next hour
     *  - if $hours > hours_after_full_day -> roll to 1 extra day
     *  - then **+1 to both $hours and $days** (web quirk)
     *  - 28-day month split, then week/day/hour items
     *  - bucket capping (hours<=day, days<=week, weeks<=month)
     *  - extra fees from $space->extra_price (one_time, per_hour, per_day)
     *  - buyer/host fees + tax from .env
     */
    public static function getSpacePrice(Space $space, string $from, string $to, $bookingId = null): array
    {
        // ---- guard ----
        if (!$from || !$to) return self::emptyPrice();

        // ---- MATCH WEB DIFF TRICK ----
        $start = new DateTime($from);
        $start->modify('-1 seconds');                 // web does this
        $end   = new DateTime($to);
        $end->modify('+1 seconds');                   // and this

        if ($end <= $start) {
            return self::emptyPrice();
        }

        $difference = $start->diff($end, true);

        // Base components
        $totalDays = $difference->days;               // full days between (diff with above tweak)
        $hours     = $difference->h;
        $minutes   = $difference->i;

        // ---- ROUND MINUTES UP TO HOUR ----
        if ($minutes > 0) {
            $hours += 1;
        }

        // ---- ROLL HOURS INTO A DAY IF OVERFLOW ----
        $maxHoursPerDay = (int)($space->hours_after_full_day ?? 24);
        if ($hours > $maxHoursPerDay) {
            $hours = 0;
            $totalDays += 1;
        }

        // ---- WEB QUIRK: +1 TO HOURS AND DAYS ----
        $hours += 1;
        $totalDays += 1;

        // ---- 28-DAY MONTH LOGIC ----
        $months = intdiv($totalDays, 28);
        $remDaysAfterMonths = $totalDays % 28;

        $weeks = intdiv($remDaysAfterMonths, 7);
        $days  = $remDaysAfterMonths % 7;

        // ---- RATES (discounted > regular > fallback listing) ----
        $listingPrice = self::numOrDefault($space->sale_price, $space->price);

        $hourRate  = self::numOrDefault($space->discounted_hourly,  $space->hourly);
        $dayRate   = self::numOrDefault($space->discounted_daily,   $space->daily,   $listingPrice);
        $weekRate  = self::numOrDefault($space->discounted_weekly,  $space->weekly);
        $monthRate = self::numOrDefault($space->discounted_monthly, $space->monthly);

        $items = [];
        $totalMonthPrice = $totalWeekPrice = $totalDayPrice = $totalHourPrice = $otherPrice = 0;

        $haveAllTiers = self::allPositive([$monthRate, $weekRate, $dayRate, ($hourRate ?? 0)]);

        if ($haveAllTiers) {
            if ($months > 0) {
                $totalMonthPrice = $months * $monthRate;
                $items[] = ['type'=>'months','quantity'=>$months,'rate'=>self::fmt($monthRate),'total'=>$totalMonthPrice];
            }
            if ($weeks > 0) {
                $totalWeekPrice = $weeks * $weekRate;
                $items[] = ['type'=>'weeks','quantity'=>$weeks,'rate'=>self::fmt($weekRate),'total'=>$totalWeekPrice];
            }
            if ($days > 0) {
                $totalDayPrice = $days * $dayRate;
                $items[] = ['type'=>'days','quantity'=>$days,'rate'=>self::fmt($dayRate),'total'=>$totalDayPrice];
            }
            if ($hours > 0) {
                $totalHourPrice = $hours * ($hourRate ?? 0);
                $items[] = ['type'=>'hours','quantity'=>$hours,'rate'=>self::fmt($hourRate ?? 0),'total'=>$totalHourPrice];
            }
        } else {
            // Fallback: bill in days using daily/listing price
            $effectiveDay = $dayRate > 0 ? $dayRate : $listingPrice;
            $totalDaysDifference = max(1, $totalDays + ($hours > 0 ? 1 : 0));
            $otherPrice = $totalDaysDifference * $effectiveDay;
            $items[] = ['type'=>'days','quantity'=>$totalDaysDifference,'rate'=>self::fmt($effectiveDay),'total'=>$otherPrice];
            // squash for clarity
            $months = $weeks = $days = 0; $hours = 0;
        }

        $price = $totalMonthPrice + $totalWeekPrice + $totalDayPrice + $totalHourPrice + $otherPrice;

        // ---- BUCKET CAPPING (don’t overcharge) ----
        if ($days == 0 && $hours > 0 && $weeks == 0 && $months == 0 && $dayRate > 0) {
            if ($price > $dayRate) $price = $dayRate;
        } elseif ($weeks == 0 && $days > 0 && $months == 0 && $weekRate > 0) {
            if ($price > $weekRate) $price = $weekRate;
        } elseif ($months == 0 && $weeks > 0 && $monthRate > 0) {
            if ($price > $monthRate) $price = $monthRate;
        }

        // ---- EXTRA FEES (one_time / per_hour / per_day) ----
        $extraFee = 0; $extraFeeList = [];
        $totalHoursForExtras = ($totalDays * 24) + $hours;
        if (!empty($space->extra_price)) {
            $extras = is_array($space->extra_price) ? $space->extra_price : json_decode($space->extra_price, true);
            if (is_array($extras)) {
                foreach ($extras as $ex) {
                    $row = 0.0;
                    $type = $ex['type'] ?? '';
                    $val  = (float)($ex['price'] ?? 0);
                    if ($type === 'one_time') {
                        $row = $val;
                    } elseif ($type === 'per_hour') {
                        $row = $val * $totalHoursForExtras;
                    } elseif ($type === 'per_day') {
                        $row = $val * max(1, $totalDays);
                    }
                    $extraFee += $row;
                    $tmp = $ex;
                    $tmp['totalAmount'] = (string)($row); // string to match your API shape
                    $extraFeeList[] = $tmp;
                }
            }
        }
        $priceAfterExtraFee = $price + $extraFee;

        // ---- BUYER (GUEST) FEES from .env JSON ----
        // e.g. SPACE_BOOKING_BUYER_FEES=[{"name":"Service Fee","desc":"Service charged to Guest on booking.","unit":"percent","price":10}]
        $guestFee = 0; $guestFeeList = [];
        $buyerFees = json_decode(env('SPACE_BOOKING_BUYER_FEES', '[]'), true);
        if (is_array($buyerFees)) {
            foreach ($buyerFees as $f) {
                $row = 0.0;
                $unit = $f['unit'] ?? 'fixed';
                $val  = (float)($f['price'] ?? 0);
                if ($unit === 'fixed') {
                    $row = $val;
                } elseif ($unit === 'percent') {
                    $row = ($val * $priceAfterExtraFee) / 100.0;
                }
                $guestFee += $row;
                $tmp = $f;
                $tmp['totalAmount'] = (string)($row);
                $guestFeeList[] = $tmp;
            }
        }
        $priceAfterGuestFee = $priceAfterExtraFee + $guestFee;

        // ---- TAX ----
        $taxPercent = (float)env('TAX_PERCENT', 0);
        $tax = ($priceAfterGuestFee * $taxPercent) / 100.0;
        $priceAfterTax = $priceAfterGuestFee + $tax;

        // ---- HOST (SELLER) FEES from .env JSON ----
        // e.g. SPACE_BOOKING_SELLER_FEES=[{"name":"Platform Fee","unit":"percent","price":3}]
        $hostFee = 0; $hostFeeList = [];
        $sellerFees = json_decode(env('SPACE_BOOKING_SELLER_FEES', '[]'), true);
        if (is_array($sellerFees)) {
            foreach ($sellerFees as $f) {
                $row = 0.0;
                $unit = $f['unit'] ?? 'fixed';
                $val  = (float)($f['price'] ?? 0);
                if ($unit === 'fixed') {
                    $row = $val;
                } elseif ($unit === 'percent') {
                    // Like your site: host fee computed on priceAfterExtraFee
                    $row = ($val * $priceAfterExtraFee) / 100.0;
                }
                $hostFee += $row;
                $tmp = $f;
                $tmp['totalAmount'] = (string)($row);
                $hostFeeList[] = $tmp;
            }
        }

        // ---- Discounts/Coupons (omitted here; add if you need parity with web coupons) ----
        $discount = 0.0;
        $couponType = 0; // 0=none

        $payable = max(0, $priceAfterTax - $discount);

        // Same split style as your site
        $adminAmount = $hostFee + $tax + $guestFee;          // (minus global coupon portion if you add coupons later)
        $hostAmount  = $payable - $adminAmount;              // (minus space coupon portion if you add coupons later)

        // Summary blocks (match your names)
        $rentalTotal = $price + $extraFee;
        $subTotal    = $rentalTotal + $guestFee;
        $total       = $subTotal + $tax;
        $grandTotal  = $total - $discount;

        return [
            'items' => $items,

            'extraFeeList' => $extraFeeList,
            'guestFeeList' => $guestFeeList,
            'hostFeeList'  => $hostFeeList,

            'timeInfo' => [
                'hours'  => ($difference->h + ($difference->i > 0 ? 1 : 0)) + 1, // including +1 quirk
                'days'   => $days,
                'weeks'  => $weeks,
                'months' => $months
            ],

            'price'               => $price,
            'extraFee'            => $extraFee,
            'priceAfterExtraFee'  => $priceAfterExtraFee,
            'guestFee'            => $guestFee,
            'priceAfterGuestFee'  => $priceAfterGuestFee,
            'tax'                 => $tax,
            'priceAfterTax'       => $priceAfterTax,

            'couponType'          => $couponType,
            'discount'            => $discount,
            'payableAmount'       => $payable,

            'hostFee'             => $hostFee,
            'adminAmount'         => $adminAmount,
            'hostAmount'          => $hostAmount,

            'rentalTotal'         => $rentalTotal,
            'subTotal'            => $subTotal,
            'total'               => $total,
            'grandTotal'          => $grandTotal,
        ];
    }

    // ---------- helpers ----------

    private static function emptyPrice(): array
    {
        return [
            'items' => [],
            'extraFeeList' => [],
            'guestFeeList' => [],
            'hostFeeList'  => [],
            'timeInfo' => ['hours'=>0,'days'=>0,'weeks'=>0,'months'=>0],
            'price'=>0,
            'extraFee'=>0,'priceAfterExtraFee'=>0,
            'guestFee'=>0,'priceAfterGuestFee'=>0,
            'tax'=>0,'priceAfterTax'=>0,
            'couponType'=>0,'discount'=>0,'payableAmount'=>0,
            'hostFee'=>0,'adminAmount'=>0,'hostAmount'=>0,
            'rentalTotal'=>0,'subTotal'=>0,'total'=>0,'grandTotal'=>0,
        ];
    }

    private static function numOrDefault(...$candidates): float
    {
        foreach ($candidates as $c) {
            if ($c !== null && $c !== '' && (float)$c > 0) {
                return (float)$c;
            }
        }
        return 0.0;
    }

    private static function allPositive(array $vals): bool
    {
        foreach ($vals as $v) {
            if ((float)$v <= 0) return false;
        }
        return true;
    }

    private static function fmt($n): string
    {
        return number_format((float)$n, 2, '.', '');
    }
}