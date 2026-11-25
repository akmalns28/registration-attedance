<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 10);
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'created_at']);
            $table->index(['event_id', 'media_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_media');
    }
};
