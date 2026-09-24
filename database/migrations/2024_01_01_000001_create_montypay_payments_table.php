<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montypay_payments', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('payable');           // your Order, Invoice, Subscription...
            $table->string('order_number')->index();     // order.number sent to MontyPay
            $table->string('payment_id')->nullable()->index(); // callback `id`; changes per payer attempt
            $table->string('operation', 20)->nullable(); // purchase | debit | transfer | credit

            // Kept exactly as sent to MontyPay: hashes are computed over these strings.
            $table->string('amount', 32)->nullable();
            $table->string('currency', 6)->nullable();
            $table->string('description', 1024)->nullable();

            $table->string('state', 24)->default('created')->index();
            $table->string('reason', 1024)->nullable();  // last decline / failure reason

            // Recurring chain, from the initial payment's callback
            $table->string('recurring_init_trans_id')->nullable();
            $table->text('recurring_token')->nullable(); // encrypted
            $table->string('schedule_id')->nullable();
            $table->string('consent_id')->nullable();
            $table->string('consent_state', 16)->nullable(); // active | cancelled | ignored
            $table->text('card_token')->nullable();      // encrypted

            $table->json('meta')->nullable();
            $table->timestamp('last_callback_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montypay_payments');
    }
};
