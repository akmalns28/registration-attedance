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
        Schema::create('event_days', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            $table->unsignedSmallInteger('day_number');

            $table->date('date');

            $table->string('title', 150)->nullable();

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['event_id', 'day_number']);

            $table->unique(['event_id', 'date']);

            $table->index(['event_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_days');
    }
};
