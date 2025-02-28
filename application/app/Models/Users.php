<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticableTrait;
class Users extends Model implements Authenticatable
{
   //
   use AuthenticableTrait;
   protected $fillable = ['username','email','password'];
   protected $hidden = [
   'password'
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