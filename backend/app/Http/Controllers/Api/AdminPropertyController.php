<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Notifications\FindCasaEventNotification;
use App\Models\AuditLog;

class AdminPropertyController extends Controller
{
    public function pending(): JsonResponse
    {
        return response()->json([
            'data' => Property::query()
                ->whereIn('publication_status', ['pending', 'rejected'])
                ->with(['owner:id,name,email,phone,owner_verification_status', 'externalSource:id,name,url', 'rooms', 'amenities', 'media'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function show(Property $property): JsonResponse
    {
        return response()->json([
            'data' => $property->load([
                'owner:id,name,email,phone,owner_verification_status',
                'externalSource:id,name,url',
                'rooms',
                'amenities',
                'media',
            ]),
        ]);
    }

    public function review(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:published,rejected'],
            'moderation_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['status'] === 'published') {
            $hasRoomPhoto = $property->media()->where('is_approved', true)->where('kind', 'photo')->where('category', 'chambre')->exists();
            $hasFrontPhoto = $property->media()->where('is_approved', true)->where('kind', 'photo')->where('category', 'devanture')->exists();
            if (!$hasRoomPhoto || !$hasFrontPhoto) {
                return response()->json(['message' => 'Une photo approuvée de la chambre et une photo approuvée de la devanture sont obligatoires.'], 422);
            }
        }

        $property->update([
            'publication_status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'moderation_note' => $data['moderation_note'] ?? null,
        ]);
        AuditLog::record($request, 'property.reviewed.'.$data['status'], $property, ['moderation_note' => $data['moderation_note'] ?? null]);

        $property->owner?->notify(new FindCasaEventNotification(
            $data['status'] === 'published' ? 'Annonce publiée' : 'Annonce rejetée',
            $data['status'] === 'published' ? 'Ton annonce est maintenant visible dans le catalogue.' : ($data['moderation_note'] ?? 'Ton annonce a été rejetée par la modération.'),
            ['property_id' => $property->id],
        ));

        return response()->json([
            'message' => $data['status'] === 'published' ? 'Annonce publiée.' : 'Annonce rejetée.',
            'data' => $property->fresh(['owner', 'externalSource', 'rooms', 'amenities', 'media']),
        ]);
    }
}
