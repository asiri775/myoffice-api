<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

class CreateUserPaymentMethodsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_payment_methods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('type'); // 'credit_card' or 'backpocket_credit'
            $table->string('cardholder_name')->nullable();
            $table->text('card_number')->nullable(); // encrypted
            $table->string('expiry_date')->nullable(); // MM/YY format
            $table->string('ccv')->nullable(); // encrypted
            $table->string('backpocket_email')->nullable();
            $table->text('backpocket_password')->nullable(); // encrypted
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_payment_methods');
    }
}

