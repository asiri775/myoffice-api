<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Media extends Model
{
   //
   protected $table = 'media_files';
   protected $fillable = ['id','file_path','file_name'];

}