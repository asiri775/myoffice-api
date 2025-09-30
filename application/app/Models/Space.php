<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Space extends Model
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
}