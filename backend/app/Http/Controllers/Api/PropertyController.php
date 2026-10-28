<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Campus;
use App\Models\User;
use App\Notifications\FindCasaEventNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'district' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:'.implode(',', config('findcasa.property_types'))],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:45000'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'max_distance' => ['nullable', 'numeric', 'min:0.1', 'max:15'],
            'amenity_ids' => ['nullable', 'array', 'max:20'],
            'amenity_ids.*' => ['integer', 'distinct', 'exists:amenities,id'],
            'sort' => ['nullable', 'in:newest,oldest,price_asc,price_desc,distance'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $campus = $request->filled('campus_id') ? Campus::find($request->integer('campus_id')) : null;
        $query = Property::query()
            ->select('properties.*')
            ->where('publication_status', 'published')
            ->with(['externalSource:id,name,url', 'rooms' => fn ($query) => $query->where('status', 'available'), 'amenities', 'media' => fn ($query) => $query->where('is_approved', true)])
            ->withMin(['rooms as minimum_rent' => fn ($rooms) => $rooms->where('status', 'available')], 'rent')
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = trim($request->string('q')->toString());
                $query->where(fn ($nested) => $nested
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('district', 'like', '%'.$search.'%')
                    ->orWhere('type', 'like', '%'.$search.'%'));
            })
            ->when($request->filled('district'), fn ($query) => $query->where('district', $request->string('district')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('amenity_ids'), function ($query) use ($request) {
                foreach ($request->input('amenity_ids', []) as $amenityId) {
                    $query->whereHas('amenities', fn ($amenities) => $amenities->whereKey($amenityId));
                }
            })
            ->whereHas('rooms', fn ($rooms) => $rooms->where('rent', '<=', config('findcasa.max_monthly_rent'))->where('status', 'available'))
            ->when($request->filled('max_price'), fn ($query) => $query->whereHas('rooms', fn ($rooms) => $rooms->where('rent', '<=', min(max($request->float('max_price'), 0), config('findcasa.max_monthly_rent')))->where('status', 'available')));

        if ($campus?->latitude !== null && $campus?->longitude !== null) {
            $distanceSql = '6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(properties.latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(properties.latitude)) * POWER(SIN(RADIANS(properties.longitude - ?) / 2), 2)))';
            $query->selectRaw("{$distanceSql} AS distance_km", [(float) $campus->latitude, (float) $campus->latitude, (float) $campus->longitude]);
            if ($request->filled('max_distance')) $query->having('distance_km', '<=', $request->float('max_distance'));
        }

        match ($request->string('sort')->toString()) {
            'price_asc' => $query->orderBy('minimum_rent'),
            'price_desc' => $query->orderByDesc('minimum_rent'),
            'oldest' => $query->oldest(),
            'distance' => $campus ? $query->orderBy('distance_km') : $query->latest(),
            default => $query->latest(),
        };

        $properties = $query->paginate($filters['per_page'] ?? 12)->withQueryString();

        if ($campus?->latitude !== null && $campus?->longitude !== null) {
            $properties->getCollection()->transform(function (Property $property) use ($campus) {
                if ($property->latitude === null || $property->longitude === null) return $property;
                $property->distance_km = round($this->haversine(
                    (float) $campus->latitude,
                    (float) $campus->longitude,
                    (float) $property->latitude,
                    (float) $property->longitude,
                ), 2);
                $property->map_latitude = round((float) $property->latitude, 3);
                $property->map_longitude = round((float) $property->longitude, 3);
                return $property;
            });
        } else {
            $properties->getCollection()->each(function (Property $property) {
                if ($property->latitude !== null && $property->longitude !== null) {
                    $property->map_latitude = round((float) $property->latitude, 3);
                    $property->map_longitude = round((float) $property->longitude, 3);
                }
            });
        }

        $properties->getCollection()->each(fn (Property $property) => $property->makeHidden(['latitude', 'longitude', 'address']));

        return response()->json($properties);
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function show(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->publication_status === 'published', 404);

        $property->load(['owner:id,name', 'rooms', 'amenities', 'media' => fn ($query) => $query->where('is_approved', true)]);

        if ($request->filled('campus_id')) {
            $campus = Campus::find($request->integer('campus_id'));
            if ($campus?->latitude !== null && $campus?->longitude !== null && $property->latitude !== null && $property->longitude !== null) {
                $property->distance_km = round($this->haversine((float) $campus->latitude, (float) $campus->longitude, (float) $property->latitude, (float) $property->longitude), 2);
            }
        }

        $viewer = $request->user('sanctum');
        $canSeeExactLocation = $viewer && (
            $viewer->role === 'admin'
            || $property->owner_id === $viewer->id
            || $property->rooms()->whereHas('reservations', fn ($query) => $query
                ->where('student_id', $viewer->id)
                ->whereIn('status', ['accepted', 'confirmed', 'rented', 'completed']))->exists()
        );
        if (!$canSeeExactLocation) {
            $property->makeHidden(['latitude', 'longitude', 'address']);
        }

        return response()->json(['data' => $property]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Property::query()->where('owner_id', $request->user()->id)->with(['rooms', 'amenities', 'media'])->latest()->get(),
        ]);
    }

    public function ownerShow(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);
        return response()->json(['data' => $property->load(['rooms', 'amenities', 'media'])]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:100', 'in:'.implode(',', config('findcasa.property_types'))],
            'address' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'],
            'surface' => ['nullable', 'numeric', 'min:1'],
            'bedroom_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'amenity_names' => ['nullable', 'array'],
            'amenity_names.*' => ['string', 'max:100'],
        ]);
        $property = isset($data['property_id']) ? Property::findOrFail($data['property_id']) : new Property();
        if ($property->exists) abort_unless($property->owner_id === $request->user()->id && in_array($property->publication_status, ['draft', 'rejected'], true), 403);
        $property->fill([
            ...collect($data)->except(['property_id', 'amenity_names'])->all(),
            'title' => $data['title'] ?: ($property->title ?: 'Brouillon sans titre'),
            'type' => $data['type'] ?: ($property->type ?: config('findcasa.property_types')[0]),
            'owner_id' => $request->user()->id,
            'bedroom_count' => $data['bedroom_count'] ?? $property->bedroom_count ?? 1,
            'publication_status' => 'draft',
            'origin' => 'direct',
        ])->save();
        if (array_key_exists('amenity_names', $data)) {
            $amenityIds = collect($data['amenity_names'] ?? [])->map(fn (string $name) => \App\Models\Amenity::firstOrCreate(['name' => trim($name)])->id);
            $property->amenities()->sync($amenityIds);
        }
        return response()->json(['message' => 'Brouillon enregistré.', 'data' => $property->fresh(['rooms', 'amenities', 'media'])], $property->wasRecentlyCreated ? 201 : 200);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->owner_verification_status === 'verified', 403, 'Le propriétaire doit être vérifié avant de publier.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'type' => ['required', 'string', 'max:100', 'in:'.implode(',', config('findcasa.property_types'))],
            'address' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:100'],
            'surface' => ['required', 'numeric', 'min:1'],
            'bedroom_count' => ['required', 'integer', 'min:1'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.name' => ['required', 'string', 'max:100'],
            'rooms.*.rent' => ['required', 'numeric', 'min:0', 'max:45000'],
            'rooms.*.deposit' => ['nullable', 'numeric', 'min:0'],
            'rooms.*.advance' => ['nullable', 'numeric', 'min:0'],
            'rooms.*.additional_fees' => ['nullable', 'numeric', 'min:0'],
            'amenity_names' => ['nullable', 'array'],
            'amenity_names.*' => ['string', 'max:100'],
        ]);

        $property = DB::transaction(function () use ($data, $request) {
            $property = Property::create([
                ...collect($data)->except(['rooms', 'amenity_names'])->all(),
                'owner_id' => $request->user()->id,
                'publication_status' => 'pending',
                'origin' => 'direct',
            ]);

            $property->rooms()->createMany($data['rooms']);
            $amenityIds = collect($data['amenity_names'] ?? [])->map(
                fn (string $name) => \App\Models\Amenity::firstOrCreate(['name' => trim($name)])->id,
            );
            $property->amenities()->sync($amenityIds);

            return $property->load(['rooms', 'amenities']);
        });

        User::where('role', 'admin')->where('is_suspended', false)->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification(
            'Nouvelle annonce à modérer',
            "{$request->user()->name} a soumis l’annonce « {$property->title} ».",
            ['property_id' => $property->id],
        )));

        return response()->json(['message' => 'Annonce soumise à validation.', 'data' => $property], 201);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);

        $previousStatus = $property->publication_status;
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string', 'max:100', 'in:'.implode(',', config('findcasa.property_types'))],
            'address' => ['sometimes', 'string', 'max:255'],
            'district' => ['sometimes', 'string', 'max:100'],
            'surface' => ['sometimes', 'numeric', 'min:1'],
            'bedroom_count' => ['sometimes', 'integer', 'min:1'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'amenity_names' => ['sometimes', 'array'],
            'amenity_names.*' => ['string', 'max:100'],
            'rooms' => ['sometimes', 'array', 'min:1'],
            'rooms.*.id' => ['nullable', 'integer', 'exists:rooms,id'],
            'rooms.*.name' => ['required_with:rooms', 'string', 'max:100'],
            'rooms.*.rent' => ['required_with:rooms', 'numeric', 'min:0', 'max:45000'],
            'rooms.*.deposit' => ['nullable', 'numeric', 'min:0'],
            'rooms.*.advance' => ['nullable', 'numeric', 'min:0'],
            'rooms.*.additional_fees' => ['nullable', 'numeric', 'min:0'],
            'rooms.*.status' => ['sometimes', 'in:available,unavailable,reserved,rented'],
            'submit' => ['sometimes', 'boolean'],
        ]);

        if (($data['submit'] ?? false) === true) {
            abort_unless($request->user()->owner_verification_status === 'verified', 403, 'Le propriétaire doit être vérifié avant de soumettre une annonce.');
            $requiredValues = [
                'title' => $data['title'] ?? $property->title,
                'description' => $data['description'] ?? $property->description,
                'address' => $data['address'] ?? $property->address,
                'district' => $data['district'] ?? $property->district,
                'surface' => $data['surface'] ?? $property->surface,
                'latitude' => $data['latitude'] ?? $property->latitude,
                'longitude' => $data['longitude'] ?? $property->longitude,
            ];
            if (collect($requiredValues)->contains(fn ($value) => blank($value)) || (empty($data['rooms']) && !$property->rooms()->exists())) {
                return response()->json(['message' => 'Complète les informations, la localisation et au moins une chambre avant de soumettre.'], 422);
            }
        }

        DB::transaction(function () use ($property, $data) {
            $property->update([
                ...collect($data)->except(['amenity_names', 'rooms', 'submit'])->all(),
                'publication_status' => ($data['submit'] ?? false) ? 'pending' : (in_array($property->publication_status, ['published', 'rejected'], true) ? 'pending' : $property->publication_status),
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
            if (array_key_exists('amenity_names', $data)) {
                $amenityIds = collect($data['amenity_names'])->map(fn (string $name) => \App\Models\Amenity::firstOrCreate(['name' => trim($name)])->id);
                $property->amenities()->sync($amenityIds);
            }
            if (array_key_exists('rooms', $data)) {
                foreach ($data['rooms'] as $roomData) {
                    $roomId = $roomData['id'] ?? null;
                    unset($roomData['id']);
                    if ($roomId) {
                        $room = $property->rooms()->findOrFail($roomId);
                        $room->update($roomData);
                    } else {
                        $property->rooms()->create($roomData);
                    }
                }
                $property->bedroom_count = $property->rooms()->count();
                $property->save();
            }
        });

        if (($data['submit'] ?? false) || in_array($previousStatus, ['published', 'rejected'], true)) {
            User::where('role', 'admin')->where('is_suspended', false)->get()->each(fn (User $admin) => $admin->notify(new FindCasaEventNotification(
                'Annonce à réexaminer',
                "L’annonce « {$property->title} » a été soumise à nouveau.",
                ['property_id' => $property->id],
            )));
        }

        return response()->json(['message' => 'Annonce mise à jour et renvoyée en modération.', 'data' => $property->fresh(['rooms', 'amenities', 'media'])]);
    }

    public function destroy(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->owner_id === $request->user()->id, 403);
        $activeReservation = $property->rooms()->whereHas('reservations', fn ($query) => $query->whereIn('status', ['accepted', 'confirmed']))->exists();
        if ($activeReservation) return response()->json(['message' => 'Cette annonce possède une réservation active et ne peut pas être supprimée.'], 422);
        $property->delete();
        return response()->json(['message' => 'Annonce supprimée.']);
    }
}
