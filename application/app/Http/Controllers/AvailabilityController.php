<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

// Models
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Models\SpaceBlockTime;
use App\Models\PostalCodesAndTimeZone;
use App\Models\TimezonesReference;

// Services
use App\Services\PriceService;

final class AvailabilityController extends Controller
{
    use ApiResponse;

    /* ===================== Confirm Block Date ===================== */
    // POST /api/mobile/availability/confirm-block-date/{id}
    public function confirmBlockDate(Request $request, int $id)
    {
        $start = $request->input('start');
        $end   = $request->input('end');

        $v = \Validator::make(
            ['start'=>$start, 'end'=>$end],
            ['start'=>'required|date', 'end'=>'required|date']
        );
        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }

        // Any booking overlapping the range?
        $bookingBetween = Booking::where('object_model', 'space')
            ->where('object_id', $id)
            ->where('status', '!=', 'draft')
            ->where(function ($q) use ($start,$end) {
                $q->whereBetween('start_date', [$start,$end])
                  ->orWhereBetween('end_date',   [$start,$end])
                  ->orWhere(function($qq) use ($start){ $qq->where('start_date','<=',$start)->where('end_date','>=',$start); })
                  ->orWhere(function($qq) use ($end){   $qq->where('start_date','<=',$end)->where('end_date','>=',$end); });
            })
            ->exists();

        if ($bookingBetween) {
            // keep legacy shape inside data so clients don’t break
            return $this->ok(['status'=>'error','message'=>'There are some booking(s) in select date'], 'Availability checked');
        }

        // Any block overlapping the range?
        $blocked = SpaceBlockTime::where('bravo_space_id', $id)
            ->where(function ($q) use ($start,$end) {
                $q->whereBetween('from', [$start,$end])
                  ->orWhereBetween('to',   [$start,$end])
                  ->orWhere(function($qq) use ($start){ $qq->where('from','<=',$start)->where('to','>=',$start); })
                  ->orWhere(function($qq) use ($end){   $qq->where('from','<=',$end)->where('to','>=',$end); });
            })
            ->exists();

        if ($blocked) {
            return $this->ok(['status'=>'error','message'=>'Selected date already blocked'], 'Availability checked');
        }

