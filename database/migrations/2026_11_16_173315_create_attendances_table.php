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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_registration_id')->constrained('event_registrations')->restrictOnDelete();
            $table->foreignId('event_day_id')->constrained('event_days')->restrictOnDelete();
            $table->foreignId('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('checked_in_at');
            $table->timestamps();
            $table->unique(['event_registration_id', 'event_day_id'], 'attendances_registration_day_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
