<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Term extends Model
{
   //
   protected $table = 'bravo_terms';
   protected $fillable = ['id','name','slug'];

}