        return $this->ok(['status'=>'ok','message'=>''], 'Availability checked');
    }

    /* ===================== Calendar Events ===================== */
    // GET /api/mobile/availability/calendar-events?id=SPACE_ID
    public function calendarEvents(Request $request)
    {
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return $this->fail(['id'=>['id is required']], 'Validation error');
        }

        $events = [];

        // All bookings (pending/processing/etc.)
        $bookings = Booking::where('object_model','space')->where('object_id',$id)->get();
        foreach ($bookings as $b) {
            $customerLast = optional(User::find($b->customer_id))->last_name ?? '';
            $events[] = [
                'title'      => date('H:i', strtotime($b->start_date)) . " - " . date('H:i', strtotime($b->end_date)) . ': #' . $b->id . ' ' . $customerLast,
                'start'      => $b->start_date,
                'end'        => $b->end_date,
                'classNames' => ['processing'],
                'url'        => url('user/booking-details/' . $b->id),
                'other'      => [
                    'id'        => $b->id,
                    'spaceId'   => $b->object_id,
                    'startDate' => $b->start_date,
                    'endDate'   => $b->end_date,
                ],
            ];
        }

        // Confirmed statuses
        $confirmedStatuses = ['booked','checked_in','checked_out','completed'];
        $bookings = Booking::where('object_model','space')->where('object_id',$id)->whereIn('status',$confirmedStatuses)->get();
        foreach ($bookings as $b) {
            $customerLast = optional(User::find($b->customer_id))->last_name ?? '';
            $events[] = [
                'title'      => date('H:i', strtotime($b->start_date)) . " - " . date('H:i', strtotime($b->end_date)) . ': #' . $b->id . ' ' . $customerLast,
                'start'      => $b->start_date,
                'end'        => $b->end_date,
                'classNames' => ['confirmed'],
                'url'        => url('user/booking-details/' . $b->id),
                'other'      => [
                    'id'        => $b->id,
                    'spaceId'   => $b->object_id,
                    'startDate' => $b->start_date,
                    'endDate'   => $b->end_date,
                ],
            ];
        }

        // Blocks
        $blocks = SpaceBlockTime::where('bravo_space_id',$id)->get();
        foreach ($blocks as $blk) {
            $events[] = [
                'title'      => date('H:i', strtotime($blk->from)) . " - " . date('H:i', strtotime($blk->to)) . ': Blocked',
                'start'      => $blk->from,
                'end'        => $blk->to,
                'classNames' => ['blocked'],
            ];
        }

        return $this->ok($events, 'Calendar events loaded');
    }

    /* ===================== Calendar Appointments ===================== */
    // GET /api/mobile/availability/calendar-appointments?id=SPACE_ID (optional id)
    public function calendarAppointments(Request $request)
    {
        $userID = Auth::id();
        $spaceId = $request->query('id');

        $confirmedStatuses = ['booked','checked_in','checked_out','completed'];

        $bookings = Booking::where('object_model','space')
            ->where(function($q) use ($userID) {
                $q->where('customer_id',$userID)->orWhere('vendor_id',$userID);
            })
            ->when($spaceId, fn($q)=>$q->where('object_id',$spaceId))
            ->whereIn('status',$confirmedStatuses)
            ->get();

        $events = [];
        foreach ($bookings as $b) {
            $events[] = [
                'title'      => date('H:i', strtotime($b->start_date)) . " - " . date('H:i', strtotime($b->end_date)) . ': #' . $b->id,
                'start'      => $b->start_date,
                'end'        => $b->end_date,
                'classNames' => ['processing'],
                'url'        => url('user/booking-details/' . $b->id),
                'other'      => [
                    'id'        => $b->id,
                    'spaceId'   => $b->object_id,
                    'startDate' => $b->start_date,
                    'endDate'   => $b->end_date,
                ],
            ];
        }

        return $this->ok($events, 'Appointments loaded');
    }

    /* ===================== Available Dates (POST) ===================== */
    // POST /api/mobile/availability/available-dates
    // body: { id, start: YYYY-MM-DD, end: YYYY-MM-DD }
    public function availableDates(Request $request)
    {
        $v = \Validator::make($request->all(), [
            'id'    => ['required','integer'],
            'start' => ['required','date'],
            'end'   => ['required','date'],
        ]);
        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }

        $id    = (int) $request->input('id');
        $start = $request->input('start');
        $end   = $request->input('end');

        $todayStart = date('Y-m-d').' 00:00:00';
        $availabilities = [];

        foreach ($this->datesFromRange($start, $end) as $d) {
            $fromDay = $d.' 00:00:00';
            $toDay   = $d.' 23:59:59';
            if ($fromDay < $todayStart) continue;

            // bookings overlapping the day
            $bookingBetween = Booking::where('object_model','space')
                ->where('object_id',$id)
                ->where('status','!=','draft')
                ->where(function ($q) use ($fromDay,$toDay){
                    $q->whereBetween('start_date', [$fromDay,$toDay])
                      ->orWhereBetween('end_date',   [$fromDay,$toDay])
                      ->orWhere(function($qq) use ($fromDay){ $qq->where('start_date','<=',$fromDay)->where('end_date','>=',$fromDay); })
                      ->orWhere(function($qq) use ($toDay){   $qq->where('start_date','<=',$toDay)->where('end_date','>=',$toDay); });
                })
                ->orderBy('start_date')
                ->get();

            foreach ($bookingBetween as $b) {
                [$npStart,$npEnd] = $this->clipToDay($b->start_date, $b->end_date, $fromDay, $toDay);
                $availabilities[] = [
                    'title' => "Booked For MyOffice Client {$npStart} - {$npEnd}",
                    'start' => "{$d} {$npStart}:00",
                    'end'   => "{$d} {$npEnd}:59",
                ];
            }

            // blocks overlapping the day
            $blockedBetween = SpaceBlockTime::where('bravo_space_id',$id)
                ->where(function ($q) use ($fromDay,$toDay){
                    $q->whereBetween('from', [$fromDay,$toDay])
                      ->orWhereBetween('to',   [$fromDay,$toDay])
                      ->orWhere(function($qq) use ($fromDay){ $qq->where('from','<=',$fromDay)->where('to','>=',$fromDay); })
                      ->orWhere(function($qq) use ($toDay){   $qq->where('from','<=',$toDay)->where('to','>=',$toDay); });
                })
                ->get();

            foreach ($blockedBetween as $blk) {
                [$npStart,$npEnd] = $this->clipToDay($blk->from, $blk->to, $fromDay, $toDay);
                $availabilities[] = [
                    'title' => "Unavailable </br> {$npStart} - {$npEnd}",
                    'start' => "{$d} {$npStart}:00",
                    'end'   => "{$d} {$npEnd}:59",
                ];
            }
        }

        return $this->ok($availabilities, 'Availability windows loaded');
    }

    /* ===================== Verify Selected Times ===================== */
    // POST /api/mobile/availability/verify-selected-times
    public function verifySelectedTimes(Request $request)
    {
        // Maintain old response shape inside data
        $response = [
            'status' => 'error',
            'message' => 'Failed to check availability',
            'price' => 0,
            'start_time' => null,
            'end_time' => null
        ];

        $spaceId   = (int) $request->input('id', 0);
        $bookingId = $request->input('bookingId');

        if ($spaceId <= 0) {
            return $this->fail(['id'=>['id is required']], 'Validation error');
        }

        $start_date = $request->input('start_date'); // MM/DD/YYYY
        $end_date   = $request->input('end_date');   // MM/DD/YYYY
        $start_ampm = $request->input('start_ampm'); // preserved for parity (not re-used)
        $end_ampm   = $request->input('end_ampm');
        $startHour  = $request->input('start_hour'); // HH or HH:mm
        $endHour    = $request->input('end_hour');

        // minimal validation
        $v = \Validator::make(
            ['start_date'=>$start_date,'end_date'=>$end_date,'start_hour'=>$startHour,'end_hour'=>$endHour],
            ['start_date'=>'required','end_date'=>'required','start_hour'=>'required','end_hour'=>'required']
        );
        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }

        $start_d = $this->mmddyyyyToYmd($start_date);
        $end_d   = $this->mmddyyyyToYmd($end_date);

        $startDate = date('Y-m-d H:i:s', strtotime("{$start_d} {$startHour}:01"));
        $toDate    = date('Y-m-d H:i:s', strtotime("{$end_d} {$endHour}:00"));

        $space = Space::find($spaceId);
        if (!$space) {
            $response['message'] = 'Space not found';
            return $this->ok($response, 'Availability checked');
        }

        // Resolve "now" in space time zone
        $timezone = $this->resolveTimezoneForSpace($space) ?: 'Canada/Eastern';
        $nowDateTime = Carbon::now($timezone)->format('Y-m-d H:i:s');

        $response['requestInfo'] = [
            'startDate'   => $startDate,
            'toDate'      => $toDate,
            'nowDateTime' => $nowDateTime
        ];

        if (strtotime($startDate) < strtotime($nowDateTime)) {
            $response['message'] = 'Start Date should not be less than now.';
            return $this->ok($response, 'Availability checked');
        }
        if (strtotime($toDate) <= strtotime($startDate)) {
            $response['message'] = 'To date should be greater than from date.';
            return $this->ok($response, 'Availability checked');
        }

        $response['start_time'] = $startDate;
        $response['end_time']   = $toDate;

        // hours (rounded like your helper)
        $totalHours = round((strtotime($toDate) - strtotime($startDate)) / 3600, 1);
        $response['total_hours'] = $totalHours;

        // Min hour stays
        $minStay = $space->min_hour_stays ?? 0;
        if ($totalHours < $minStay) {
            $response['message'] = 'Minimum Stay should be equal or greater than '.$minStay.' hours.';
            return $this->ok($response, 'Availability checked');
        }

        $startDateMain = $start_d . ' 00:00:01';
        $toDateMain    = $end_d   . ' 23:59:59';
        $excludeId     = $bookingId ?? -1;

        // Overlap with other bookings?
        $bookingOverlaps = Booking::where('object_model','space')
            ->where('object_id',$spaceId)
            ->where('id','!=',$excludeId)
            ->where(function ($q) use ($startDateMain,$toDateMain,$startDate,$toDate){
                $q->whereBetween('start_date', [$startDateMain,$toDateMain])
                  ->orWhereBetween('end_date',   [$startDateMain,$toDateMain])
                  ->orWhere(function($qq) use ($startDateMain){ $qq->where('start_date','<=',$startDateMain)->where('end_date','>=',$startDateMain); })
                  ->orWhere(function($qq) use ($toDateMain){   $qq->where('start_date','<=',$toDateMain)->where('end_date','>=',$toDateMain); })
                  ->orWhereBetween('start_date', [$startDate,$toDate])
                  ->orWhereBetween('end_date',   [$startDate,$toDate])
                  ->orWhere(function($qq) use ($startDate){ $qq->where('start_date','<=',$startDate)->where('end_date','>=',$startDate); })
                  ->orWhere(function($qq) use ($toDate){   $qq->where('start_date','<=',$toDate)->where('end_date','>=',$toDate); });
            })
            ->get();

        if ($bookingOverlaps->count() > 0) {
            $response['status']  = 'error';
            $response['message'] = 'Timings not available';
            return $this->ok($response, 'Availability checked');
        }

        // Overlap with blocks?
        $blockedBetween = SpaceBlockTime::where('bravo_space_id', $spaceId)
            ->where(function ($q) use ($startDate,$toDate){
                $q->whereBetween('from', [$startDate,$toDate])
                  ->orWhereBetween('to',   [$startDate,$toDate])
                  ->orWhere(function($qq) use ($startDate){ $qq->where('from','<=',$startDate)->where('to','>=',$startDate); })
                  ->orWhere(function($qq) use ($toDate){   $qq->where('from','<=',$toDate)->where('to','>=',$toDate); });
            })
            ->exists();

        if ($blockedBetween) {
            $response['status']  = 'error';
            $response['message'] = 'Timings not available';
            return $this->ok($response, 'Availability checked');
        }

        // Available-from/to window check
        if ($space->available_from && $space->available_to) {
            $sh = (int) date('H', strtotime($startDate));
            $eh = (int) date('H', strtotime($toDate));
            if ($sh < (int)$space->available_from || $eh > (int)$space->available_to) {
                $response['status']  = 'error';
                $response['message'] = 'Space is only available from '.$space->available_from.'-'.$space->available_to;
                return $this->ok($response, 'Availability checked');
            }
        }

        // Pricing via service
        [$priceInfo, $price, $priceFormatted] = $this->priceFor($space, $startDate, $toDate, $bookingId);

        $response['status']          = 'success';
        $response['message']         = 'Successfully checked the availability';
        $response['step']            = '0x2';
        $response['priceInfo']       = $priceInfo;
        $response['price']           = $price;
        $response['priceFormatted']  = $priceFormatted;
        $response['space_title']     = $space->title;

        return $this->ok($response, 'Availability checked');
    }

    /* ===================== Helpers ===================== */

    private function datesFromRange(string $start, string $end): array
    {
        $out = [];
        $cur = strtotime($start);
        $to  = strtotime($end);
        while ($cur <= $to) {
            $out[] = date('Y-m-d', $cur);
            $cur = strtotime('+1 day', $cur);
        }
        return $out;
    }

   // put at the bottom of AvailabilityController (replacing your current clipToDay)
