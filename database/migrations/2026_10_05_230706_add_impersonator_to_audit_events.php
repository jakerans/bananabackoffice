<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An Owner may view Backoffice as another staff member. A change made that
     * way is recorded under the identity it was made as (the actor) and also
     * names the real Owner who was signed in, so the history never says the
     * staff member did it alone. Both are empty for an ordinary change.
     *
     * The name is copied, like the actor's, so the record still reads correctly
     * if the account is renamed later.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->string('impersonator_name')->nullable();

            $table->index('impersonator_id');
            $table->foreign('impersonator_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropForeign(['impersonator_id']);
            $table->dropIndex(['impersonator_id']);
            $table->dropColumn(['impersonator_id', 'impersonator_name']);
        });
    }
};
