<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Contact is a person. The same person may be connected to several
     * Accounts, so their details are kept once, here.
     *
     * The phone numbers and email are stored as typed, with a normalized form
     * beside each for recognizing the same value written another way. They are
     * signals for a person to weigh, never an identity: none is unique, because
     * spouses share an email address and an office shares a phone number.
     *
     * A Contact is archived, never deleted.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('alternate_phone', 50)->nullable();
            $table->string('alternate_phone_normalized', 20)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();

            $table->index('email_normalized');
            $table->index('phone_normalized');
            $table->index('alternate_phone_normalized');
            $table->index('last_name');
            $table->index('archived_by');

            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