private function clipToDay($from, $to, $dayStart, $dayEnd): array
{
    // Convert anything (Carbon|string|int) to timestamps
    $fromTs = $this->toTimestamp($from);
    $toTs   = $this->toTimestamp($to);

    $dayStartTs = $this->toTimestamp($dayStart);
    $dayEndTs   = $this->toTimestamp($dayEnd);

    // Clip to the day's window
    $s = max($fromTs, $dayStartTs);
    $e = min($toTs,   $dayEndTs);

    // Format HH:MM
    $npStart = date('H:i', $s);
    $npEnd   = date('H:i', $e);

    // Keep original behavior for edge bounds
    if ($s <= $dayStartTs) $npStart = '00:00';
    if ($e >= $dayEndTs)   $npEnd   = '23:59';

    return [$npStart, $npEnd];
}

/**
 * Normalize Carbon|string|int|null to a UNIX timestamp.
 */
private function toTimestamp($value): int
{
    if ($value instanceof \DateTimeInterface) {
        return $value->getTimestamp();
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value)) {
        // if it’s already numeric string like "1696579200"
        if (ctype_digit($value)) return (int)$value;
        return strtotime($value) ?: 0;
    }
    // Fallback: cast to string then strtotime (handles weird driver returns)
    return strtotime((string)$value) ?: 0;
}


    private function mmddyyyyToYmd(?string $mdy): ?string
    {
        if (!$mdy) return null;
        $p = explode('/', $mdy);
        if (count($p) !== 3) return null;
        return $p[2] . '-' . $p[0] . '-' . $p[1];
    }

    private function resolveTimezoneForSpace(Space $space): ?string
    {
        $zip   = $space->zip;
        $city  = strtoupper($space->city ?? '');
        $state = $space->state;

        $postal = PostalCodesAndTimeZone::where('province_abbr',$state)
            ->orWhere('postalcode',$zip)
            ->orWhere('city',$city)
            ->first();

        if (!$postal) return null;

        $tzRow = TimezonesReference::where('id', $postal->timezone)->first();
        return $tzRow->php_time_zones ?? null;
    }

    private function priceFor(Space $space, string $start, string $end, $bookingId = null): array
    {
        $priceInfo = PriceService::getSpacePrice($space, $start, $end, $bookingId);
        return [$priceInfo, $priceInfo['price'], '$'.number_format($priceInfo['price'], 2)];
    }
}
