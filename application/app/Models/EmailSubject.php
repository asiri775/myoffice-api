<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSubject extends Model
{
    protected $connection = 'mailserver';
    protected $table = 'email_subjects';
    public $timestamps = false;

    // columns: id, token, subject, ... (no 'code')
    protected $fillable = ['token', 'subject'];
}
