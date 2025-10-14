<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CoreSetting;
use App\Models\Payment;
use App\Models\Space;
use App\Models\SpaceTerm;
use App\Models\User;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

final class BookingController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/bookings/add-to-cart

     */

        public function addToCart(Request $request)
        {
            // 1) Validate
            $v = Validator::make($request->all(), [
                'service_type' => ['required', 'string'],
                'service_id'   => ['required', 'integer', 'min:1'],
                'start_date'   => ['sometimes', 'string'],
                'end_date'     => ['sometimes', 'string'],
                'start_hour'   => ['sometimes', 'string'],
                'end_hour'     => ['sometimes', 'string'],
                'start_ampm'   => ['sometimes', 'in:AM,PM'],
                'end_ampm'     => ['sometimes', 'in:AM,PM'],
                'adults'       => ['sometimes', 'integer', 'min:0'],
                'children'     => ['sometimes', 'integer', 'min:0'],
                'rooms'        => ['sometimes','array'],
            ]);

            if ($v->fails()) {
                return $this->fail($v->errors(), 'Validation error'); // 422
            }

            try {
                $normalized = $this->normalizeDateTimeFields($request);
                $request->merge($normalized);
            } catch (\Throwable $e) {
                return $this->badRequest('Invalid date/time provided', ['_error' => [$e->getMessage()]]);
            }

            // Resolve model & permissions (same as you had)
            $bookables = get_bookable_services();
            $serviceType = $request->input('service_type');
            $serviceId   = (int)$request->input('service_id');

            if (empty($bookables[$serviceType])) {
                return $this->notFound('Service type not found');
            }
            $modelClass = $bookables[$serviceType];
            $service = $modelClass::find($serviceId);

            if (!$service) return $this->notFound('Service not found');
            if (!$service->isBookable()) return $this->badRequest('Service is not bookable');
            if (Auth::id() && (int)Auth::id() === (int)$service->create_user) {
                return $this->forbidden('You cannot book your own service');
            }

            try {
                $resp = $service->addToCart($request);

                // If model returned a JsonResponse, pass through (still gets X-Trace-Id in middleware)
                if ($resp instanceof \Illuminate\Http\JsonResponse) return $resp;

                // Array fallback → wrap as success
                if (is_array($resp)) return $this->ok($resp, 'Added to cart');

                return $this->ok(null, 'Added to cart');
            } catch (\Throwable $e) {
                return $this->serverError('Unable to add to cart', [
                    'exception' => $e->getMessage(),
                ]); // 500
            }
        }


        public function show($id)
        {
            try {
                $userId = Auth::id();
                if (!$userId) {
                    return $this->unauthorized();
                }

                $booking = Booking::find($id);
                if (!$booking) {
                    return $this->notFound("Booking not found");
                }

                // Check ownership (customer or vendor)
                if ($booking->customer_id != $userId && $booking->vendor_id != $userId) {
                    return $this->forbidden("You do not have permission to view this booking");
                }

                $space    = Space::find($booking->object_id);
                $customer = User::find($booking->customer_id);
                $payment  = Payment::where('booking_id', $booking->id)->first();
                $categories = SpaceTerm::where('target_id', $booking->object_id)
                    ->pluck('term_id')
                    ->toArray();

                $fmt = function ($val) {
                    if (function_exists('format_price')) return format_price($val);
                    return is_null($val) ? '-' : '$' . number_format((float) $val, 2);
                };

                $data = [
                    'id' => $booking->id,
                    'code' => $booking->code,
                    'status' => $booking->status,
                    'status_text' => $this->statusText($booking->status),
                    'status_class' => $this->statusClass($booking->status),
                    'is_archive' => (int) ($booking->is_archive ?? 0),
                    'object_id' => $booking->object_id,
                    'space' => $space ? [
                        'id' => $space->id,
                        'title' => $space->title,
                        'address' => $space->address,
                        'categories' => $categories,
                    ] : null,
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    ] : null,
                    'start_date' => $booking->start_date,
                    'end_date' => $booking->end_date,
                    'total' => (float) $booking->total,
                    'total_formatted' => $fmt($booking->total),
                    'host_amount' => (float) $booking->host_amount,
                    'host_amount_formatted' => $fmt($booking->host_amount),
                    'transaction' => $payment ? [
                        'id' => $payment->id,
                        'status' => $payment->status,
                        'status_text' => $payment->status === 'completed' ? 'PAID' : 'UNPAID',
                        'gateway' => $payment->payment_gateway,
                        'amount' => (float) $payment->amount,
                        'credit' => (float) $payment->credit,
                    ] : null,
                ];

                return $this->ok($data, "Booking detail fetched successfully");
            } catch (\Throwable $e) {
                return $this->serverError("Failed to fetch booking details", [
                    'exception' => class_basename($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }

        public function reschedule(Request $request)
        {
            try {
                $userId = Auth::id();
                if (!$userId) {
                    return $this->unauthorized();
                }

                // Validate required fields
                $validated = $this->validate($request, [
                    'booking_id'     => 'required|integer|exists:bravo_bookings,id',
                    'newstart_date'  => 'required|date_format:Y-m-d H:i',
                    'newend_date'    => 'required|date_format:Y-m-d H:i|after:newstart_date',
                ]);

                $booking = Booking::find($validated['booking_id']);
                if (!$booking) {
                    return $this->notFound('Booking not found');
                }

                // Check user is owner (customer or vendor)
                if ($booking->customer_id !== $userId && $booking->vendor_id !== $userId) {
                    return $this->forbidden('You do not have permission to modify this booking');
                }

                // Update times
                $booking->start_date = Carbon::parse($validated['newstart_date'])->format('Y-m-d H:i:s');
                $booking->end_date   = Carbon::parse($validated['newend_date'])->format('Y-m-d H:i:s');
                $booking->save();

                $data = [
                    'id' => $booking->id,
                    'start_date' => $booking->start_date,
                    'end_date'   => $booking->end_date,
                    'status'     => $booking->status,
                    'status_text' => $this->statusText($booking->status),
                ];

                return $this->ok($data, 'Booking rescheduled successfully');
            } catch (\Throwable $e) {
                return $this->serverError('Failed to reschedule booking', [
                    'exception' => class_basename($e),
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
            }
        }


        public function invoice(Request $request)
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return $this->unauthorized();
            }

            // Validate request body
            $validated = $this->validate($request, [
                'code' => 'required|string|exists:bravo_bookings,code',
            ]);

            $booking = Booking::where('code', $validated['code'])->first();
            if (!$booking) {
                return $this->notFound('Booking not found');
            }

            // Access control
            if ($booking->customer_id !== $userId && $booking->vendor_id !== $userId) {
                return $this->forbidden('You do not have permission to view this invoice');
            }

            $space      = Space::find($booking->object_id);
            $customer   = User::find($booking->customer_id);
            $host       = User::find($booking->vendor_id);
            $payment    = Payment::where('booking_id', $booking->id)->first();
            $categoryIds = SpaceTerm::where('target_id', $booking->object_id)->pluck('term_id')->toArray();

            $fmt = function ($val) {
                if (function_exists('format_price')) return format_price($val);
                return is_null($val) ? '-' : '$' . number_format((float) $val, 2);
            };

            $data = [
                'booking' => [
                    'id'                 => $booking->id,
                    'code'               => $booking->code,
                    'status'             => $booking->status,
                    'start_date'         => $booking->start_date,
                    'end_date'           => $booking->end_date,
                    'total'              => (float)$booking->total,
                    'total_formatted'    => $fmt($booking->total),
                    'host_amount'        => (float)$booking->host_amount,
                    'host_amount_formatted' => $fmt($booking->host_amount),
                ],
                'space' => $space ? [
                    'id'          => $space->id,
                    'title'       => $space->title,
                    'address'     => $space->address,
                    'category_ids'=> $categoryIds,
                ] : null,
                'customer' => $customer ? [
                    'id'    => $customer->id,
                    'name'  => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'email' => $customer->email ?? null,
                ] : null,
                'host' => $host ? [
                    'id'    => $host->id,
                    'name'  => trim(($host->first_name ?? '') . ' ' . ($host->last_name ?? '')),
                    'email' => $host->email ?? null,
                ] : null,
                'payment' => $payment ? [
                    'id'          => $payment->id,
                    'status'      => $payment->status,
                    'status_text' => $payment->status === 'completed' ? 'PAID' : 'UNPAID',
                    'amount'      => (float)($payment->amount ?? $booking->total),
                    'credit'      => (float)($payment->credit ?? 0),
                    'gateway'     => $payment->payment_gateway,
                    'code'        => $payment->code,
                ] : [
                    'status'      => 'draft',
                    'status_text' => 'UNPAID',
                    'amount'      => (float)$booking->total,
                    'credit'      => 0.0,
                ],
                'totals' => [
                    'grand_total'           => (float)$booking->total,
                    'grand_total_formatted' => $fmt($booking->total),
                ],
            ];

            return $this->ok($data, 'Invoice fetched');
        } catch (\Throwable $e) {
            return $this->serverError('Failed to fetch invoice', [
                'exception' => class_basename($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    public function verifySelectedTimes(Request $request)
{
    try {
        $userId = Auth::id();
        if (!$userId) {
            return $this->unauthorized();
        }

        // Keep validation simple here; parse formats manually below
        $validated = $this->validate($request, [
            'booking_id' => 'required|integer|exists:bravo_bookings,id',
            'start_date' => 'required|string',
            'end_date'   => 'required|string',
            'extendtime' => 'nullable|string',
        ]);

        $booking = Booking::find($validated['booking_id']);
        if (!$booking) {
            return $this->notFound('Booking not found');
        }
        if ($booking->customer_id !== $userId && $booking->vendor_id !== $userId) {
            return $this->forbidden('You do not have permission to check this booking');
        }

        // Parse dates allowing both "Y-m-d H:i" and "Y-m-d H:i:s"
        $parse = function (string $value) {
            $fmts = ['Y-m-d H:i:s', 'Y-m-d H:i'];
            foreach ($fmts as $fmt) {
                $dt = \DateTime::createFromFormat($fmt, $value);
                if ($dt && $dt->format($fmt) === $value) {
                    return \Carbon\Carbon::createFromFormat($fmt, $value)->seconds(0);
                }
            }
            // last try: let Carbon parse loosely
            try {
                return \Carbon\Carbon::parse($value)->seconds(0);
            } catch (\Throwable) {
                return null;
            }
        };

        $start = $parse($validated['start_date']);
        $end   = $parse($validated['end_date']);

        if (!$start || !$end) {
            return $this->fail([
                'start_date' => ['Invalid date format. Use Y-m-d H:i or Y-m-d H:i:s'],
                'end_date'   => ['Invalid date format. Use Y-m-d H:i or Y-m-d H:i:s'],
            ], 'Validation error', 422);
        }
        if ($end->lessThanOrEqualTo($start)) {
            return $this->fail([
                'end_date' => ['The end date must be after the start date.'],
            ], 'Validation error', 422);
        }

        // Normalize extendtime like "3 Hour" / "3 Hours" / "1 hour", etc.
        $extendLabel = $validated['extendtime'] ?? null;
        if ($extendLabel) {
            if (preg_match('/^\s*([1-3])\s*hour[s]?\s*$/i', $extendLabel, $m)) {
                $hours = (int) $m[1];
                $end->addHours($hours);
                $extendLabel = $hours . ' Hour' . ($hours > 1 ? 's' : '');
            } else {
                return $this->fail([
                    'extendtime' => ['Allowed values: 1 Hour, 2 Hours, 3 Hours'],
                ], 'Validation error', 422);
            }
        }

        // Overlap check with proper logic: startA < endB && endA > startB
        $blockingStatuses = ['booked','scheduled','checked-in','checked-out','completed'];
        $conflicts = Booking::query()
            ->where('object_model', 'space')
            ->where('object_id', $booking->object_id)
            ->where('id', '!=', $booking->id)
            ->whereIn('status', $blockingStatuses)
            ->where(function ($q) use ($start, $end) {
                $q->where('start_date', '<', $end->toDateTimeString())
                  ->where('end_date',   '>', $start->toDateTimeString());
            })
            ->get(['id','start_date','end_date','status']);

        $available = $conflicts->isEmpty();

        $data = [
            'booking_id' => $booking->id,
            'space_id'   => $booking->object_id,
            'start_date' => $start->toDateTimeString(),
            'end_date'   => $end->toDateTimeString(),
            'extendtime' => $extendLabel,
            'available'  => $available,
            'conflicts'  => $conflicts->map(fn($c) => [
                'id' => $c->id, 'start_date' => $c->start_date, 'end_date' => $c->end_date, 'status' => $c->status
            ])->values(),
        ];

        return $this->ok($data, $available ? 'Successfully checked the availability' : 'Conflicting booking(s) found');

    } catch (\Throwable $e) {
        return $this->serverError('Failed to check availability', [
            'exception' => class_basename($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}


public function statusChange(Request $request)
{
    try {
        $userId = Auth::id();
        if (!$userId) {
            return $this->unauthorized();
        }

        // Validate request
        $validated = $this->validate($request, [
            'booking_id'      => 'required|integer|exists:bravo_bookings,id',
            'changetostatus'  => 'required|string|max:50',
        ]);

        $booking = Booking::find($validated['booking_id']);
        if (!$booking) {
            return $this->notFound('Booking not found');
        }

        // Permission check: only vendor or customer can change
        if ($booking->customer_id !== $userId && $booking->vendor_id !== $userId) {
            return $this->forbidden('You do not have permission to change this booking');
        }

        // Normalize new status
        $newStatus = strtolower(trim($validated['changetostatus']));

        // Optional: restrict allowed statuses
        $allowedStatuses = [
            'draft', 'booked', 'checked-in', 'checked-out', 'completed', 'cancelled', 'failed'
        ];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            return $this->fail([
                'changetostatus' => ['Invalid status. Allowed: ' . implode(', ', $allowedStatuses)]
            ], 'Validation error', 422);
        }

        // Update booking status
        $booking->status = $newStatus;
        $booking->save();

        // Trigger any internal notifications
        if (method_exists($booking, 'sendBookingNotifications')) {
            $booking->sendBookingNotifications();
        }

        $data = [
            'booking_id' => $booking->id,
            'code'       => $booking->code,
            'new_status' => $booking->status,
            'status_text'=> strtoupper($booking->status),
        ];

        return $this->ok($data, 'Booking status updated successfully');

    } catch (\Throwable $e) {
        return $this->serverError('Failed to update booking status', [
            'exception' => class_basename($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}



public function cancelBooking(Request $request)
{
    try {
        $userId = Auth::id();
        if (!$userId) {
            return $this->unauthorized();
        }

        // Validate input
        $validated = $this->validate($request, [
            'booking_id' => 'required|integer|exists:bravo_bookings,id',
        ]);

        // Find booking
        $booking = Booking::find($validated['booking_id']);
        if (!$booking) {
            return $this->notFound('Booking not found');
        }

        // Permission check (customer or vendor)
        if ($booking->customer_id !== $userId && $booking->vendor_id !== $userId) {
            return $this->forbidden('You do not have permission to cancel this booking');
        }

        // Update booking status
        $booking->status = 'cancelled';
        $booking->save();

        // Optional: trigger booking notification if available
        if (method_exists($booking, 'sendBookingNotifications')) {
            $booking->sendBookingNotifications();
        }

        return $this->ok([
            'booking_id'   => $booking->id,
            'code'         => $booking->code,
            'new_status'   => $booking->status,
            'status_text'  => strtoupper($booking->status),
        ], 'Booking cancelled successfully.');

    } catch (\Throwable $e) {
        return $this->serverError('Failed to cancel booking', [
            'exception' => class_basename($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}


public function contactHost(Request $request)
{
    try {
        $validated = $this->validate($request, [
            'booking_id' => 'required|integer|exists:bravo_bookings,id',
            'notes'      => 'required|string',
        ]);

        $booking = Booking::find($validated['booking_id']);
        if (!$booking) {
            return $this->notFound('Booking not found');
        }

        $space = Space::find($booking->object_id);
        if (!$space) {
            return $this->notFound('Space not found for this booking');
        }

        $spaceUser = User::find($space->create_user);
        if (!$spaceUser) {
            return $this->notFound('Host user not found');
        }

        $body = nl2br($validated['notes']);

        Mail::raw(strip_tags($validated['notes']), function ($message) use ($spaceUser) {
            $message->to($spaceUser->email)
                ->subject('Message from MyOffice Guest');
        });

        return $this->ok([
            'booking_id'   => $booking->id,
            'host_email'   => $spaceUser->email,
            'space_title'  => $space->title,
            'sent_by'      => auth()->id(),
            'sent_message' => $validated['notes'],
        ], 'Host has been contacted successfully.');
    } catch (\Throwable $e) {
        return $this->serverError('Failed to send email', [
            'exception' => class_basename($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
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


    protected function statusText(?string $status): string
    {
        return match ($status) {
            'draft'       => 'PENDING',
            'failed'      => 'FAILED',
            'scheduled'   => 'SCHEDULED',
            'booked'      => 'BOOKED',
            'checked-in'  => 'CHECKED-IN',
            'checked-out' => 'CHECKED-OUT',
            'completed'   => 'COMPLETED',
            'cancelled'   => 'CANCELLED',
            'no-show'     => 'NO-SHOW',
            default       => strtoupper($status ?? 'UNKNOWN'),
        };
    }

    protected function statusClass(?string $status): string
    {
        return match ($status) {
            'draft'                              => 'pending',
            'failed'                             => 'cancelled',
            'scheduled'                          => 'scheduled',
            'booked', 'checked-in', 'checked-out'=> 'active',
            'completed'                          => 'complete',
            'cancelled', 'no-show'               => 'cancelled',
            default                              => '',
        };
    }

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