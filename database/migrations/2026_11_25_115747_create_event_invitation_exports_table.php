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
        Schema::create('event_invitation_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('participant_type', 16);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('participant_count');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedSmallInteger('batch_size');
            $table->unsignedInteger('batch_number')->default(0);
            $table->unsignedBigInteger('last_registration_id');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('peak_memory_bytes')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['event_id', 'requested_by', 'participant_type', 'created_at'], 'event_invitation_exports_lookup');
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_invitation_exports');
    }
};
