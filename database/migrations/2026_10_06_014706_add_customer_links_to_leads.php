<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Lead can be linked to the customer it turned out to be from: an Account,
     * one of that Account's Contacts, and one of its Properties. Each is empty
     * until it is known. Linking never changes the inquiry as it was received.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();

            $table->index('account_id');
            $table->index('contact_id');
            $table->index('property_id');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['property_id']);
            $table->dropIndex(['account_id']);
            $table->dropIndex(['contact_id']);
            $table->dropIndex(['property_id']);
            $table->dropColumn(['account_id', 'contact_id', 'property_id']);
        });
    }
};
