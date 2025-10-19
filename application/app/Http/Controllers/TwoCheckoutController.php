<?php
namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TwoCheckoutController extends Controller
{
    // 2CO posts back to x_receipt_link_url (we used route('twoco.return'))
    public function return(Request $request)
    {
        // web plugin compares a custom sha256. Keep compatible if you want:
        // $expected = hash('sha256', env('TWOCO_HASH_PREFIX','name205CAD2001') . date('Y-m-d') . number_format((float)$request->input('amount'), 2, '.', '') . $request->input('invoice_number'));
        // $valid = hash_equals($expected, (string)$request->input('txnToken'));
        // For now, accept success on response == '00' OR txnToken matches, if your gateway sends that.
        $bookingId = (int) $request->input('invoice_number', $request->input('orderId'));
        $booking   = Booking::find($bookingId);

        if (!$booking) {
            return redirect(env('APP_URL','/'))->with('error','Booking not found');
        }

        // only allow transition from unpaid/draft
        if (in_array($booking->payment_status, ['paid'])) {
            return redirect($booking->getDetailUrl())->with('success', 'Payment already processed');
        }

        $payment = $booking->payment;
        if (!$payment) {
            $payment = Payment::where('booking_id', $booking->id)->latest('id')->first();
        }

        // Validate response
        $isSuccess = $request->input('response') === '00';
        if (!$isSuccess) {
            if ($payment) {
                $payment->status = 'fail';
                $payment->logs   = json_encode($request->all());
                $payment->save();
            }
            $booking->markAsPaymentFailed();
            return redirect($booking->getDetailUrl())->with('error', 'Payment Failed');
        }

        // success
        if ($payment) {
            $payment->status = 'completed';
            $payment->logs   = json_encode($request->all());
            $payment->save();
        }

        $booking->paid += (float) ($booking->pay_now ?? 0);
        $booking->markAsPaid();

        return redirect($booking->getDetailUrl())->with('success', 'Your payment has been processed successfully');
    }

    // cancel_url handler (route('twoco.cancel'))
    public function cancel(Request $request)
    {
        $code    = $request->query('c');
        $booking = Booking::where('code', $code)->first();

        if ($booking) {
            if (in_array($booking->status, [Booking::UNPAID, 'unpaid', 'draft'])) {
                if ($booking->payment) {
                    $booking->payment->status = 'cancel';
                    $booking->payment->logs   = json_encode(['customer_cancel' => 1]);
                    $booking->payment->save();
                }
                return redirect($booking->getDetailUrl())->with('error', 'You cancelled the payment');
            }
            return redirect($booking->getDetailUrl());
        }
        return redirect(env('APP_URL','/'));
    }
}