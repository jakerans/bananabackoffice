<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Attachments (docs/slice-16-document-storage-plan.md, step 16a; decision
     * 33: one attachment model, in private storage of its own; decision 157).
     *
     * One row for each file attached to anything: what it belongs to, what
     * kind of file it is, where it is stored (always under a random name,
     * never the name it was uploaded with), the name it came with, its type
     * and size, and who added it. A removed file is hidden at once, with who
     * and why; the file is deleted from storage 30 days later. Its earlier
     * version, the backup, is deleted by the bucket's own rule 30 days after
     * that, never by the application. The row is kept.
     *
     * The step checks whether it is still needed, so it can be run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('attachments')) {
            return;
        }

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 60);
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind', 40);
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->string('removed_by_name')->nullable();
            $table->text('removed_reason')->nullable();
            $table->dateTime('purged_at')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id', 'kind'], 'attachments_attachable_index');
            $table->unique('path', 'attachments_path_unique');
            $table->index(['removed_at', 'purged_at'], 'attachments_removed_at_index');
            $table->index('uploaded_by', 'attachments_uploaded_by_index');
            $table->index('removed_by', 'attachments_removed_by_index');
            $table->foreign('uploaded_by', 'attachments_uploaded_by_foreign')->references('id')->on('users')->nullOnDelete();
            $table->foreign('removed_by', 'attachments_removed_by_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
