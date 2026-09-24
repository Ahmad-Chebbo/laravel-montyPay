<?php

namespace AhmadChebbo\LaravelMontypay\Http\Controllers;

use AhmadChebbo\LaravelMontypay\Events\PaymentCanceled;
use AhmadChebbo\LaravelMontypay\Events\PaymentFailed;
use AhmadChebbo\LaravelMontypay\Events\PaymentSuccessful;
use AhmadChebbo\LaravelMontypay\Facades\MontyPay;
use AhmadChebbo\LaravelMontypay\Http\Requests\MontyPayCallBackRequest;
use AhmadChebbo\LaravelMontypay\Services\CallbackProcessor;
use AhmadChebbo\LaravelMontypay\Services\PaymentSignatureService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;

class MontyPayController extends Controller
{
    public function success(Request $request)
    {
        return $this->returned($request, PaymentSuccessful::class, 'montypay::success');
    }

    public function cancel(Request $request)
    {
        return $this->returned($request, PaymentCanceled::class, 'montypay::cancel');
    }

    public function decline(Request $request)
    {
        return $this->returned($request, PaymentFailed::class, 'montypay::decline');
    }

    /**
     * Signed server-to-server callback (the source of truth).
     *
     * Payment completion is decided by type + status + order_status together;
     * e.g. type=3ds,status=success is NOT a completed payment.
     */
    public function callback(MontyPayCallBackRequest $request, CallbackProcessor $processor)
    {
        $payment = $request->all();

        if (config('montypay.callback.verify_signature') && ! $this->hasValidSignature($payment)) {
            abort(403, 'Invalid signature');
        }

        // Stores the callback, ignores replays, and fires the events inline or on the queue.
        // Always acknowledge fast: MontyPay never retries a callback.
        $processor->handle($payment);

        return response()->json(['status' => 'success']);
    }

    /**
     * Customer-return redirect. The return URL is NOT proof of payment: only the signed
     * callback is. When MontyPay's return hash is present it is checked, and a mismatch is refused.
     */
    protected function returned(Request $request, string $event, string $view)
    {
        $payment = $request->query();
        $payment['verified'] = MontyPay::verifyReturn($payment);

        abort_if($payment['verified'] === false, 403, 'Invalid return signature');

        Event::dispatch(new $event($payment));

        return view($view, compact('payment'));
    }

    protected function hasValidSignature(array $data): bool
    {
        $expected = PaymentSignatureService::generateCallbackSignature(
            $data['id'], $data['order_number'], $data['order_amount'], $data['order_currency'], $data['order_description']
        );

        return hash_equals($expected, (string) ($data['hash'] ?? ''));
    }
}
