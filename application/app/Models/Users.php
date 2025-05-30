<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticableTrait;
class Users extends Model implements Authenticatable
{
   //
   use AuthenticableTrait;
   protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'password',
        'address',
        'address2',
        'phone',
        'birthday',
        'city',
        'state',
        'country',
        'zip_code',
        'last_login_at',
        'avatar_id',
        'bio',
        'business_name',
        'map_lat',
        'map_lng',
        'map_zoom',
        'instagram_link',
        'facebook_link',
        'site_link',
        'super_host'
    ];
   protected $hidden = [
    'password',
    'remember_token',
   ];

   protected $casts = [
    'email_verified_at' => 'datetime',
   ];
   /*
   * Get Todo of User
   *
   */
   public function Space()
   {
       return $this->hasMany('App\Space');
   }
}
