<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $favorites = Favorite::where('user_id', $request->user()->id)
            ->with(['property.rooms', 'property.amenities', 'property.media' => fn ($query) => $query->where('is_approved', true)])
            ->latest()
            ->get();
        $favorites->each(fn (Favorite $favorite) => $favorite->property?->makeHidden(['address', 'latitude', 'longitude']));

        return response()->json(['data' => $favorites]);
    }
    public function store(Request $request, Property $property): JsonResponse { abort_unless($property->publication_status === 'published', 404); return response()->json(['data' => Favorite::firstOrCreate(['user_id' => $request->user()->id, 'property_id' => $property->id])->load('property')], 201); }
    public function destroy(Request $request, Property $property): JsonResponse { Favorite::where('user_id', $request->user()->id)->where('property_id', $property->id)->delete(); return response()->json(['message' => 'Favori retiré.']); }
}
