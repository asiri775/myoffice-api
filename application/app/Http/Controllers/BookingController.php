<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PostalCodesAndTimeZone;
use App\Models\Timezones_Reference;
use App\Support\ApiResponse;
use App\Helpers\BookingHelper;
use App\Models\Space;
use App\Models\Booking;
use App\Models\SpaceBlockTime;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class BookingController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/bookings/verify-times
     */
    public function verifySelectedTimes(Request $request): JsonResponse
    {
        // 1) Validate inputs
        $v = Validator::make($request->all(), [
            'id'          => ['required','integer','min:1'],        // space id
            'bookingId'   => ['nullable','integer','min:1'],
            'start_date'  => ['required','string'],                 // m/d/Y
            'end_date'    => ['required','string'],                 // m/d/Y
            'start_hour'  => ['required','date_format:H'],          // 00..23
            'end_hour'    => ['required','date_format:H'],          // 00..23
            'start_ampm'  => ['nullable','in:AM,PM'],
            'end_ampm'    => ['nullable','in:AM,PM'],
        ]);
        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }

        $data       = $v->validated();
        $spaceId    = (int) $data['id'];
        $bookingId  = $data['bookingId'] ?? null;

        // 2) Space exists?
        $space = Space::find($spaceId);
        if (!$space) {
            return $this->notFound('Space not found');
        }

        // 3) Normalize dates m/d/Y -> Y-m-d and bind hours
        $startYmd = BookingHelper::parseUsDate($data['start_date']);
        $endYmd   = BookingHelper::parseUsDate($data['end_date']);
        if (!$startYmd || !$endYmd) {
            return $this->badRequest('Invalid date format. Expected m/d/Y');
        }

        $startDate = "{$startYmd} {$data['start_hour']}:01";
        $endDate   = "{$endYmd} {$data['end_hour']}:00";

        // 4) Resolve timezone via postal/city/state tables with safe fallback
        $timezone = 'UTC';
        try {
            $zip   = $space->zip ?? null;
            $city  = $space->city ? strtoupper($space->city) : null;
            $state = $space->state ?? null;

            $postal = PostalCodesAndTimeZone::query()
                ->when($state, fn($q)=>$q->orWhere('province_abbr', $state))
                ->when($zip,   fn($q)=>$q->orWhere('postalcode', $zip))
                ->when($city,  fn($q)=>$q->orWhere('city', $city))
                ->first();

            if ($postal) {
                $tzRow = Timezones_Reference::find($postal->timezone);
                $timezone = BookingHelper::resolveTimezone($tzRow->php_time_zones ?? null);
            } else {
                $timezone = BookingHelper::resolveTimezone(null);
            }
        } catch (\Throwable $e) {
            $timezone = BookingHelper::resolveTimezone(null);
        }

        $nowTz   = Carbon::now($timezone);
        $startAt = Carbon::parse($startDate, $timezone);
        $endAt   = Carbon::parse($endDate, $timezone);

        // 5) Quick chronological checks
        if ($startAt->lt($nowTz)) {
            return $this->badRequest('Start date must be in the future');
        }
        if ($endAt->lte($startAt)) {
            return $this->badRequest('End date must be greater than start date');
        }

        // 6) If bookingId given and dates unchanged
        if ($bookingId) {
            $existing = Booking::find($bookingId);
            if (!$existing) return $this->notFound('Booking not found');

            if ($existing->start_date === $startAt->format('Y-m-d H:i:s') &&
                $existing->end_date   === $endAt->format('Y-m-d H:i:s')) {

                $price = (float) ($existing->total_before_fees ?? 0);
                return $this->ok([
                    'space_title'    => $space->title,
                    'start_time'     => $existing->start_date,
                    'end_time'       => $existing->end_date,
                    'total_hours'    => BookingHelper::getHoursBetweenDates($existing->start_date, $existing->end_date),
                    'price'          => $price,
                    'priceFormatted' => BookingHelper::formatPrice($price),
                    'message_hint'   => 'No changes',
                ], 'No Changes');
            }
        }

        // 7) Minimum stay
        $minHours   = (int) ($space->min_hour_stays ?? 0);
        $totalHours = BookingHelper::getHoursBetweenDates($startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s'));
        if ($totalHours < $minHours) {
            return $this->badRequest('Minimum stay must be at least '.$minHours.' hours.');
        }

        // 8) Daily availability window
        if ($space->available_from !== null && $space->available_to !== null) {
            $fromH = (int) $space->available_from;
            $toH   = (int) $space->available_to;
            $startH = (int) $startAt->format('H');
            $endH   = (int) $endAt->format('H');

            if ($startH < $fromH || $startH > $toH || $endH < $fromH || $endH > $toH) {
                return $this->badRequest("Space is only available from {$fromH}:00 to {$toH}:00");
            }
        }

        // 9) Overlaps (bookings + blocks), no raw SQL
        $dayStart = $startAt->copy()->startOfDay();
        $dayEnd   = $endAt->copy()->endOfDay();

        $bookingOverlap = Booking::query()
            ->where('object_model', 'space')
            ->where('object_id', $spaceId)
            ->when($bookingId, fn($q)=>$q->where('id','!=',$bookingId))
            ->whereNotIn('status', ['complete','paid'])
            ->where(function($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('start_date', [$dayStart, $dayEnd])
                  ->orWhereBetween('end_date',   [$dayStart, $dayEnd])
                  ->orWhere(function($q2) use ($dayStart,$dayEnd){
                      $q2->where('start_date', '<=', $dayStart)
                         ->where('end_date',   '>=', $dayEnd);
                  });
            })
            ->exists();

        if ($bookingOverlap) {
            return $this->badRequest('Timings not available (overlaps existing bookings).');
        }

        $blockOverlap = SpaceBlockTime::query()
            ->where('bravo_space_id', $spaceId)
            ->where(function($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('from', [$dayStart, $dayEnd])
                  ->orWhereBetween('to',   [$dayStart, $dayEnd])
                  ->orWhere(function($q2) use ($dayStart,$dayEnd){
                      $q2->where('from', '<=', $dayStart)
                         ->where('to',   '>=', $dayEnd);
                  });
            })
            ->exists();

        if ($blockOverlap) {
            return $this->badRequest('Timings not available (space is blocked).');
        }

        // 10) Price
        $priceInfo = BookingHelper::getSpacePrice(
            $space,
            $startAt->format('Y-m-d H:i:s'),
            $endAt->format('Y-m-d H:i:s'),
            $bookingId ? (int)$bookingId : null
        );
        $price = (float) ($priceInfo['price'] ?? 0);

        // 11) Success
        return $this->ok([
            'space_title'    => $space->title,
            'start_time'     => $startAt->format('Y-m-d H:i:s'),
            'end_time'       => $endAt->format('Y-m-d H:i:s'),
            'total_hours'    => $totalHours,
            'price'          => $price,
            'priceFormatted' => BookingHelper::formatPrice($price),
            'priceInfo'      => $priceInfo,
            'timezone'       => $timezone,
        ], 'Successfully checked the availability');
    }
}
