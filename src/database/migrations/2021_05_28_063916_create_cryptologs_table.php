<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCryptologsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crypto_logs', function (Blueprint $table) {
            $table->id();
            $table->string('transactions');
            $table->string('delegate_address');
            $table->string('beneficary_address');
            $table->bigInteger('delegate_balance');
            $table->integer('totalVoters');
            $table->integer('fee');
            $table->decimal('rate');
            $table->integer('amount');
            $table->boolean('succeed');
            $table->integer('hourCount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cryptologs');
    }
}
