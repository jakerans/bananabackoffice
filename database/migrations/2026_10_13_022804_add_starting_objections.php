<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The objections the list starts with (decision 129), so a result does
     * not need a typed note every time. They come first, in this order, and
     * "Other (see note)" follows them. Owner and Leadership change the list
     * from then on, on Lead & Sales Lists.
     *
     * @var list<string>
     */
    private const array STARTING = [
        'Price',
        'Wants to think it over',
        'Needs to talk to spouse or partner',
        'Getting other bids',
        'Timing',
        'Financing',
    ];

    /**
     * Run the migrations.
     *
     * One that is already there, by name, is kept and only put in its place.
     * Anything else already in the list keeps its order, after these. The
     * step can be run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('objections')) {
            return;
        }

        foreach (self::STARTING as $index => $name) {
            $existing = DB::table('objections')->where('name', $name)->value('id');

            if ($existing === null) {
                DB::table('objections')->insert([
                    'name' => $name,
                    'requires_note' => false,
                    'position' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('objections')->where('id', $existing)->update(['position' => $index + 1]);
            }
        }

        $others = DB::table('objections')->whereNotIn('name', self::STARTING)->orderBy('position')->orderBy('id')->pluck('id');

        foreach ($others as $index => $id) {
            DB::table('objections')->where('id', $id)->update(['position' => count(self::STARTING) + $index + 1]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * A list option is never deleted, because results may already use it. The
     * list is left as it is.
     */
    public function down(): void
    {
        //
    }
};
