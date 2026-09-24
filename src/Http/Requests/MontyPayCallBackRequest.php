<?php

namespace AhmadChebbo\LaravelMontypay\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MontyPayCallBackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the fields the package relies on are required; every other callback
     * field (3DS, browser, consent, schedule, ...) passes through untouched.
     */
    public function rules(): array
    {
        return [
            'id' => 'required|string',
            'order_number' => 'required|string',
            'order_amount' => 'required',
            'order_currency' => 'required|string',
            'order_description' => 'required|string',
            'order_status' => 'required|string', // prepare, settled, pending, 3ds, redirect, decline, refund, reversal, void, chargeback
            'type' => 'required|string', // sale, capture, refund, void, recurring, debit, credit, transfer, 3ds, redirect, init, chargeback, reversal
            'status' => 'required|string', // success, fail, waiting, undefined
            'hash' => 'required|string',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation failed',
            'messages' => $validator->errors(),
        ], 422));
    }
}
