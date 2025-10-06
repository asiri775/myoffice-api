<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

final class BookingController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/bookings/add-to-cart

     */
    public function addToCart(Request $request): JsonResponse
    {
        // 1) Validate generic inputs first
        $v = Validator::make($request->all(), [
            'service_type' => ['required', 'string'],
            'service_id'   => ['required', 'integer', 'min:1'],


            'start_date'   => ['sometimes', 'string'],       // "10/25/2025" or "2025-10-25"
            'end_date'     => ['sometimes', 'string'],       // same
            'start_hour'   => ['sometimes', 'string'],       // "10:00"
            'end_hour'     => ['sometimes', 'string'],       // "17:00"
            'start_ampm'   => ['sometimes', 'in:AM,PM'],
            'end_ampm'     => ['sometimes', 'in:AM,PM'],


            'adults'       => ['sometimes', 'integer', 'min:0'],
            'children'     => ['sometimes', 'integer', 'min:0'],


        ]);

        if ($v->fails()) {
            return $this->fail($v->errors()->toArray(), 'Validation error');
        }


        $serviceType   = $request->input('service_type');
        $serviceId     = (int) $request->input('service_id');
        $bookables     = get_bookable_services(); // must return ['space' => \Modules\Space\Models\Space::class, ...]
        if (empty($bookables[$serviceType])) {
            return $this->notFound('Service type not found');
        }
        $modelClass = $bookables[$serviceType];


        /** @var \Models\Bookable $service */
        $service = $modelClass::find($serviceId);
        if (!$service) {
            return $this->notFound('Service not found');
        }
        if (!$service->isBookable()) {
            return $this->badRequest('Service is not bookable');
        }
        if (Auth::id() && (int)Auth::id() === (int)$service->create_user) {
            return $this->forbidden('You cannot book your own service');
        }

        // 4) Normalize date/time fields so every model receives consistent inputs.

        try {
            $normalized = $this->normalizeDateTimeFields($request);
            // Merge normalized canonical fields to Request for downstream model use
            // - start_at / end_at: "Y-m-d H:i:s" (full timestamps)
            // - start_date_norm / end_date_norm: "Y-m-d"
            $request->merge($normalized);
        } catch (\Throwable $e) {
            return $this->badRequest('Invalid date/time provided', ['_error' => [$e->getMessage()]]);
        }

        // 5) Delegate to model-specific addToCart() (Car/Event/Flight/Hotel/Space already implement this)

        try {
            $resp = $service->addToCart($request);

            // If a model returned a Laravel Response/JsonResponse, pass it through.
            if ($resp instanceof \Illuminate\Http\JsonResponse) {
                return $resp;
            }
            if ($resp instanceof \Symfony\Component\HttpFoundation\Response) {
                return response()->json($resp->getOriginalContent(), $resp->getStatusCode());
            }

            // If model returned an array (rare), wrap it.
            if (is_array($resp)) {
                return $this->ok($resp, 'Added to cart');
            }

            // As a safe fallback:
            return $this->ok(null, 'Added to cart');
        } catch (\Throwable $e) {
            // Uniform server error with trace id
            return $this->serverError(
                'Unable to add to cart',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /* ---------------- helpers ---------------- */

    /**
     * Accepts:
     * - start_date/end_date: "10/25/2025" or "2025-10-25"
     * - start_hour/end_hour: "10:00"
     * - start_ampm/end_ampm: "AM"/"PM" (optional)
     *
     * Returns canonical fields:
     * - start_date_norm / end_date_norm: "Y-m-d"
     * - start_at / end_at:               "Y-m-d H:i:s"
     *
    
     */
    private function normalizeDateTimeFields(Request $r): array
    {
        $out = [];

        $startDateRaw = $r->input('start_date');
        $endDateRaw   = $r->input('end_date');

        $startDateYmd = $startDateRaw ? $this->toYmd($startDateRaw) : null;
        $endDateYmd   = $endDateRaw   ? $this->toYmd($endDateRaw)   : null;

        $out['start_date_norm'] = $startDateYmd;
        $out['end_date_norm']   = $endDateYmd;

        // Times (support both 24h and AM/PM)
        $startHour = $r->input('start_hour');
        $endHour   = $r->input('end_hour');

        $startAmPm = $r->input('start_ampm'); // AM|PM|null
        $endAmPm   = $r->input('end_ampm');

        // Normalize clock parts
        $startClock = $startHour ? $this->to24h($startHour, $startAmPm) : '00:00';
        $endClock   = $endHour   ? $this->to24h($endHour,   $endAmPm)   : '00:00';

        // Compose timestamps
        if ($startDateYmd) {
            $out['start_at'] = $startDateYmd.' '.$startClock.':00';
        }
        if ($endDateYmd) {
            $out['end_at'] = $endDateYmd.' '.$endClock.':00';
        } elseif (!empty($out['start_at'])) {
            // Some models (Event ticket) only use a start moment
            $out['end_at'] = $out['start_at'];
        }

        // Validate temporal order if both present
        if (!empty($out['start_at']) && !empty($out['end_at'])) {
            if (strtotime($out['end_at']) <= strtotime($out['start_at'])) {
                throw new \InvalidArgumentException('End time must be after start time');
            }
        }

        return $out;
    }

    private function toYmd(string $date): string
    {
        $date = trim($date);
        // Allow "MM/DD/YYYY" or "YYYY-MM-DD"
        if (preg_match('~^\d{2}/\d{2}/\d{4}$~', $date)) {
            [$m,$d,$y] = array_map('intval', explode('/', $date));
            if (!checkdate($m,$d,$y)) throw new \InvalidArgumentException('Invalid date');
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        // Try native parse for ISO or common formats
        $ts = strtotime($date);
        if ($ts === false) throw new \InvalidArgumentException('Invalid date');
        return date('Y-m-d', $ts);
    }

    private function to24h(string $hhmm, ?string $ampm): string
    {
        $hhmm = trim($hhmm);
        // If AM/PM present, let strtotime convert
        if ($ampm === 'AM' || $ampm === 'PM') {
            $t = strtotime("$hhmm $ampm");
        } else {
            // Already 24h, still normalize via strtotime
            $t = strtotime($hhmm);
        }
        if ($t === false) {
            throw new \InvalidArgumentException('Invalid time');
        }
        return date('H:i', $t);
    }
}
