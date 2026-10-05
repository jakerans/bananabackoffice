<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The property address of an inquiry, in parts. It is filled either from an
     * address lookup, which also gives coordinates and the provider's identifier
     * for the place, or by hand, which gives neither. "address_entry" says which.
     * The same parts will describe a Property, so a Lead's address can become or
     * be matched to one without being typed again.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();
            $table->string('address_country', 2)->nullable();
            $table->decimal('address_latitude', 10, 7)->nullable();
            $table->decimal('address_longitude', 10, 7)->nullable();
            $table->string('address_accuracy', 30)->nullable();
            $table->string('address_provider', 30)->nullable();
            $table->string('address_provider_place_id')->nullable();
            $table->string('address_entry', 20)->nullable();

            $table->index('address_postal_code');
            $table->index('address_provider_place_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['address_postal_code']);
            $table->dropIndex(['address_provider_place_id']);

            $table->dropColumn([
                'address_line1', 'address_line2', 'address_city', 'address_state', 'address_postal_code', 'address_country',
                'address_latitude', 'address_longitude', 'address_accuracy', 'address_provider', 'address_provider_place_id', 'address_entry',
            ]);
        });
    }
};
