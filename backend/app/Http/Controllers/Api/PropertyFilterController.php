<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Http\JsonResponse;

class PropertyFilterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'districts' => Property::query()->where('publication_status', 'published')->whereNotNull('district')->distinct()->orderBy('district')->pluck('district'),
            'amenities' => Amenity::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'property_types' => config('findcasa.property_types'),
            'max_monthly_rent' => config('findcasa.max_monthly_rent'),
            'max_distance_km' => 15,
        ]]);
    }
}
