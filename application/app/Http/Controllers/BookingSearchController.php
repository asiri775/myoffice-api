<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

use App\Models\Booking;
use App\Models\Space;
use App\Models\SpaceTerm;
use App\Models\User;
use App\Models\Payment;

class BookingSearchController extends Controller
{
    use ApiResponse;

    /**
     * Convert mm/dd/yyyy to Y-m-d
     */
    protected function convertDate(?string $mdy): ?string
    {
        if (!$mdy) return null;
        $parts = explode('/', $mdy);
        if (count($parts) !== 3) return null;
        [$m, $d, $y] = $parts;
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
    }

    public function search(Request $request)
    {
        try {
            $userId  = Auth::id();
            if (!$userId) {
                return $this->unauthorized();
            }

            // Pagination (LengthAwarePaginator so ApiResponse meta helpers work)
            $page    = (int)($request->input('page', 1));
            $perPage = (int)($request->input('per_page', 20));
            $perPage = $perPage > 0 ? min($perPage, 100) : 20;

            // Inputs (mirror your web parameters)
            $q              = $request->input('search_query', []);
            $tType          = $q['tType'] ?? null;                 // "bookings" | "earnings"
            $dateOption     = $q['date_option'] ?? null;
            $statusScope    = $q['status'] ?? null;                // archived|scheduled|pending|history|active|booked|completed|all
            $transaction    = $q['transaction_status'] ?? null;    // paid|unpaid|fail
            $fromRaw        = $q['from'] ?? null;                  // mm/dd/yyyy
            $toRaw          = $q['to'] ?? null;                    // mm/dd/yyyy
            $searchText     = trim((string)($q['search'] ?? ''));
            $spaceParam     = trim((string)($q['space'] ?? ''));
            $topSearch      = trim((string)($q['top_search'] ?? ''));
            $guestName      = trim((string)($q['guest'] ?? ''));
            $bookingStatus  = $q['booking_status'] ?? null;
            $categoryId     = $q['category'] ?? null;
            $bookingId      = $q['id'] ?? null;
            $amountLike     = $q['amount'] ?? null;

            $isVendor       = $request->boolean('is_vendor', $tType === 'bookings' || $tType === 'earnings');

            // Resolve table names from models
            $bookingTable = (new Booking)->getTable();
            $spaceTable   = (new Space)->getTable();
            $spaceTermTbl = (new SpaceTerm)->getTable();
            $paymentTable = (new Payment)->getTable();

            $bookings = Booking::query()
                ->orderByDesc("$bookingTable.id")
                ->where(function (Builder $qq) use ($bookingTable, $userId) {
                    $qq->where("$bookingTable.customer_id", $userId)
                       ->orWhere("$bookingTable.vendor_id", $userId);
                });

            if ($tType === 'bookings' || $tType === 'earnings') {
                $bookings->where("$bookingTable.vendor_id", $userId);
            }

            /* -------- Date shortcuts -------- */
            if ($dateOption) {
                switch ($dateOption) {
                    case 'yesterday':
                        $bookings->whereDate("$bookingTable.start_date", Carbon::yesterday());
                        break;
                    case 'today':
                        $bookings->whereDate("$bookingTable.start_date", Carbon::today());
                        break;
                    case 'this_weekdays':
                        $bookings->whereBetween("$bookingTable.start_date", [
                            Carbon::now()->startOfWeek(),
                            Carbon::now()->endOfWeek()->subDays(2),
                        ]);
                        break;
                    case 'this_whole_week':
                        $bookings->whereBetween("$bookingTable.start_date", [
                            Carbon::now()->startOfWeek(),
                            Carbon::now()->endOfWeek(),
                        ]);
                        break;
                    case 'this_month':
                        $bookings->whereBetween("$bookingTable.start_date", [
                            Carbon::now()->startOfMonth(),
                            Carbon::now()->endOfMonth(),
                        ]);
                        break;
                    case 'this_year':
                        $bookings->whereBetween("$bookingTable.start_date", [
                            Carbon::now()->startOfYear(),
                            Carbon::now()->endOfYear(),
                        ]);
                        break;
                }
            }

            /* -------- Status scope (left pill) -------- */
            $showOnlyArchived = false;
            if ($statusScope) {
                switch ($statusScope) {
                    case 'archived':
                        $showOnlyArchived = true;
                        break;
                    case 'scheduled':
                        $bookings->where("$bookingTable.status", 'scheduled');
                        break;
                    case 'pending':
                        $bookings->where("$bookingTable.status", 'draft');
                        break;
                    case 'history':
                        $bookings->where("$bookingTable.status", 'completed'); // if you store 'complete', change accordingly
                        break;
                    case 'active':
                    case 'booked':
                        $bookings->whereIn("$bookingTable.status", ['booked', 'checked-in', 'checked-out']);
                        break;
                    case 'completed':
                        $bookings->where("$bookingTable.status", 'completed');
                        break;
                    case 'all':
                    default:
                        // no-op
                        break;
                }
            }

            /* -------- Transaction filter -------- */
            if ($transaction === 'paid') {
                $bookings->leftJoin($paymentTable, "$bookingTable.payment_id", '=', "$paymentTable.id")
                         ->where("$paymentTable.status", 'completed')
                         ->select("$bookingTable.*");
            } elseif ($transaction === 'unpaid') {
                $bookings->leftJoin($paymentTable, "$bookingTable.payment_id", '=', "$paymentTable.id")
                         ->where(function ($qq) use ($paymentTable, $bookingTable) {
                             $qq->where("$paymentTable.status", 'draft')
                                ->orWhereNull("$bookingTable.payment_id");
                         })
                         ->select("$bookingTable.*");
            } elseif ($transaction === 'fail') {
                $bookings->leftJoin($paymentTable, "$bookingTable.payment_id", '=', "$paymentTable.id")
                         ->where("$paymentTable.status", 'fail')
                         ->select("$bookingTable.*");
            }

            /* -------- Date range -------- */
            if (!empty($fromRaw)) {
                $from = $this->convertDate($fromRaw);
                if ($from) $bookings->where("$bookingTable.start_date", '>=', $from.' 00:00:00');
            }
            if (!empty($toRaw)) {
                $to = $this->convertDate($toRaw);
                if ($to) $bookings->where("$bookingTable.end_date", '<=', $to.' 23:59:59');
            }

            /* -------- Search -------- */
            if ($searchText !== '') {
                $bookings->where(function (Builder $qq) use ($searchText, $bookingTable, $spaceTable) {
                    $qq->whereIn("$bookingTable.object_id", function ($sub) use ($searchText, $spaceTable) {
                        $sub->select('id')->from($spaceTable)->where('address', 'like', '%'.$searchText.'%');
                    })->orWhere("$bookingTable.id", 'like', '%'.$searchText.'%');
                });
            }

            /* -------- Space (id/title) or Top search -------- */
            if ($spaceParam !== '') {
                $bookings->where(function (Builder $qq) use ($spaceParam, $bookingTable, $spaceTable) {
                    if (ctype_digit($spaceParam)) {
                        $qq->where("$bookingTable.object_id", (int)$spaceParam);
                    } else {
                        $qq->whereIn("$bookingTable.object_id", function ($sub) use ($spaceParam, $spaceTable) {
                            $sub->select('id')->from($spaceTable)
                                ->where('title', 'like', '%'.$spaceParam.'%')
                                ->orWhere('id', 'like', '%'.$spaceParam.'%');
                        });
                    }
                });
            } elseif ($topSearch !== '') {
                $userIds = User::query()
                    ->select('id')
                    ->whereRaw('CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,"")) LIKE ?', ['%'.$topSearch.'%'])
                    ->pluck('id')
                    ->all();
                if (empty($userIds)) $userIds = [-1];

                $bookings->where(function (Builder $qq) use ($topSearch, $userIds, $isVendor, $bookingTable, $spaceTable) {
                    $qq->whereIn("$bookingTable.object_id", function ($sub) use ($topSearch, $spaceTable) {
                        $sub->select('id')->from($spaceTable)
                            ->where('title', 'like', '%'.$topSearch.'%');
                    })->orWhere("$bookingTable.id", 'like', '%'.$topSearch.'%');

                    $isVendor
                        ? $qq->orWhereIn("$bookingTable.vendor_id", $userIds)
                        : $qq->orWhereIn("$bookingTable.customer_id", $userIds);
                });
            }

            /* -------- Guest -------- */
            if ($guestName !== '') {
                $userIds = User::query()
                    ->select('id')
                    ->whereRaw('CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,"")) LIKE ?', ['%'.$guestName.'%'])
                    ->pluck('id')->all();
                if (empty($userIds)) $userIds = [-1];
                $bookings->whereIn("$bookingTable.customer_id", $userIds);
            }

            /* -------- Booking status fine-grained -------- */
            if (!empty($bookingStatus)) {
                if ($bookingStatus === 'archived') {
                    $showOnlyArchived = true;
                } else {
                    $bookings->where("$bookingTable.status", $bookingStatus);
                }
            } else {
                // Restrict to known statuses (adjust if you have others)
                $bookings->whereIn("$bookingTable.status", [
                    'draft','failed','scheduled','booked','checked-in','checked-out','completed','cancelled','no-show',
                ]);
            }

            /* -------- Category -------- */
            if (!empty($categoryId)) {
                $spaceIds = SpaceTerm::query()
                    ->where('term_id', $categoryId)
                    ->pluck('target_id')
                    ->all();
                $bookings->whereIn("$bookingTable.object_id", $spaceIds ?: [-1]);
            }

            /* -------- Id / Amount -------- */
            if (!empty($bookingId)) {
                $bookings->where("$bookingTable.id", $bookingId);
            }
            if (!empty($amountLike)) {
                $bookings->where("$bookingTable.total", 'like', '%'.$amountLike.'%');
            }

            /* -------- Archived scope -------- */
            if ($showOnlyArchived) {
                $bookings->where("$bookingTable.is_archive", 1);
            } else {
                $bookings->where(function (Builder $qq) use ($bookingTable) {
                    $qq->whereNull("$bookingTable.is_archive")->orWhere("$bookingTable.is_archive", '!=', 1);
                });
            }

            /* -------- Totals (clone before paginate) -------- */
            $totalsQuery = (clone $bookings);
            $grandTotal  = (float) $totalsQuery->sum("$bookingTable.total");
            $hostTotal   = (float) (clone $bookings)->sum("$bookingTable.host_amount");

            /* -------- Paginate -------- */
            $paginator = $bookings->paginate($perPage, ['*'], 'page', $page);

            /* -------- Map items -------- */
            $data = collect($paginator->items())->map(function (Booking $b) {
                $space    = Space::find($b->object_id);
                $customer = User::find($b->customer_id);
                $payment  = Payment::where('booking_id', $b->id)->first();
                $catIds   = SpaceTerm::where('target_id', $b->object_id)->pluck('term_id')->all();

                // Use your helpers if available; otherwise format raw
                $fmt = function ($val) {
                    if (function_exists('format_price')) return format_price($val);
                    return is_null($val) ? '-' : '$'.number_format((float)$val, 2);
                };

                return [
                    'id'                     => $b->id,
                    'code'                   => $b->code,
                    'object_id'              => $b->object_id,
                    'space_title'            => $space->title ?? null,
                    'space_address'          => $space->address ?? null,
                    'category_ids'           => $catIds,
                    'customer'               => $customer ? [
                        'id'   => $customer->id,
                        'name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
                    ] : null,
                    'status'                 => $b->status,
                    'status_text'            => $this->statusText($b->status),
                    'status_class'           => $this->statusClass($b->status),
                    'transaction_status'     => ($payment && $payment->status === 'completed') ? 'PAID' : 'UNPAID',
                    'start_date'             => $b->start_date,
                    'end_date'               => $b->end_date,
                    'total'                  => (float) $b->total,
                    'total_formatted'        => $fmt($b->total),
                    'host_amount'            => (float) $b->host_amount,
                    'host_amount_formatted'  => $fmt($b->host_amount),
                    'is_archive'             => (int)($b->is_archive ?? 0),
                ];
            })->values();

            // Build meta via ApiResponse helper
            $meta = $this->withPaginationMeta($paginator, [
                'totals' => [
                    'grand_total' => function_exists('format_price') ? format_price($grandTotal) : '$'.number_format($grandTotal, 2),
                    'host_total'  => function_exists('format_price') ? format_price($hostTotal)  : '$'.number_format($hostTotal, 2),
                    'quantity'    => $paginator->total(),
                ],
            ]);

            return $this->ok($data, 'Bookings fetched', $meta);

        } catch (\Throwable $e) {
            // You’ll get a trace_id header + error body via ApiResponse
            return $this->serverError('Failed to fetch bookings', [
                'exception' => class_basename($e),
                'message'   => $e->getMessage(),
                // In prod you might omit file/line/trace:
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    

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
}