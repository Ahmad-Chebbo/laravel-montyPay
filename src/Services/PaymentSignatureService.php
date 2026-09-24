<?php

namespace AhmadChebbo\LaravelMontypay\Services;

use AhmadChebbo\LaravelMontypay\Support\Credentials;

/**
 * Checkout hash signatures: sha1(md5|sha256(uppercase(values . password))), values joined with no delimiter.
 * Request signatures use mb_strtoupper; callback and return signatures use strtoupper.
 *
 * @see https://docs.montypay.com/docs/requests/hash_signature
 */
class PaymentSignatureService
{
    public static function generateAuthenticationSignature($orderNumber, $orderAmount, $orderCurrency, $orderDescription, $password, ?string $algorithm = null): string
    {
        return self::sign([$orderNumber, $orderAmount, $orderCurrency, $orderDescription], $password, true, $algorithm);
    }

    public static function generateTransactionStatusSignatureByPaymentId($paymentId, $password, ?string $algorithm = null): string
    {
        return self::sign([$paymentId], $password, true, $algorithm);
    }

    public static function generateTransactionStatusSignatureByOrderId($orderId, $password, ?string $algorithm = null): string
    {
        return self::sign([$orderId], $password, true, $algorithm);
    }

    /** Capture and refund. */
    public static function generateRefundSignature($paymentId, $amount, $password, ?string $algorithm = null): string
    {
        return self::sign([$paymentId, $amount], $password, true, $algorithm);
    }

    /** Void and retry. */
    public static function generateVoidSignature($paymentId, $password, ?string $algorithm = null): string
    {
        return self::sign([$paymentId], $password, true, $algorithm);
    }

    /** Note: the order currency is not part of the recurring signature. */
    public static function generateRecurringSignature($recurringInitTransId, $recurringToken, $orderId, $amount, $description, $password, ?string $algorithm = null): string
    {
        return self::sign([$recurringInitTransId, $recurringToken, $orderId, $amount, $description], $password, true, $algorithm);
    }

    /** Card credit (payout): the flat order_* request fields. */
    public static function generateCardCreditSignature($orderId, $orderAmount, $orderCurrency, $orderDescription, $password, ?string $algorithm = null): string
    {
        return self::sign([$orderId, $orderAmount, $orderCurrency, $orderDescription], $password, true, $algorithm);
    }

    /** success_url / cancel_url `hash`: payment_id, order_id, then amount, currency, description from your own record. */
    public static function generateReturnSignature($paymentPublicId, $orderNumber, $orderAmount, $orderCurrency, $orderDescription, $password, ?string $algorithm = null): string
    {
        return self::sign([$paymentPublicId, $orderNumber, $orderAmount, $orderCurrency, $orderDescription], $password, false, $algorithm);
    }

    /** Callback `hash` (`id` is the callback's public payment id). Defaults to the active environment's password. */
    public static function generateCallbackSignature($paymentPublicId, $orderNumber, $orderAmount, $orderCurrency, $orderDescription, ?string $password = null, ?string $algorithm = null): string
    {
        return self::sign(
            [$paymentPublicId, $orderNumber, $orderAmount, $orderCurrency, $orderDescription],
            $password ?? Credentials::for()->password,
            false,
            $algorithm
        );
    }

    protected static function sign(array $values, ?string $password, bool $multibyte, ?string $algorithm): string
    {
        $raw = implode('', array_map('strval', $values)) . $password;
        $raw = $multibyte ? mb_strtoupper($raw) : strtoupper($raw);

        return sha1(hash(($algorithm ?? config('montypay.hash_algorithm', 'md5')) === 'sha256' ? 'sha256' : 'md5', $raw));
    }
}
