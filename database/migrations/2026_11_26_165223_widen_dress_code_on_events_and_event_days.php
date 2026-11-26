<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['events', 'event_days'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('dress_code')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'length' : 'char_length';
        $tables = ['events', 'event_days'];

        foreach ($tables as $name) {
            if (DB::table($name)->whereRaw("$length(dress_code) > ?", [150])->exists()) {
                throw new RuntimeException('Shorten Dress Code values that exceed 150 characters before rolling back.');
            }
        }

        foreach ($tables as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('dress_code', 150)->nullable()->change();
            });
        }
    }
};
