<?php
namespace App\PaymentGateways;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Payment;

class TwoCheckoutGateway
{
    public const ID   = 'two_checkout_gateway';
    public const NAME = 'Two Checkout';

    public function process(Request $request, Booking $booking, bool $returnString = false)
    {
        if (in_array($booking->payment_status, ['paid'])) {
            throw new \RuntimeException("Booking status does not need to be paid");
        }
        if (!$booking->total) {
            throw new \RuntimeException("Booking total is zero. Can not process payment gateway!");
        }

        // Create/attach Payment row
        $payment = new Payment();
        $payment->booking_id      = $booking->id;
        $payment->payment_gateway = self::ID;
        $payment->status          = 'unpaid';
        $payment->amount          = (float) ($booking->pay_now ?? $booking->total);
        $payment->save();

        $booking->payment_id = $payment->id;
        $booking->save();

        // Prefer request→fallbacks→booking/config
        $currencyMain = (string) setting_item('currency_main', 'USD');
        $amount       = (float) ($request->input('amount', $booking->pay_now ?? $booking->total));
        $currency     = (string) $request->input('currency', $currencyMain);

        // Let these be configured or overridden per-request
        $merchantPgIdentifier = $request->input('merchantPgIdentifier', config('services.twoco.merchantPgIdentifier', 205));
        $secretId             = $request->input('secret_id',            config('services.twoco.secret_id',            2001));

        $args = [
            // Core 2CO fields
            'sid'                 => $payment->id,
            'paypal_direct'       => 'Y',
            'cart_order_id'       => $booking->code,
            'merchant_order_id'   => $booking->code,
            'total'               => (float)($booking->pay_now ?? $booking->total), // legacy 'total'
            'credits'             => $request->input('credits'),
            'return_url'          => route('twoco.cancel').'?c='.$booking->code,
            'x_receipt_link_url'  => route('twoco.return').'?c='.$booking->code,
            'currency_code'       => $currencyMain,    // legacy field kept
            'card_holder_name'    => trim(($booking->first_name ?? '').' '.($booking->last_name ?? '')),
            'street_address'      => (string) ($booking->address  ?? ''),
            'street_address2'     => (string) ($booking->address2 ?? ''), // use address2 (not address1)
            'city'                => (string) ($booking->city     ?? ''),
            'state'               => (string) ($booking->state    ?? ''),
            'country'             => (string) ($booking->country  ?? ''),
            'zip'                 => (string) ($booking->zip_code ?? ''),
            'phone'               => (string) ($booking->phone    ?? ''),
            'email'               => (string) ($booking->email    ?? ''),
            'lang'                => app()->getLocale(),

            // Missing fields you asked to include
            'merchantPgIdentifier'=> $merchantPgIdentifier,
            'secret_id'           => $secretId,
            'currency'            => $currency,
            'amount'              => $amount,

            // Pass-through/meta
            'orderId'             => $booking->id,
            'invoiceNumber'       => $booking->id,
            'customer_id'         => (string) ($booking->customer_id ?? ''),

            // Optional mobile/web callback extras
            'successUrl'          => route('twoco.return').'?c='.$booking->code,
            'errorUrl'            => route('twoco.cancel').'?c='.$booking->code,
            'storeName'           => $request->input('storeName'),
            'transactionType'     => $request->input('transactionType'),
            'timeout'             => $request->input('timeout'),
            'transactionDateTime' => $request->input('transactionDateTime'),
            'language'            => $request->input('language'),
            'txnToken'            => $request->input('txnToken'),
            'itemList'            => $request->input('itemList'),
            'otherInfo'           => $request->input('otherInfo'),
            'merchantCustomerPhone'=> $request->input('merchantCustomerPhone'),
            'merchantCustomerEmail'=> $request->input('merchantCustomerEmail'),
        ];

        // Build endpoint from config (sandbox/live)
        $base = config('services.twoco.sandbox', true)
            ? 'http://checkout.backpocket.ca/backpocket-payment/sandbox'
            : 'http://checkout.backpocket.ca/backpocket-payment/live';

        $url = $base.'?'.http_build_query($args, '', '&');

        if ($returnString) return $url;
        return response()->json(['url' => $url]);
    }
}
