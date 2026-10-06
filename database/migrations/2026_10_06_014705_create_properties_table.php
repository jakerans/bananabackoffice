<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Property is a physical place where work could occur. It belongs to one
     * Account. It has no location of the company's: where a property is never
     * decides who may see it, and never restricts who may.
     *
     * The address is kept in the same parts a Lead keeps, so a Lead's address can
     * be carried over as it is. "address_normalized" is a simplified form of the
     * street, unit and ZIP code, for recognizing the same address typed another way.
     *
     * A Property is archived, never deleted.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('label')->nullable();
            $table->string('type', 20);
            $table->string('address');
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
            $table->string('address_normalized')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('address_postal_code');
            $table->index('address_provider_place_id');
            $table->index('address_normalized');
            $table->index('archived_by');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
