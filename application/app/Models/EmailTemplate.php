<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $connection = 'mailserver';
    protected $table = 'email_contents';
    public $timestamps = false;

    // columns: id, domain, subject_id, html/content, ...
    protected $fillable = ['domain', 'subject_id', 'html']; // use the actual html/content column name
}
