<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'owner_id', 'title', 'description', 'type', 'address', 'district', 'surface',
        'bedroom_count', 'latitude', 'longitude', 'publication_status', 'origin',
        'external_source_id', 'external_url', 'external_image_url', 'collected_at', 'exact_location_visible',
        'reviewed_by', 'reviewed_at', 'moderation_note',
    ];

    protected function casts(): array
    {
        return [
            'surface' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'collected_at' => 'datetime',
            'exact_location_visible' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function externalSource(): BelongsTo { return $this->belongsTo(ExternalSource::class, 'external_source_id'); }
    public function rooms(): HasMany { return $this->hasMany(Room::class); }
    public function amenities(): BelongsToMany { return $this->belongsToMany(Amenity::class, 'property_amenity'); }
    public function media(): HasMany { return $this->hasMany(Media::class)->orderBy('sort_order'); }
    public function favorites(): HasMany { return $this->hasMany(Favorite::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }
}
