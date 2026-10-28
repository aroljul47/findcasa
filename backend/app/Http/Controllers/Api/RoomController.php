<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Notifications\FindCasaEventNotification;

class RoomController extends Controller
{
    public function store(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);
        $data = $this->validateRoom($request);
        $room = $property->rooms()->create($data);
        $property->update(['bedroom_count' => $property->rooms()->count(), 'publication_status' => $property->publication_status === 'published' ? 'pending' : $property->publication_status]);
        return response()->json(['message' => 'Chambre ajoutée.', 'data' => $room], 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        abort_unless($room->property->owner_id === $request->user()->id, 403);
        $data = $this->validateRoom($request, true);
        if (($data['status'] ?? null) === 'rented' && $room->status !== 'reserved') return response()->json(['message' => 'Seule une chambre réservée peut passer au statut louée.'], 422);
        $previousStatus = $room->status;
        $room->update($data);
        if ($room->property->publication_status === 'published') $room->property()->update(['publication_status' => 'pending', 'reviewed_at' => null, 'reviewed_by' => null]);
        if (isset($data['status']) && $data['status'] !== $previousStatus) {
            $room->property->favorites()->with('user')->get()->each(function ($favorite) use ($room) {
                $favorite->user?->notify(new FindCasaEventNotification(
                    'Disponibilité modifiée',
                    "Le statut de {$room->property->title} est maintenant modifié.",
                    ['property_id' => $room->property_id, 'room_id' => $room->id, 'room_status' => $room->status],
                ));
            });
        }
        return response()->json(['message' => 'Chambre mise à jour.', 'data' => $room->fresh()]);
    }

    public function destroy(Request $request, Room $room): JsonResponse
    {
        abort_unless($room->property->owner_id === $request->user()->id, 403);
        if ($room->reservations()->whereIn('status', ['accepted', 'confirmed'])->exists()) return response()->json(['message' => 'Cette chambre possède une réservation active.'], 422);
        $property = $room->property;
        $room->delete();
        $property->update(['bedroom_count' => max(1, $property->rooms()->count()), 'publication_status' => $property->publication_status === 'published' ? 'pending' : $property->publication_status]);
        return response()->json(['message' => 'Chambre supprimée.']);
    }

    private function validateRoom(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'rent' => [$required, 'numeric', 'min:0', 'max:45000'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'additional_fees' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:available,unavailable,reserved,rented'],
        ]);
    }
}
