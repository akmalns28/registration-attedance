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
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('nik', 15)->change();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameColumn('nik', 'nip');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameIndex('employees_nik_unique', 'employees_nip_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('nip', 30)->change();
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameColumn('nip', 'nik');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->renameIndex('employees_nip_unique', 'employees_nik_unique');
        });
    }
};
