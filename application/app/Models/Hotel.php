<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class Hotel extends Bookable
{
    protected $table = 'bravo_hotels';
    public string $type = 'hotel';

    protected $casts = [
        'service_fee' => 'array',   // vendor service fee rules (optional)
        'extra_price' => 'array',   // attach if you store hotel-level extras
    ];

    /**
     * POST /api/bookings/add-to-cart entrypoint (called by your BookingController).
     */
    public function addToCart(Request $request)
    {
        // 1) Validate hotel-specific payload
        if (!$this->addToCartValidate($request)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation error',
                'errors'  => $this->validationErrors ?? [],
            ], 422);
        }


        // 2) Pull normalized timestamps (your controller sets these)
        $startAt = $request->string('start_at')->toString();
        $endAt   = $request->string('end_at')->toString();

        // Nights (ceil so partial days count as 1)
        $nights = max(1, (int)ceil((strtotime($endAt) - strtotime($startAt)) / 86400));

        // 3) Base rate (use sale if available)
        $baseRate = (float)($this->discounted_daily ?? $this->sale_price ?? $this->price ?? 0.0);
        if ($baseRate < 0) $baseRate = 0.0;

        // Rooms math (sum number_selected; default 1 if rooms not provided)
        $rooms = (array)$request->input('rooms', []);
        $totalRoomsSelected = 0;
        foreach ($rooms as $r) {
            $totalRoomsSelected += max(0, (int)($r['number_selected'] ?? 0));
        }
        if ($totalRoomsSelected === 0) {
            $totalRoomsSelected = 1; // fallback
        }

        // Guests
        $adults   = max(0, (int)$request->input('adults', 1));
        $children = max(0, (int)$request->input('children', 0));
        $totalGuests = $adults + $children;

        // 4) Subtotal from rooms * nights * baseRate
        $subtotal = $baseRate * $nights * $totalRoomsSelected;

        // 5) Extra prices (optional UI-driven extras)
        [$extraTotal, $extraLines] = $this->computeExtras(
            (array)$request->input('extra_price', []),
            $nights,
            $totalGuests
        );

        $totalBeforeFees = $subtotal + $extraTotal;

        // 6) Fees
        $buyerFees   = $this->getSettingArray('hotel_booking_buyer_fees'); // from settings, if present
        $serviceFees = $this->service_fee ?? [];                            // vendor service fee rules on this Hotel

        $buyerFeeTotal   = $this->calculateServiceFees($buyerFees, $totalBeforeFees, $totalGuests);
        $serviceFeeTotal = $this->calculateServiceFees($serviceFees, $totalBeforeFees, $totalGuests);

        $grandTotal = $totalBeforeFees + $buyerFeeTotal + $serviceFeeTotal;

        // 7) Deposit (optional)
        $deposit = null;
        if ($this->isDepositEnabled()) {
            $deposit = $this->computeDeposit(
                amountBase: $this->getDepositFormula() === 'deposit_and_fee' ? $totalBeforeFees : $grandTotal,
                buyerFee: $buyerFeeTotal,
                vendorFee: $serviceFeeTotal
            );
        }

        // 8) Create booking (draft)
        $booking = new Booking([
            'status'              => 'draft',
            'object_id'           => $this->id,
            'object_model'        => $this->type,
            'vendor_id'           => (int)($this->create_user ?? 0),
            'customer_id'         => (int)(Auth::id() ?? 0),
            'total'               => $grandTotal,
            'total_before_fees'   => $totalBeforeFees,
            // 'total_before_tax'    => $totalBeforeFees,
            'total_before_discount' => $totalBeforeFees,
            'start_date'          => $startAt,
            'end_date'            => $endAt,
            'total_guests'        => $totalGuests,
            // optional JSON columns if you have them:
            'buyer_fees'          => !empty($buyerFees) ? $buyerFees : null,
            'vendor_service_fee'  => !empty($serviceFees) ? $serviceFees : null,
            'vendor_service_fee_amount' => $serviceFeeTotal,
            // 'deposit' handled below if your bookings table has it
        ]);
        if (!is_null($deposit)) {
            $booking->deposit = $deposit;
        }

        $booking->save();

        // 9) Optionally: create HotelRoomBooking rows if rooms[] provided
        if (!empty($rooms)) {
            foreach ($rooms as $r) {
                $roomId = (int)($r['id'] ?? 0);
                $qty    = max(0, (int)($r['number_selected'] ?? 0));
                if ($roomId > 0 && $qty > 0) {
                    HotelRoomBooking::create([
                        'room_id'    => $roomId,
                        'parent_id'  => $this->id,
                        'start_date' => $startAt,
                        'end_date'   => $endAt,
                        'number'     => $qty,
                        'booking_id' => $booking->id,
                        'price'      => $baseRate, // per-night price per room (adjust if you do room-specific pricing)
                    ]);
                }
            }
        }

        // 10) Optional booking meta (if your Booking model supports it)
        if (method_exists($booking, 'addMeta')) {
            $booking->addMeta('nights', $nights);
            $booking->addMeta('rooms_selected', $totalRoomsSelected);
            $booking->addMeta('adults', $adults);
            $booking->addMeta('children', $children);
            $booking->addMeta('extra_price', $extraLines);
            if (!is_null($deposit)) {
                $booking->addMeta('deposit_info', [
                    'type'    => $this->getDepositType(),
                    'amount'  => $this->getDepositAmount(),
                    'formula' => $this->getDepositFormula(),
                    'calculated' => $deposit,
                ]);
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Hotel added to cart',
            'data'    => [
                'booking_code' => $booking->code,
                'total'        => $grandTotal,
                'deposit'      => $deposit,
                'url'          => $booking->getCheckoutUrl($request->input('platform')),
            ],
        ]);
    }

    /**
     * Validate hotel payload. Return true if ok, else array{message,errors}.
     */
    protected function addToCartValidate(Request $request): bool
{
    $rules = [
        'start_at'  => ['required','date'],
        'end_at'    => ['required','date'],
        'adults'    => ['required','integer','min:1'],
        'children'  => ['required','integer','min:0'],

        // allow zeros during validation
        'rooms'                       => ['required','array'],
        'rooms.*.id'                  => ['required','integer','min:1'],
        'rooms.*.number_selected'     => ['required','integer','min:0'],
    ];

    $v = \Validator::make($request->all(), $rules);
    if ($v->fails()) {
        $this->validationErrors = $v->errors()->toArray();
        return false;
    }

    // now enforce "at least one room with qty >= 1"
    $rooms = (array)$request->input('rooms', []);
    $selected = array_filter($rooms, fn($r) => (int)($r['number_selected'] ?? 0) > 0);

    if (count($selected) === 0) {
        $this->validationErrors = ['rooms' => ['Please select at least one room']];
        return false;
    }

    // optionally write the filtered list back so downstream code only sees selected rooms
    $request->merge(['rooms' => array_values($selected)]);

    if (strtotime($request->input('end_at')) <= strtotime($request->input('start_at'))) {
        $this->validationErrors = ['end_at' => ['End time must be after start time']];
        return false;
    }

    return true;
}



    /* ---------------- helpers ---------------- */

    protected function computeExtras(array $extraInput, int $nights, int $guests): array
    {
        // Expected $extraInput like: { "<index>": { "enable": 1 }, ... }
        // If you also store rules on the hotel (e.g., $this->extra_price), you can merge here.
        $lines  = [];
        $total  = 0.0;

        // If you have hotel-level configured extras via $this->extra_price:
        $config = $this->extra_price ?? [];

        foreach ($config as $idx => $rule) {
            $enabled = (int)Arr::get($extraInput, $idx.'.enable', 0) === 1;
            if (!$enabled) continue;

            $type   = $rule['type']   ?? 'one_time'; // one_time | per_day
            $price  = (float)($rule['price'] ?? 0);
            $pp     = !empty($rule['per_person']);   // boolean-ish

            $line = [
                'name'       => $rule['name'] ?? ('Extra #'.$idx),
                'type'       => $type,
                'price'      => $price,
                'per_person' => $pp ? 1 : 0,
                'total'      => 0.0,
            ];

            $units = ($type === 'per_day') ? $nights : 1;
            $lineTotal = $price * $units;
            if ($pp) $lineTotal *= $guests;

            $line['total'] = $lineTotal;
            $total += $lineTotal;
            $lines[] = $line;
        }

        return [$total, $lines];
    }

    /**
     * Generic fee calculator used for both buyer fees and vendor service fees.
     * Supports items like:
     *   [ { "name": "...", "type": "percent"|"fixed", "amount": 10, "per_person": true } ... ]
     */
    protected function calculateServiceFees(?array $fees, float $base, int $qty = 1): float
    {
        $sum = 0.0;
        if (empty($fees)) {
            return 0.0;
        }

        foreach ($fees as $fee) {
            $amount = (float)($fee['amount'] ?? $fee['price'] ?? 0);
            $type   = $fee['type'] ?? 'fixed';  // 'fixed' or 'percent'
            $perPerson = !empty($fee['per_person']);

            // calculate base fee
            $value = $type === 'percent' ? ($base * $amount / 100.0) : $amount;

            // multiply if per_person or quantity > 1
            if ($perPerson || $qty > 1) {
                $value *= max(1, $qty);
            }

            $sum += $value;
        }

        return $sum;
    }


    /* ---- deposit (optional) ---- */

    protected function isDepositEnabled(): bool
    {
        return (bool)$this->getSetting('hotel_deposit_enable') && (float)$this->getSetting('hotel_deposit_amount');
    }

    protected function getDepositAmount(): float
    {
        return (float)$this->getSetting('hotel_deposit_amount', 0);
    }

    protected function getDepositType(): string
    {
        // 'percent' or 'fixed'
        return (string)$this->getSetting('hotel_deposit_type', 'percent');
    }

    protected function getDepositFormula(): string
    {
        // 'default' or 'deposit_and_fee'
        return (string)$this->getSetting('hotel_deposit_fomular', 'default');
    }

    protected function computeDeposit(float $amountBase, float $buyerFee, float $vendorFee): float
    {
        $type = $this->getDepositType();
        $amt  = $this->getDepositAmount();

        $deposit = ($type === 'percent')
            ? ($amountBase * $amt / 100.0)
            : $amt;

        if ($this->getDepositFormula() === 'deposit_and_fee') {
            $deposit += ($buyerFee + $vendorFee);
        }
        return max(0.0, $deposit);
    }

    /* ---- settings helpers (safe fallbacks for mobile project) ---- */

    protected function getSetting(string $key, mixed $default = null): mixed
    {
        // If you have setting_item() helper in mobile project, use that:
        if (function_exists('setting_item')) {
            $v = setting_item($key);
            return $v !== null ? $v : $default;
        }
        // Otherwise fallback to env/config or default
        return config("booking.$key", $default);
    }

    protected function getSettingArray(string $key): array
    {
        $raw = $this->getSetting($key, []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }
}