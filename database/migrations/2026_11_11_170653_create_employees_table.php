<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('nik', 30)->unique();

            $table->string('name', 150);

            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();

            $table->string('email')->nullable();

            $table->string('phone', 20)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['department_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
