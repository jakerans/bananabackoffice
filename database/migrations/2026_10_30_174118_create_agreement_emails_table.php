<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each time the customer's copy of a signed agreement was to be emailed
 * (docs/slice-36-agreement-copy-plan.md, step 36a; decision 214): when the
 * customer signed, or again by staff. Kept whether it was sent or not, with
 * the address and why not.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('agreement_emails')) {
            return;
        }

        Schema::create('agreement_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agreement_id');
            $table->unsignedBigInteger('lead_id');
            $table->string('to_address')->nullable();
            $table->string('outcome', 30);
            $table->boolean('is_again')->default(false);
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('sent_by_name')->nullable();
            $table->timestamps();

            $table->index(['agreement_id', 'created_at'], 'agreement_emails_agreement_created_index');
            $table->foreign('agreement_id', 'agreement_emails_agreement_foreign')->references('id')->on('agreements')->restrictOnDelete();
            $table->foreign('lead_id', 'agreement_emails_lead_foreign')->references('id')->on('leads')->restrictOnDelete();
            $table->foreign('sent_by', 'agreement_emails_sent_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_emails');
    }
};
