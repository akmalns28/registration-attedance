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
        Schema::table('events', function (Blueprint $table) {
            $table->string('dress_code', 150)->nullable();
        });

        Schema::table('event_days', function (Blueprint $table) {
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('dress_code', 150)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_days', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'dress_code']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('dress_code');
        });
    }
};
