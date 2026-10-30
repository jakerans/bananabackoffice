<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wording of Banana's customer agreement, in versions
 * (docs/slice-33-agreement-plan.md, step 33a; decisions 210 and 211). One
 * wording for the company; a new version never changes one already signed.
 * The body is plain text in numbered sections, never web code.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('agreement_wordings')) {
            Schema::create('agreement_wordings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('version');
                $table->string('title', 200);
                $table->longText('body');
                $table->string('source', 20);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->unique('version', 'agreement_wordings_version_unique');
                $table->foreign('created_by', 'agreement_wordings_created_by_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_wordings');
    }
};
