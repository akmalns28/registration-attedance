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
        Schema::create('event_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_day_id')->constrained('event_days')->cascadeOnDelete();

            $table->string('title', 200);

            $table->text('description')->nullable();

            $table->time('start_time');

            $table->time('end_time');

            $table->string('location')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['event_day_id', 'start_time']);

            $table->index(['event_day_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_schedules');
    }
};
