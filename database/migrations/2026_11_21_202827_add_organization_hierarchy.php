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
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 20)->nullable()->change();
            $table->unique(['entity_id', 'code']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropUnique(['name']);
        });

        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('entities')->exists()
            || DB::table('divisions')->exists()
            || DB::table('departments')->whereNull('code')->exists()
            || DB::table('departments')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('departments')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('The previous schema cannot represent the current organization data.');
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
        });

        Schema::dropIfExists('divisions');

        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['entity_id']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'code']);
            $table->unique('code');
            $table->unique('name');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('entity_id');
            $table->string('code', 20)->nullable(false)->change();
        });

        Schema::dropIfExists('entities');
    }
};
