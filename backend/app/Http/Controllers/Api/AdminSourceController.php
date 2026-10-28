<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalSource;
use App\Models\CollectedOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class AdminSourceController extends Controller
{
    public function index(): JsonResponse { return response()->json(['data' => ExternalSource::withCount(['runs', 'offers'])->with(['runs' => fn ($query) => $query->latest()->limit(10)])->latest()->get()]); }
    public function store(Request $request): JsonResponse { $data = $request->validate(['name' => ['required','string','max:100'], 'url' => ['required','url','unique:external_sources,url'], 'robots_url' => ['nullable','url'], 'adapter' => ['required','in:sitemap,rss,json'], 'configuration' => ['nullable','array'], 'collection_interval_minutes' => ['required','integer','min:30']]); return response()->json(['data' => ExternalSource::create($data)], 201); }
    public function update(Request $request, ExternalSource $source): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes','string','max:100'], 'is_active' => ['sometimes','boolean'], 'collection_interval_minutes' => ['sometimes','integer','min:30']]);
        if (($data['is_active'] ?? null) === true) $data += ['consecutive_failures' => 0, 'disabled_at' => null, 'last_error' => null];
        $source->update($data);
        return response()->json(['data' => $source->fresh()->loadCount(['runs', 'offers'])]);
    }

    public function offers(Request $request): JsonResponse
    {
        $qualification = $request->string('qualification')->toString();
        $offers = CollectedOffer::query()
            ->when($qualification !== 'all', fn ($query) => $query->where('status', 'pending'))
            ->when(
                $qualification === 'eligible',
                fn ($query) => $query->whereIn('qualification_status', ['priority', 'review']),
                fn ($query) => $query->when(
                    $qualification && $qualification !== 'all',
                    fn ($filtered) => $filtered->where('qualification_status', $qualification),
                ),
            )
            ->with(['source:id,name', 'nearestCampus:id,institution_id,name'])
            ->orderByDesc('relevance_score')
            ->latest()
            ->paginate(50);
        return response()->json(['data' => $offers]);
    }

    public function reviewOffer(Request $request, CollectedOffer $offer): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        if ($data['status'] === 'approved') {
            if (!in_array($offer->qualification_status, ['priority', 'review'], true) || !$offer->monthly_rent || !$offer->property_type || !$offer->latitude || !$offer->longitude) {
                return response()->json(['message' => 'Cette offre est incomplète ou ne respecte pas les critères étudiants.'], 422);
            }

            DB::transaction(function () use ($offer, $request) {
                $property = Property::updateOrCreate(
                    ['external_url' => $offer->source_url],
                    [
                        'owner_id' => null,
                        'title' => $offer->title,
                        'description' => $offer->description_excerpt,
                        'type' => $offer->property_type,
                        'district' => $offer->city,
                        'surface' => null,
                        'bedroom_count' => str_starts_with($offer->property_type, 'Double') ? 2 : 1,
                        'latitude' => $offer->latitude,
                        'longitude' => $offer->longitude,
                        'publication_status' => 'published',
                        'origin' => 'collected',
                        'external_source_id' => $offer->external_source_id,
                        'external_image_url' => $offer->image_url,
                        'collected_at' => $offer->discovered_at,
                        'reviewed_by' => $request->user()->id,
                        'reviewed_at' => now(),
                        'moderation_note' => 'Offre externe préqualifiée et approuvée.',
                    ],
                );
                $property->rooms()->updateOrCreate(['name' => 'Chambre 1'], ['rent' => $offer->monthly_rent, 'status' => 'available']);
                $offer->update(['status' => 'approved', 'metadata' => [...($offer->metadata ?? []), 'property_id' => $property->id]]);
            });
        } else {
            $offer->update(['status' => 'rejected']);
        }

        return response()->json(['message' => $data['status'] === 'approved' ? 'Offre externe approuvée et publiée.' : 'Offre externe rejetée.', 'data' => $offer->fresh(['source', 'nearestCampus'])]);
    }
}
