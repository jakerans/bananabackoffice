<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A note is what a staff member wrote about a record, in their own words.
     * It belongs to one record: a Lead today, and a Project, Account or Property
     * when those are built. It has no location of its own; whoever may see the
     * record may see its notes.
     *
     * A note is never edited and never deleted. It can be removed from view, in
     * which case who removed it and when are kept here.
     *
     * "impersonator_id" is filled only when an Owner wrote the note while viewing
     * Backoffice as the author. Screens do not show it; an audit event recorded
     * with the note is what the history relies on.
     *
     * Each foreign key gets an index of its own (see docs/decisions.md, 21).
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');
            $table->text('body');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('impersonator_id')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestamps();

            $table->index('author_id');
            $table->index('impersonator_id');
            $table->index('removed_by');

            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('impersonator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('removed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
