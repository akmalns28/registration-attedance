<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registration_notifications', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Relationships
            |--------------------------------------------------------------------------
            */

            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();

            $table->foreignId('event_registration_id')->constrained('event_registrations')->restrictOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Notification Identity
            |--------------------------------------------------------------------------
            */

            $table->string('notification_type', 32);

            $table->string('dedupe_key', 191)->unique();

            /*
            |--------------------------------------------------------------------------
            | Recipient
            |--------------------------------------------------------------------------
            */

            $table->string('recipient_email')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Processing Status
            |--------------------------------------------------------------------------
            */

            $table->string('status', 32)->default('pending');

            $table->unsignedInteger('attempts')->default(0);

            /*
            |--------------------------------------------------------------------------
            | Scheduling & Delivery
            |--------------------------------------------------------------------------
            */

            $table->timestamp('scheduled_for')->nullable();

            $table->timestamp('queued_at')->nullable();

            $table->timestamp('last_attempt_at')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamp('failed_at')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Failure Information
            |--------------------------------------------------------------------------
            */

            $table->text('last_error')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Additional Metadata
            |--------------------------------------------------------------------------
            */

            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */

            $table->index(['event_id', 'notification_type'], 'ern_event_type_idx');

            $table->index(['event_registration_id', 'notification_type'], 'ern_registration_type_idx');

            $table->index(['status', 'scheduled_for'], 'ern_status_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registration_notifications');
    }
};
