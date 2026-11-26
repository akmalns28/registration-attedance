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
        Schema::table('event_posters', function (Blueprint $table) {
            $table->boolean('is_event_wide')->default(true);
        });

        Schema::create('event_day_event_poster', function (Blueprint $table) {
            $table->foreignUuid('event_poster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_day_id')->constrained()->cascadeOnDelete();
            $table->primary(['event_poster_id', 'event_day_id']);
            $table->index('event_day_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_day_event_poster');

        Schema::table('event_posters', function (Blueprint $table) {
            $table->dropColumn('is_event_wide');
        });
    }
};
