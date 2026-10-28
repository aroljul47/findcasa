<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Notifications\FindCasaEventNotification;

class MediaController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4'],
            'category' => ['required', 'in:chambre,devanture,douche,cuisine,cour,autre'],
        ]);

        $file = $data['file'];
        $kind = str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'photo';

        if ($kind === 'photo') {
            $dimensions = @getimagesize($file->getRealPath());
            if (!$dimensions || $dimensions[0] < 320 || $dimensions[1] < 240 || $dimensions[0] > 8000 || $dimensions[1] > 8000) {
                return response()->json(['message' => 'L’image doit mesurer entre 320×240 et 8000×8000 pixels.'], 422);
            }
        }

        if ($kind === 'video' && $file->getMimeType() !== 'video/mp4') {
            return response()->json(['message' => 'La vidéo doit être au format MP4.'], 422);
        }

        $duration = $kind === 'video' ? $this->mp4DurationSeconds($file->getRealPath()) : null;
        if ($kind === 'video' && ($duration === null || $duration < 120 || $duration > 600)) {
            return response()->json(['message' => 'La vidéo doit durer entre 2 et 10 minutes.'], 422);
        }

        $media = Media::create([
            'property_id' => $property->id,
            'kind' => $kind,
            'category' => $data['category'],
            'disk' => 'public',
            'path' => $file->store('properties/'.$property->id, 'public'),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'duration_seconds' => $duration,
            'is_approved' => false,
            'sort_order' => $property->media()->count(),
        ]);

        User::where('role', 'admin')->where('is_suspended', false)->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification(
            'Nouveau média à vérifier',
            "Un média de l’annonce « {$property->title} » attend une validation.",
            ['property_id' => $property->id, 'media_id' => $media->id],
        )));

        return response()->json(['message' => 'Média envoyé et placé en attente de validation.', 'data' => $media], 201);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        abort_unless($media->property->owner_id === $request->user()->id, 403);
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return response()->json(['message' => 'Média supprimé.']);
    }

    public function reorder(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);
        $data = $request->validate([
            'media_ids' => ['required', 'array'],
            'media_ids.*' => ['integer', 'distinct', 'exists:media,id'],
        ]);
        $ownedIds = $property->media()->whereIn('id', $data['media_ids'])->pluck('id')->all();
        abort_unless(count($ownedIds) === count($data['media_ids']), 403);
        foreach ($data['media_ids'] as $index => $mediaId) Media::whereKey($mediaId)->update(['sort_order' => $index]);
        return response()->json(['message' => 'Ordre des médias mis à jour.', 'data' => $property->media()->get()]);
    }

    public function pending(): JsonResponse
    {
        return response()->json(['data' => Media::query()->where('is_approved', false)->with('property:id,title,owner_id')->latest()->paginate(30)]);
    }

    public function review(Request $request, Media $media): JsonResponse
    {
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $media->update(['is_approved' => $data['approved']]);

        return response()->json(['message' => $data['approved'] ? 'Média approuvé.' : 'Média rejeté.', 'data' => $media->fresh()]);
    }

    private function mp4DurationSeconds(string $path): ?int
    {
        $contents = file_get_contents($path);
        if ($contents === false) return null;
        $position = strpos($contents, 'mvhd');
        if ($position === false) return null;
        $offset = $position + 4;
        $version = ord($contents[$offset] ?? "\0");
        if ($version === 1) {
            $timescaleData = substr($contents, $offset + 20, 4);
            $durationData = substr($contents, $offset + 24, 8);
            if (strlen($timescaleData) !== 4 || strlen($durationData) !== 8) return null;
            $timescale = unpack('N', $timescaleData)[1];
            $parts = unpack('Nhigh/Nlow', $durationData);
            $duration = ($parts['high'] * 4294967296) + $parts['low'];
        } else {
            $timescaleData = substr($contents, $offset + 12, 4);
            $durationData = substr($contents, $offset + 16, 4);
            if (strlen($timescaleData) !== 4 || strlen($durationData) !== 4) return null;
            $timescale = unpack('N', $timescaleData)[1];
            $duration = unpack('N', $durationData)[1];
        }
        if (!$timescale) return null;
        return (int) round($duration / $timescale);
    }
}
