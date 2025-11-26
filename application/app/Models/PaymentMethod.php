<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentMethod extends Model
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

    protected $hidden = [
        'ccv',
        'backpocket_password',
        'card_number',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }


    /**
     * Get decrypted card number
     */
    public function getDecryptedCardNumber(): ?string
    {
        if (!$this->card_number) {
            return null;
        }
        try {
            return Crypt::decryptString($this->card_number);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get masked card number
     */
    public function getMaskedCardNumber(): ?string
    {
        $cardNumber = $this->getDecryptedCardNumber();
        if (!$cardNumber) {
            return null;
        }
        // Remove spaces and mask all but last 4 digits
        $cleaned = preg_replace('/\s+/', '', $cardNumber);
        if (strlen($cleaned) < 4) {
            return $cardNumber;
        }
        $last4 = substr($cleaned, -4);
        return '**** **** **** ' . $last4;
    }
}

