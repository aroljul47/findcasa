<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectedOffer extends Model
{
    protected $fillable = ['external_source_id', 'source_url', 'title', 'category', 'monthly_rent', 'city', 'property_type', 'description_excerpt', 'image_url', 'latitude', 'longitude', 'nearest_campus_id', 'distance_km', 'relevance_score', 'qualification_status', 'qualification_reasons', 'fingerprint', 'duplicate_of_id', 'status', 'metadata', 'discovered_at', 'last_seen_at'];
    protected function casts(): array { return ['metadata' => 'array', 'qualification_reasons' => 'array', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'distance_km' => 'decimal:2', 'discovered_at' => 'datetime', 'last_seen_at' => 'datetime']; }
    public function source(): BelongsTo { return $this->belongsTo(ExternalSource::class, 'external_source_id'); }
    public function nearestCampus(): BelongsTo { return $this->belongsTo(Campus::class, 'nearest_campus_id'); }
}
