<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Space extends Bookable
{
    protected $table = 'bravo_spaces';

    protected $fillable = [
        'title','slug','content','image_id','banner_image_id','location_id',
        'address','address_unit','city','state','country','zip',
        'price','sale_price','discount',
        'hourly','daily','weekly','monthly',
        'discounted_hourly','discounted_daily','discounted_weekly','discounted_monthly',
        'map_lat','map_lng','map_zoom',
        'faqs','extra_price','service_fee','discount_by_days',
        'allow_children','allow_infant','max_guests','enable_extra_price',
        'enable_service_fee','status','review_score',
        'available_from','available_to','first_working_day','last_working_day',
        'min_day_before_booking','min_day_stays','min_hour_stays',
        'hours_after_full_day','house_rules','tos','total_bookings','image_url'
    ];

    protected $casts = [
        'faqs'             => 'array',
        'extra_price'      => 'array',
        'service_fee'      => 'array',
        'discount_by_days' => 'array',
        'allow_children'   => 'boolean',
        'allow_infant'     => 'boolean',
        'enable_extra_price' => 'boolean',
        'enable_service_fee' => 'boolean',
        'map_lat'          => 'float',
        'map_lng'          => 'float',
        'review_score'     => 'float',
    ];

    public function terms()
    {
        return $this->hasMany(SpaceTerm::class, 'target_id');
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'publish');
    }


    public function addToCart(Request $request)
    {
        $this->addToCartValidate($request);


        $startAt = $request->input('start_at'); // 'Y-m-d H:i:s'
        $endAt   = $request->input('end_at');   // 'Y-m-d H:i:s'
        if (!$startAt || !$endAt) {
            return response()->json([
                'status' => 'error',
                'code'   => 422,
                'trace_id' => (string) Str::uuid(),
                'message' => 'Start and end time are required',
                'data'    => null
            ], 422);
        }

        $adults   = (int) $request->input('adults', 1);
        $children = (int) $request->input('children', 0);
        $guests   = max(1, $adults + $children);


        $durationHours = max(1, (int)ceil((strtotime($endAt) - strtotime($startAt)) / 3600));
        $hourRate   = (float) ($this->discounted_hourly  ?? $this->hourly  ?? 0);
        $dayRate    = (float) ($this->discounted_daily   ?? $this->daily   ?? 0);
        $weekRate   = (float) ($this->discounted_weekly  ?? $this->weekly  ?? 0);
        $monthRate  = (float) ($this->discounted_monthly ?? $this->monthly ?? 0);

        $base = 0.0;
        if ($hourRate > 0) {
            $base = $durationHours * $hourRate;
        } else {
            $durationDays = max(1, (int)ceil($durationHours / 24));
            if ($dayRate > 0) {
                $base = $durationDays * $dayRate;
            } elseif ($weekRate > 0) {
                $weeks = max(1, (int)ceil($durationDays / 7));
                $base = $weeks * $weekRate;
            } elseif ($monthRate > 0) {
                $months = max(1, (int)ceil($durationDays / 28));
                $base = $months * $monthRate;
            } else {
                // fallback to generic price
                $base = (float) ($this->sale_price ?? $this->price ?? 0);
                if ($base <= 0) {
                    $base = 0.0; // allow 0 if your inventory is free or misconfigured
                }
            }
        }

        
        $buyerFees = $this->service_fee; // e.g. same schema as web: [{unit,price,name},...]
        $buyerFeeTotal = $this->calculateServiceFees($buyerFees, $base, $guests);

        $total = round($base + $buyerFeeTotal, 2);

        // --- Create draft booking ---
        $booking = new Booking();
        $booking->status         = 'draft';
        $booking->object_id      = (int) $request->input('service_id');
        $booking->object_model   = (string) $request->input('service_type'); // 'space'
        $booking->vendor_id      = (int) ($this->create_user ?? 0);
        $booking->customer_id    = auth()->id();
        $booking->total          = $total;
        $booking->total_guests   = $guests;
        $booking->start_date     = $startAt;
        $booking->end_date       = $endAt;
        $booking->total_before_fees = $base;
        $booking->total_before_discount = $base;

        $booking->save();

        // now meta rows
        $booking->addMeta('items', [
            ['type' => 'base', 'quantity' => 1, 'rate' => number_format($base, 2, '.', ''), 'total' => $base],
        ]);
        $booking->addMeta('buyer_fees', $buyerFees ?? []);
        $booking->addMeta('buyer_fees_amount', $buyerFeeTotal);
        $booking->addMeta('adults', $adults);
        $booking->addMeta('children', $children);

        // Optionally purge old drafts
        Booking::clearDraftBookings();

        $platform = $request->input('platform');

        return response()->json([
            'status'  => 'success',
            'code'    => 200,
            'trace_id'=> (string) Str::uuid(),
            'message' => 'Added to cart',
            'data'    => [
                'booking_code' => $booking->code,
                'url'          => $booking->getCheckoutUrl($platform),
                'total'        => $total,
            ],
        ], 200);
    }
}