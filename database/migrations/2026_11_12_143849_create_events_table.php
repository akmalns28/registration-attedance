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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('title', 200);

            $table->text('description')->nullable();

            $table->string('location')->nullable();

            $table->date('start_date');

            $table->date('end_date');

            $table->dateTime('registration_start_at')->nullable();

            $table->dateTime('registration_end_at')->nullable();

            $table->unsignedInteger('capacity')->nullable();

            $table->string('status', 30)->default('draft');

            $table->timestamps();

            $table->index(['status', 'start_date']);

            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
