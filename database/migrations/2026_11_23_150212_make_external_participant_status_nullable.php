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
        Schema::table('external_participants', function (Blueprint $table): void {
            $table->boolean('is_active')->nullable()->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('external_participants')->whereNull('is_active')->exists()) {
            throw new RuntimeException('Set a status for every external participant before restoring the required status constraint.');
        }

        Schema::table('external_participants', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->change();
        });
    }
};
