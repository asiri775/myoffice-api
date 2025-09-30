<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    // Common names: 'media_files' or 'media'
    protected $table = 'media_files';
    public $timestamps = false;

    protected $fillable = ['id','file_name','file_path','file_size','file_type','driver'];

    // Convenience accessor if you ever switch to a CDN later
    public function getUrlAttribute(): ?string
    {
        return $this->file_path ? $this->file_path : null;
    }
}
