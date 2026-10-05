<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which Location normally serves which postal code. This is the explicit
     * service-area rule: a Location is suggested for an address only because its
     * postal code is listed here, never because an office is nearby.
     *
     * Each row is one postal code for one Location. Territories drawn on a map
     * can be added later as a table of their own; Leads store only the Location
     * they end up with, so nothing about them changes.
     */
    public function up(): void
    {
        Schema::create('service_area_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->string('postal_code', 10);
            $table->timestamps();

            $table->index('location_id');
            $table->index('postal_code');
            $table->unique(['location_id', 'postal_code']);

            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_area_postal_codes');
    }
};
