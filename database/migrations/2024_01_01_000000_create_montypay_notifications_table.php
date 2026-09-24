<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montypay_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id');
            $table->string('order_id')->nullable();
            $table->string('type', 36)->nullable();
            $table->string('status', 20);
            $table->string('order_status', 20)->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Callbacks may be replayed; dedupe on (id, type, status).
            $table->unique(['payment_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montypay_notifications');
    }
};
