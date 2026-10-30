<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed agreements (docs/slice-33-agreement-plan.md, step 33b; decisions
 * 210 and 211): the customer signs on the tablet for the option chosen, and
 * what they saw is kept as it was, with the version of the wording, the name
 * they typed and their signature. Never changed; marked void, with the
 * reason, when a manager corrects the sale.
 *
 * An appointment the customer signed at is marked run there and then; the
 * rest of its after-action report (other estimates, notes) is still due,
 * until the salesperson sends it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('agreements')) {
            Schema::create('agreements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('estimate_id');
                $table->unsignedBigInteger('estimate_option_id');
                $table->unsignedBigInteger('proposal_id');
                $table->unsignedBigInteger('appointment_id');
                $table->unsignedBigInteger('agreement_wording_id');
                $table->json('details');
                $table->unsignedBigInteger('total_cents');
                $table->unsignedBigInteger('deposit_cents');
                $table->string('signer_name', 200);
                $table->json('signature');
                $table->timestamp('signed_at');
                $table->unsignedBigInteger('signed_by')->nullable();
                $table->string('signed_by_name');
                $table->string('signed_ip', 45)->nullable();
                $table->string('signed_user_agent', 500)->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('voided_by_name')->nullable();
                $table->text('void_reason')->nullable();
                $table->timestamps();

                $table->index(['lead_id', 'signed_at'], 'agreements_lead_signed_index');
                $table->index('proposal_id', 'agreements_proposal_index');
                $table->index('appointment_id', 'agreements_appointment_index');
                $table->foreign('lead_id', 'agreements_lead_foreign')->references('id')->on('leads')->restrictOnDelete();
                $table->foreign('location_id', 'agreements_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('estimate_id', 'agreements_estimate_foreign')->references('id')->on('estimates')->restrictOnDelete();
                $table->foreign('estimate_option_id', 'agreements_estimate_option_foreign')->references('id')->on('estimate_options')->restrictOnDelete();
                $table->foreign('proposal_id', 'agreements_proposal_foreign')->references('id')->on('proposals')->restrictOnDelete();
                $table->foreign('appointment_id', 'agreements_appointment_foreign')->references('id')->on('appointments')->restrictOnDelete();
                $table->foreign('agreement_wording_id', 'agreements_wording_foreign')->references('id')->on('agreement_wordings')->restrictOnDelete();
                $table->foreign('signed_by', 'agreements_signed_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('voided_by', 'agreements_voided_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('appointments', 'rest_of_report_due')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->boolean('rest_of_report_due')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('appointments', 'rest_of_report_due')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropColumn('rest_of_report_due');
            });
        }

        Schema::dropIfExists('agreements');
    }
};
