<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SpaceTerm extends Model
{
   //
   protected $table = 'bravo_space_term';
   protected $fillable = ['id','target_id','term_id'];

}