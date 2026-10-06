<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "account_contacts" connects a Contact to an Account: one row per pair. A
     * person connected to ten Accounts has ten rows here and one Contact.
     *
     * Being the primary Contact is a mark on the connection, not a role.
     * "primary_for_account_id" is calculated by the database (the account's ID
     * on its primary row, otherwise empty) and is unique, so the database itself
     * refuses a second primary Contact for one Account.
     *
     * A connection is archived, never deleted, so it can be restored.
     *
     * "account_contact_roles" holds what the person is to that Account. A person
     * may be several things at once, such as Owner and Billing Contact, so each
     * role is its own row and none has to be chosen over another.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('account_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('contact_id');
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('primary_for_account_id')->nullable()->storedAs('case when is_primary = 1 then account_id end');
            $table->dateTime('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index('contact_id');
            $table->index('archived_by');
            $table->unique(['account_id', 'contact_id']);
            $table->unique('primary_for_account_id');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('account_contact_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_contact_id');
            $table->string('role', 30);
            $table->timestamps();

            $table->index('account_contact_id');
            $table->unique(['account_contact_id', 'role']);

            $table->foreign('account_contact_id')->references('id')->on('account_contacts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_contact_roles');
        Schema::dropIfExists('account_contacts');
    }
};
