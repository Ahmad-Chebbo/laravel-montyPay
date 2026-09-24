<?php

use AhmadChebbo\LaravelMontypay\Http\Controllers\MontyPayController;
use Illuminate\Support\Facades\Route;

// Customer-facing redirects (registered with the `routes.middleware` group).
Route::get('success', [MontyPayController::class, 'success'])->name('montypay.success');
Route::get('cancel', [MontyPayController::class, 'cancel'])->name('montypay.cancel');
Route::get('decline', [MontyPayController::class, 'decline'])->name('montypay.decline');
