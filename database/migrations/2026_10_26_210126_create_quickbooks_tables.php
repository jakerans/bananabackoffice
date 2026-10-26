<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The QuickBooks connection and net profit (docs/slice-28-quickbooks-plan.md;
     * decision 189; product-architecture.md, 9.8).
     *
     * - Each Location's connection to its own QuickBooks Online company: the
     *   company, its keys (stored encrypted), when they expire, who connected
     *   it, and when it last could not be renewed. One company, one Location.
     * - Each Location's profit and loss for a month, as QuickBooks reported it
     *   when it was last read.
     * - Each Location's Net Profit % target for its scorecard.
     *
     * Each step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('quickbooks_connections')) {
            Schema::create('quickbooks_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->string('realm_id', 32);
                $table->string('company_name')->nullable();
                $table->text('access_token');
                $table->text('refresh_token');
                $table->timestamp('access_token_expires_at');
                $table->timestamp('refresh_token_expires_at')->nullable();
                $table->unsignedBigInteger('connected_by')->nullable();
                $table->string('connected_by_name')->nullable();
                $table->timestamp('connected_at');
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->unique('location_id', 'quickbooks_connections_location_unique');
                $table->unique('realm_id', 'quickbooks_connections_realm_unique');
                $table->index('connected_by', 'quickbooks_connections_connected_by_index');
                $table->foreign('location_id', 'quickbooks_connections_location_foreign')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('connected_by', 'quickbooks_connections_connected_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('location_profit_and_losses')) {
            Schema::create('location_profit_and_losses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('location_id');
                $table->char('month', 7);
                $table->bigInteger('income_cents');
                $table->bigInteger('cost_of_goods_cents');
                $table->bigInteger('expenses_cents');
                $table->bigInteger('net_operating_income_cents');
                $table->bigInteger('net_income_cents');
                $table->timestamp('read_at');
                $table->timestamps();

                $table->unique(['location_id', 'month'], 'location_profit_and_losses_month_unique');
                $table->foreign('location_id', 'location_profit_and_losses_location_foreign')->references('id')->on('locations')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('locations', 'net_profit_target_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedTinyInteger('net_profit_target_percent')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('locations', 'net_profit_target_percent')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('net_profit_target_percent');
            });
        }

        Schema::dropIfExists('location_profit_and_losses');
        Schema::dropIfExists('quickbooks_connections');
    }
};
