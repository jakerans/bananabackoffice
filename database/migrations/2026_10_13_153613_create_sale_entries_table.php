<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The record the Sales figures count money and wins from (decisions 133
     * and 134). One row is written when a proposal is marked Sold, and one
     * more, taking the same amount off, when a manager corrects that sale.
     * Rows are never changed or deleted, so a month that has passed is not
     * rewritten: a later correction is counted in the month it was made.
     *
     * - "salesperson" is who had the Lead at the sale: Close Rate works off
     *   lead ownership (decision 134). A correction carries the same person
     *   as the sale it takes back.
     * - "cents" is the sold amount for a sale and the same amount below zero
     *   for a correction.
     * - "is_lead_win": for a sale, whether it was the Lead's first standing
     *   sale, so one Lead is one win. For a correction, whether it left the
     *   Lead with no sale standing, which takes the win back.
     *
     * Every proposal that is sold now is given its sale row here, in the
     * order the sales were made. A sale that was corrected before this table
     * existed is not rebuilt.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sale_entries')) {
            Schema::create('sale_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('proposal_id');
                $table->unsignedBigInteger('lead_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('salesperson_id')->nullable();
                $table->string('salesperson_name')->nullable();
                $table->string('kind', 20);
                $table->bigInteger('cents');
                $table->boolean('is_lead_win')->default(false);
                $table->dateTime('occurred_at');
                $table->unsignedBigInteger('recorded_by')->nullable();
                $table->string('recorded_by_name')->nullable();
                $table->text('note')->nullable();
                $table->dateTime('created_at');

                $table->index('proposal_id');
                $table->index('lead_id');
                $table->index('project_id');
                $table->index(['location_id', 'occurred_at']);
                $table->index('salesperson_id');
                $table->index('recorded_by');

                $table->foreign('proposal_id')->references('id')->on('proposals')->restrictOnDelete();
                $table->foreign('lead_id')->references('id')->on('leads')->restrictOnDelete();
                $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
                $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('salesperson_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        $sold = DB::table('proposals')
            ->where('result', 'sold')
            ->whereNull('added_in_error_at')
            ->whereNotIn('id', DB::table('sale_entries')->select('proposal_id'))
            ->orderBy('result_at')
            ->orderBy('id')
            ->get();

        foreach ($sold as $proposal) {
            $lead = DB::table('leads')->where('id', $proposal->lead_id)->first();
            $project = DB::table('projects')->where('proposal_id', $proposal->id)->first();

            if ($lead === null || $lead->location_id === null) {
                continue;
            }

            $salespersonId = $project->salesperson_id ?? $lead->assigned_user_id;

            DB::table('sale_entries')->insert([
                'proposal_id' => $proposal->id,
                'lead_id' => $lead->id,
                'project_id' => $project?->id,
                'location_id' => $project->location_id ?? $lead->location_id,
                'salesperson_id' => $salespersonId,
                'salesperson_name' => $project->salesperson_name ?? ($salespersonId === null ? null : DB::table('users')->where('id', $salespersonId)->value('name')),
                'kind' => 'sale',
                'cents' => $proposal->sold_cents ?? $proposal->price_cents,
                // The Lead's first sale is its win; a later one adds revenue only.
                'is_lead_win' => ! DB::table('sale_entries')->where('lead_id', $lead->id)->exists(),
                'occurred_at' => $proposal->result_at ?? $proposal->updated_at ?? now(),
                'recorded_by' => $proposal->result_by,
                'recorded_by_name' => $proposal->result_by_name,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_entries');
    }
};
