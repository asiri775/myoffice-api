<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserPaymentMethod extends Model
{
    protected $table = 'user_payment_methods';

    protected $fillable = [
        'user_id',
        'type',
        'cardholder_name',
        'card_number',
        'expiry_date',
        'ccv',
        'backpocket_email',
        'backpocket_password',
        'is_default',
    ];
}


