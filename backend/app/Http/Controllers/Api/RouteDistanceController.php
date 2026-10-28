<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RouteDistanceController extends Controller
{
    public function show(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->publication_status === 'published', 404);
        $data = $request->validate(['campus_id' => ['required', 'integer', 'exists:campuses,id']]);
        $campus = Campus::findOrFail($data['campus_id']);

        if ($campus->latitude === null || $campus->longitude === null || $property->latitude === null || $property->longitude === null) {
            return response()->json(['message' => 'Les coordonnées nécessaires sont indisponibles.'], 422);
        }

        $key = config('services.openrouteservice.key');
        if (!$key) return response()->json(['message' => 'Le calcul routier n’est pas configuré.'], 503);

        $response = Http::timeout(20)
            ->withHeaders(['Authorization' => $key, 'Content-Type' => 'application/json'])
            ->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'coordinates' => [
                    [(float) $campus->longitude, (float) $campus->latitude],
                    [(float) $property->longitude, (float) $property->latitude],
                ],
            ]);

        if (!$response->successful()) {
            return response()->json(['message' => 'Le service d’itinéraire est momentanément indisponible.'], 503);
        }

        $summary = $response->json('routes.0.summary');
        if (!$summary) return response()->json(['message' => 'Aucun itinéraire routier trouvé.'], 404);

        return response()->json(['data' => [
            'distance_km' => round(((float) $summary['distance']) / 1000, 2),
            'duration_minutes' => (int) ceil(((float) $summary['duration']) / 60),
            'profile' => 'driving-car',
        ]]);
    }
}
