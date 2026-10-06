<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A simplified form of a business's name, so "Lopez Bakery, LLC" and
     * "lopez bakery" can be recognized as possibly the same business when a Lead
     * is being matched to a customer. It only ever raises a suggestion.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('business_name_normalized')->nullable();

            $table->index('business_name_normalized');
        });

        DB::table('accounts')->whereNotNull('business_name')->orderBy('id')->each(function (object $account): void {
            DB::table('accounts')->where('id', $account->id)->update([
                'business_name_normalized' => Account::normalizeBusinessName($account->business_name),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['business_name_normalized']);
            $table->dropColumn('business_name_normalized');
        });
    }
};
