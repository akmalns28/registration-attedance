<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPoster;
use App\Models\User;
use App\Services\EventAssetStorage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventPosterController extends Controller
{
    public function show(Event $event, EventPoster $poster, EventAssetStorage $assets): BinaryFileResponse
    {
        Gate::allowIf(fn (User $user): bool => $user->canAny(['events.view', 'events.update']));

        return $assets->response($poster->file_path, $poster->mime_type);
    }
}
