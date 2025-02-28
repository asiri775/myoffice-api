<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Space extends Model
{
   //
   protected $table = 'bravo_spaces';
   protected $fillable = ['id','title','slug','content','image_id','banner_image_id','location_id','address','address_unit','price','map_lat','map_lng'];

}