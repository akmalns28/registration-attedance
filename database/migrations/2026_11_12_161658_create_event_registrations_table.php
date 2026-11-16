<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();

            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();

            $table->foreignId('external_participant_id')->nullable()->constrained('external_participants')->restrictOnDelete();

            $table->string('registration_number', 50)->unique();

            $table->string('registration_type', 20);

            $table->string('registration_source', 20)->default('admin');

            $table->string('status', 20)->default('registered');

            $table->dateTime('registered_at')->nullable();

            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Duplicate Prevention
            |--------------------------------------------------------------------------
            */

            $table->unique(['event_id', 'employee_id'], 'event_registrations_event_employee_unique');

            $table->unique(['event_id', 'external_participant_id'], 'event_registrations_event_external_unique');

            /*
            |--------------------------------------------------------------------------
            | Query Optimization
            |--------------------------------------------------------------------------
            */

            $table->index(['event_id', 'status']);

            $table->index(['event_id', 'registration_type']);
        });

        /*
        |--------------------------------------------------------------------------
        | Participant Integrity Constraint
        |--------------------------------------------------------------------------
        |
        | Employee:
        |   employee_id IS NOT NULL
        |   external_participant_id IS NULL
        |
        | External:
        |   employee_id IS NULL
        |   external_participant_id IS NOT NULL
        |
        */

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                <<<'SQL'
                    ALTER TABLE event_registrations
                    ADD CONSTRAINT event_registrations_participant_check
                    CHECK (
                        (
                            registration_type = 'employee'
                            AND employee_id IS NOT NULL
                            AND external_participant_id IS NULL
                        )
                        OR
                        (
                            registration_type = 'external'
                            AND employee_id IS NULL
                            AND external_participant_id IS NOT NULL
                        )
                    )
                SQL
                ,
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
