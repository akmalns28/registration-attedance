<?php

namespace Database\Seeders;

use App\Models\EventInvitationExport;
use App\Models\EventRegistration;
use App\Services\EventInvitationExportQueue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventInvitationExportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(EventInvitationExportQueue $queue): void
    {
        DB::transaction(function () use ($queue): void {
            $registration = EventRegistration::factory()->invitedTo()->create();
            $export = EventInvitationExport::factory()->for($registration->event)->create(['last_registration_id' => $registration->id]);
            $queue->enqueue($export);
        });
    }
}
