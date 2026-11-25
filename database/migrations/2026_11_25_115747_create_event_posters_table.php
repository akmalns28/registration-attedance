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
        Schema::create('event_posters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->char('content_hash', 64);
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('primary_slot')->nullable()->storedAs('CASE WHEN is_primary THEN 1 ELSE NULL END');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['event_id', 'primary_slot']);
            $table->unique(['event_id', 'content_hash']);
            $table->index(['event_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_posters');
    }
};
