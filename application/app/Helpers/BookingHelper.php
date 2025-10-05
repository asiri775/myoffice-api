<?php
declare(strict_types=1);

namespace App\Helpers;

use DateTime;
use DateTimeZone;

final class BookingHelper
{
    /**
     * Input: Y-m-d H:i:s
     */
    public static function getHoursBetweenDates(string $start, string $end): float
    {
        return round((strtotime($end) - strtotime($start)) / 3600, 1);
    }

    /**
     * Returns: "$1,234.00" or '-' if null (unless $zeroIfNull = true)
     */
    public static function formatPrice($value, bool $zeroIfNull = false): string
    {
        if (!self::checkIfNumValNotNull($value)) {
            return $zeroIfNull ? '$0.00' : '-';
        }
        return '$' . number_format((float) $value, 2);
    }

    /** Core pricing logic (your code, compacted and guarded) */
    public static function getSpacePrice($space, string $from, string $to, ?int $bookingId = null): array
    {
        $items = [];
        $price = 0;

        if (!$space || !$from || !$to) {
            return self::emptyPrice();
        }

        $start = new DateTime($from);
        $start->modify('-1 seconds');

        $end = new DateTime($to);
        $end->modify('+1 seconds');

        $difference = $start->diff($end, true);

        $totalDays  = $difference->days;
        $totalHours = $difference->h + $difference->days * 24;

        // 28-day month logic
        $months = (int) floor($totalDays / 28);
        $weeks  = (int) floor(($totalDays % 28) / 7);
        $days   = (int) ($totalDays % 7);
        $hours  = (int) $difference->h;
        $minutes = (int) $difference->i;

        if ($minutes > 0) $hours++;

        $maxHoursPerDay = (int) (trim((string) $space->hours_after_full_day) ?: 24);
        if ($hours > $maxHoursPerDay) {
            $hours = 0;
            $days++;
        }

        // NOTE: your original code had extra increments; keeping pricing faithful without +1 side effects.
        $listingPrice = self::getNumValueOrDefault($space->sale_price, $space->price);
        $monthlyPrice = self::getNumValueOrDefault($space->discounted_monthly, $space->monthly);
        $dayPrice     = self::getNumValueOrDefault($space->discounted_daily,   $space->daily);
        $hourPrice    = self::getNumValueOrDefault($space->discounted_hourly,  $space->hourly);
        $weekPrice    = self::getNumValueOrDefault($space->discounted_weekly,  $space->weekly);

        $totalMonthPrice = $totalDayPrice = $totalHourPrice = $totalWeekPrice = $otherPrice = 0;

        if (
            self::checkIfNumValNotNull($monthlyPrice) &&
            self::checkIfNumValNotNull($dayPrice) &&
            self::checkIfNumValNotNull($hourPrice) &&
            self::checkIfNumValNotNull($weekPrice)
        ) {
            if ($months > 0) {
                $totalMonthPrice = $months * $monthlyPrice;
                $items[] = ['type' => 'months', 'quantity' => $months, 'rate' => $monthlyPrice, 'total' => $totalMonthPrice];
            }
            if ($days > 0) {
                $totalDayPrice = $days * $dayPrice;
                $items[] = ['type' => 'days', 'quantity' => $days, 'rate' => $dayPrice, 'total' => $totalDayPrice];
            }
            if ($hours > 0) {
                $totalHourPrice = $hours * $hourPrice;
                $items[] = ['type' => 'hours', 'quantity' => $hours, 'rate' => $hourPrice, 'total' => $totalHourPrice];
            }
            if ($weeks > 0) {
                $totalWeekPrice = $weeks * $weekPrice;
                $items[] = ['type' => 'weeks', 'quantity' => $weeks, 'rate' => $weekPrice, 'total' => $totalWeekPrice];
            }
        } else {
            // fallback: per-day listing price
            $diffSeconds = abs(strtotime($to) - strtotime($from));
            $daysDiff = max(1, (int) ceil($diffSeconds / 86400));
            $otherPrice = $daysDiff * $listingPrice;
            $items[] = ['type' => 'days', 'quantity' => $daysDiff, 'rate' => $listingPrice, 'total' => $otherPrice];
        }

        $price = $totalMonthPrice + $totalDayPrice + $totalHourPrice + $totalWeekPrice + $otherPrice;

        // caps: hour→day, day→week, week→month
        if ($days === 0 && $hours > 0 && $weeks === 0 && $months === 0) {
            $price = min($price, $dayPrice ?: $price);
        } elseif ($weeks === 0 && $days > 0 && $months === 0) {
            $price = min($price, $weekPrice ?: $price);
        } elseif ($months === 0 && $weeks > 0) {
            $price = min($price, $monthlyPrice ?: $price);
        }

        $priceDetails = [
            'items'          => $items,
            'extraFeeList'   => [],
            'guestFeeList'   => [],
            'hostFeeList'    => [],
            'timeInfo'       => ['hours' => $hours, 'days' => $days, 'weeks' => $weeks, 'months' => $months],
            'price'          => $price,
            'extraFee'       => 0,
            'priceAfterExtraFee' => $price,
            'guestFee'       => 0,
            'priceAfterGuestFee' => $price,
            'tax'            => 0,
            'priceAfterTax'  => $price,
            'couponType'     => 0,
            'discount'       => 0,
            'payableAmount'  => $price,
            'hostFee'        => 0,
            'adminAmount'    => 0,
            'hostAmount'     => 0,
            // aggregates used by your views
            'rentalTotal'    => $price,
            'subTotal'       => $price,
            'total'          => $price,
            'grandTotal'     => $price,
        ];

        // Note: I’ve omitted fees/coupons rendering & Blade view rendering to keep helper framework-agnostic.
        // You can add them back here if you need HTML snippets in the API.

        return $priceDetails;
    }

    /* ------------- small internals ------------- */

    public static function getNumValueOrDefault($prefer, $fallback)
    {
        if (self::checkIfNumValNotNull($prefer)) return (float) $prefer;
        if (self::checkIfNumValNotNull($fallback)) return (float) $fallback;
        return null;
    }

    public static function checkIfNumValNotNull($v): bool
    {
        return $v !== null && $v !== '' && is_numeric($v);
    }

    private static function emptyPrice(): array
    {
        return [
            'items' => [],
            'extraFeeList' => [],
            'guestFeeList' => [],
            'hostFeeList' => [],
            'timeInfo' => ['hours' => 0, 'days' => 0, 'weeks' => 0, 'months' => 0],
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

    /** m/d/Y → Y-m-d, returns null if invalid */
    public static function parseUsDate(?string $mdy): ?string
    {
        if (!$mdy) return null;
        $parts = explode('/', $mdy);
        if (count($parts) !== 3) return null;
        [$m, $d, $y] = $parts;
        if (!checkdate((int)$m, (int)$d, (int)$y)) return null;
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
    }

    /** Safe TZ fallback chain */
    public static function resolveTimezone(?string $tz): string
    {
        // ensure valid PHP timezone
        if ($tz && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }
        // region fallbacks
        foreach (['America/Toronto', 'America/New_York', 'UTC'] as $cand) {
            if (in_array($cand, DateTimeZone::listIdentifiers(), true)) {
                return $cand;
            }
        }
        return 'UTC';
    }
}