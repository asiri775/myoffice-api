<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Booking extends Model
{
    protected $table = 'bravo_bookings';

    protected $fillable = [
        'code','object_id','object_model','vendor_id','customer_id',
        'status','total','total_before_fees','total_before_tax','total_before_discount',
        'buyer_fees','vendor_service_fee','vendor_service_fee_amount',
        'start_date','end_date','total_guests','host_amount','paid',

    ];

    protected $casts = [
        'buyer_fees'           => 'array',
        'vendor_service_fee'   => 'array',
        'start_date'           => 'datetime',
        'end_date'             => 'datetime',
        'total'                => 'float',
        'host_amount'          => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $b) {
            if (empty($b->code)) {
                $b->code = strtoupper(Str::random(10));
            }
            
        });
    }

    public function space()
    {
        return $this->belongsTo(Space::class, 'object_id')
                    ->where('object_model','space');
    }

    public function scopeAccepted($q)
    {
        return $q->whereNotIn('status', ['draft','failed','pending']);
    }

    /* ---------------- Meta lives in bravo_booking_meta ---------------- */

    public function addMeta(string $key, $val, bool $multiple = false)
    {
        if (is_array($val) || is_object($val)) {
            $val = json_encode($val);
        }

        if ($multiple) {
            return DB::table('bravo_booking_meta')->insert([
                'booking_id' => $this->id,
                'name'       => $key,
                'val'        => $val,
                'created_at' => Carbon::now(),
                'updated_at' =>  Carbon::now(),
            ]);
        }

        $old = DB::table('bravo_booking_meta')->where([
            'booking_id' => $this->id,
            'name'       => $key,
        ])->first();

        if ($old) {
            return DB::table('bravo_booking_meta')->where('id', $old->id)->update([
                'val'        => $val,
                'updated_at' => Carbon::now(),
            ]);
        }

        return DB::table('bravo_booking_meta')->insert([
            'booking_id' => $this->id,
            'name'       => $key,
            'val'        => $val,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function getMeta(string $key, $default = '')
    {
        $row = DB::table('bravo_booking_meta')->where([
            'booking_id' => $this->id,
            'name'       => $key,
        ])->first();

        return $row ? $row->val : $default;
    }

    public function getJsonMeta(string $key, $default = [])
    {
        $v = $this->getMeta($key, null);
        return $v === null ? $default : json_decode($v, true);
    }

    public function getAllMeta()
    {
        return DB::table('bravo_booking_meta')
            ->select(['name','val'])
            ->where('booking_id', $this->id)
            ->get();
    }

    /* --------------------------------------------------------------- */

    public function markAsPaid(): self
    {
        $this->status = 'paid';
        $this->is_paid = 1;
        $this->paid = $this->total ?? 0;
        $this->save();
        return $this;
    }

    public function getCheckoutUrl(?string $platform = null): string
    {
        $base = env('APP_URL', 'http://localhost');
        return $base.'/checkout/'.$this->code.($platform ? ('?platform='.$platform) : '');
    }

    public function getDetailUrl(): string
    {
        $base = env('APP_URL', 'http://localhost');
        return $base.'/booking/'.$this->code;
    }

    public static function clearDraftBookings(int $hours = 24): void
    {
        static::query()
            ->where('status', 'draft')
            ->where('created_at', '<', Carbon::now()->subHours($hours))
            ->delete();
    }
}