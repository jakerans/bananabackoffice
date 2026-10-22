<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What a customer owes for a Project and what they paid
     * (docs/slice-17-customer-payments-plan.md, step 17a; decision 162;
     * product-architecture.md, 9.2, 10.4 and 10.6; decision 133).
     *
     * An invoice is the deposit or the final invoice as QuickBooks sent it:
     * its number, its total with tax, and the day it was sent. A payment is
     * money received against one invoice: how much, how, when, and the
     * processor's or QuickBooks' reference. Cash and check count only once
     * Finance approves them. Nothing is deleted: a wrong invoice is voided
     * and a wrong payment reversed, each with who, when and why.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('project_invoices')) {
            Schema::create('project_invoices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->string('kind', 20);
                $table->string('number', 40);
                $table->unsignedBigInteger('amount_cents');
                $table->date('sent_on');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('voided_by_name')->nullable();
                $table->text('voided_reason')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'kind'], 'project_invoices_project_kind_index');
                $table->index('recorded_by', 'project_invoices_recorded_by_index');
                $table->index('voided_by', 'project_invoices_voided_by_index');
                $table->foreign('project_id', 'project_invoices_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('recorded_by', 'project_invoices_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('voided_by', 'project_invoices_voided_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('project_payments')) {
            Schema::create('project_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('project_invoice_id');
                $table->unsignedBigInteger('amount_cents');
                $table->string('method', 20);
                $table->date('received_on');
                $table->string('reference', 100)->nullable();
                $table->boolean('needs_approval')->default(false);
                $table->dateTime('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->string('approved_by_name')->nullable();
                $table->dateTime('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reversed_by_name')->nullable();
                $table->text('reversed_reason')->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->timestamps();

                $table->index('project_id', 'project_payments_project_index');
                $table->index('project_invoice_id', 'project_payments_invoice_index');
                $table->index(['needs_approval', 'approved_at'], 'project_payments_approval_index');
                $table->index('recorded_by', 'project_payments_recorded_by_index');
                $table->index('approved_by', 'project_payments_approved_by_index');
                $table->index('reversed_by', 'project_payments_reversed_by_index');
                $table->foreign('project_id', 'project_payments_project_foreign')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('project_invoice_id', 'project_payments_invoice_foreign')->references('id')->on('project_invoices')->restrictOnDelete();
                $table->foreign('recorded_by', 'project_payments_recorded_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('approved_by', 'project_payments_approved_by_foreign')->references('id')->on('users')->nullOnDelete();
                $table->foreign('reversed_by', 'project_payments_reversed_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_payments');
        Schema::dropIfExists('project_invoices');
    }
};
