<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An Account is the party the company does business with: a household or a
     * business. Staff see it as a "Customer". It has no location: who may see it
     * is decided by the Leads (and later Projects) it has.
     *
     * "display_name" is what staff see and search. For a business it is the
     * business name; for a household it is the primary Contact's name. It is
     * kept up to date by the application.
     *
     * An Account is archived, never deleted.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('business_name')->nullable();
            $table->string('display_name');
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();

            $table->index('display_name');
            $table->index('archived_by');

            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
