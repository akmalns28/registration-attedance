<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
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
        Schema::create('event_day_invitations', function (Blueprint $table) {
            $table->foreignId('event_registration_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_day_id')->constrained()->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->primary(['event_registration_id', 'event_day_id'], 'event_day_invitations_primary');
            $table->index(['event_day_id', 'revoked_at']);
        });

        DB::table('event_day_invitations')->insertUsing(
            ['event_registration_id', 'event_day_id'],
            DB::table('attendances')
                ->join('event_registrations', 'event_registrations.id', '=', 'attendances.event_registration_id')
                ->join('event_days', function (JoinClause $join): void {
                    $join->on('event_days.id', '=', 'attendances.event_day_id')
                        ->on('event_days.event_id', '=', 'event_registrations.event_id');
                })
                ->select('attendances.event_registration_id', 'attendances.event_day_id')->distinct(),
        );

        DB::table('event_day_invitations')->insertUsing(
            ['event_registration_id', 'event_day_id'],
            DB::table('event_registrations')
                ->join('events', 'events.id', '=', 'event_registrations.event_id')
                ->join('event_days', function (JoinClause $join): void {
                    $join->on('event_days.event_id', '=', 'events.id')->on('event_days.date', '=', DB::raw('date(events.start_date)'));
                })
                ->whereColumn(DB::raw('date(events.start_date)'), DB::raw('date(events.end_date)'))
                ->where('event_registrations.status', 'registered')
                ->where('event_days.is_active', true)
                ->whereNotExists(fn (Builder $query): Builder => $query->from('attendances')
                    ->whereColumn('attendances.event_registration_id', 'event_registrations.id')
                    ->whereColumn('attendances.event_day_id', 'event_days.id'))
                ->select('event_registrations.id', 'event_days.id as event_day_id'),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_day_invitations');
    }
};
