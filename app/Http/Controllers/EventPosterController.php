<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPoster;
use App\Models\User;
use App\Services\EventAssetStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EventPosterController extends Controller
{
    public function show(Request $request, Event $event, EventPoster $poster, EventAssetStorage $assets): BinaryFileResponse
    {
        Gate::allowIf(fn (User $user): bool => $user->canAny(['events.view', 'events.update']));

        $size = $request->query('size');
        if ($size !== null) {
            abort_unless(in_array($size, ['thumbnail', 'detail'], true), 404);
            $variant = EventPoster::variantPath($poster->file_path, $size);
            if (Storage::disk('local')->exists($variant)) {
                return $assets->response($variant, 'image/jpeg');
            }
        }

        return $assets->response($poster->file_path, $poster->mime_type);
    }
}
