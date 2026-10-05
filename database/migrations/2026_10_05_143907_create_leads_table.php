<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Lead is one inquiry. The "inquiry_" columns hold what the person told us,
     * as received, and are never replaced by a customer's later details. A phone
     * number and an email are each kept twice: as typed, and in a normalized form
     * used only for matching. An empty location means the Lead is unrouted.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->dateTime('received_at');
            $table->string('status');

            $table->unsignedBigInteger('lead_channel_id');
            $table->unsignedBigInteger('lead_source_id');
            $table->unsignedBigInteger('marketing_source_id');

            $table->string('inquiry_name')->nullable();
            $table->string('inquiry_phone')->nullable();
            $table->string('inquiry_phone_normalized')->nullable();
            $table->string('inquiry_email')->nullable();
            $table->string('inquiry_email_normalized')->nullable();
            $table->string('inquiry_business_name')->nullable();
            $table->string('inquiry_address', 500)->nullable();
            $table->text('inquiry_message')->nullable();

            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->timestamps();

            $table->index('lead_channel_id');
            $table->index('lead_source_id');
            $table->index('marketing_source_id');
            $table->index('location_id');
            $table->index('captured_by');
            $table->index('inquiry_phone_normalized');
            $table->index('inquiry_email_normalized');
            $table->index('received_at');

            $table->foreign('lead_channel_id')->references('id')->on('lead_channels')->restrictOnDelete();
            $table->foreign('lead_source_id')->references('id')->on('lead_sources')->restrictOnDelete();
            $table->foreign('marketing_source_id')->references('id')->on('marketing_sources')->restrictOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('captured_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
