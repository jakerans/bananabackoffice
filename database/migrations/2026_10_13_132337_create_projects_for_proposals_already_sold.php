<?php

use App\Support\Addresses\AddressKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A proposal could be marked Sold for a day before Projects existed (step
     * 7c came before step 8a). Each one that is sold now, counts, and has no
     * Project is given one here, exactly as a sale gives it today: the Lead's
     * Location, customer, contact, property, services and size, the
     * proposal's sold amount and day, and the salesperson the Lead is with.
     * "Recorded by" is whoever recorded the sale.
     *
     * It writes plain rows and no audit event: the sale itself is already in
     * the audit record. A proposal that already has a Project is left alone,
     * so the step can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('projects') || ! Schema::hasColumn('proposals', 'result')) {
            return;
        }

        $sold = DB::table('proposals')
            ->where('result', 'sold')
            ->whereNull('added_in_error_at')
            ->whereNotIn('id', DB::table('projects')->select('proposal_id'))
            ->orderBy('id')
            ->get();

        foreach ($sold as $proposal) {
            $lead = DB::table('leads')->where('id', $proposal->lead_id)->first();

            if ($lead === null || $lead->location_id === null || $lead->account_id === null || $lead->property_id === null) {
                continue;
            }

            $services = DB::table('lead_service')->where('lead_id', $lead->id)->get();
            $main = $services->firstWhere('is_primary', 1) ?? $services->firstWhere('is_primary', true);
            $service = $main === null ? null : DB::table('services')->where('id', $main->service_id)->value('name');
            $property = DB::table('properties')->where('id', $lead->property_id)->first();
            $street = $property === null ? null : ($property->address_line1 === null ? $property->address : AddressKey::shortStreet($property->address_line1));

            $title = ($service ?? $proposal->name).($street === null || $street === '' ? '' : ' — '.$street);

            if (DB::table('projects')->where('lead_id', $lead->id)->where('title', $title)->exists()) {
                $title .= ' ('.$proposal->name.')';
            }

            $projectId = DB::table('projects')->insertGetId([
                'proposal_id' => $proposal->id,
                'lead_id' => $lead->id,
                'location_id' => $lead->location_id,
                'account_id' => $lead->account_id,
                'contact_id' => $lead->contact_id,
                'property_id' => $lead->property_id,
                'title' => mb_substr($title, 0, 160),
                'project_size_id' => $lead->project_size_id,
                'project_size_min_value' => $lead->project_size_min_value,
                'project_size_max_value' => $lead->project_size_max_value,
                'sold_cents' => $proposal->sold_cents ?? $proposal->price_cents,
                'sold_at' => $proposal->result_at ?? $proposal->updated_at ?? now(),
                'salesperson_id' => $lead->assigned_user_id,
                'salesperson_name' => $lead->assigned_user_id === null ? null : DB::table('users')->where('id', $lead->assigned_user_id)->value('name'),
                'created_by' => $proposal->result_by,
                'created_by_name' => $proposal->result_by_name,
                'production_state' => 'new_from_sales',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($services as $row) {
                DB::table('project_service')->insert([
                    'project_id' => $projectId,
                    'service_id' => $row->service_id,
                    'is_primary' => (bool) $row->is_primary,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * A Project is never deleted. The ones this step made are left as they are.
     */
    public function down(): void
    {
        //
    }
};
