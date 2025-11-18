<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['credit_card', 'backpocket_credit']);
            $table->string('cardholder_name')->nullable();
            $table->string('card_number', 32)->nullable();
            $table->string('expiry_date', 7)->nullable();
            $table->string('ccv', 10)->nullable();
            $table->string('backpocket_email')->nullable();
            $table->string('backpocket_password')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_payment_methods');
    }
};


