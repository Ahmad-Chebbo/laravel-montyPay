<?php

use AhmadChebbo\LaravelMontypay\Http\Controllers\MontyPayController;
use Illuminate\Support\Facades\Route;

// Server-to-server webhook. Registered without the `web` group so there is no
// session or CSRF check; authenticity comes from the callback hash.
Route::post('callback', [MontyPayController::class, 'callback'])->name('montypay.callback');
