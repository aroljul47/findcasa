<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalSource extends Model
{
    protected $fillable = ['name', 'url', 'adapter', 'robots_url', 'configuration', 'is_active', 'collection_interval_minutes', 'last_collected_at', 'last_error', 'consecutive_failures', 'disabled_at'];
    protected function casts(): array { return ['configuration' => 'array', 'is_active' => 'boolean', 'last_collected_at' => 'datetime', 'disabled_at' => 'datetime']; }
    public function runs(): HasMany { return $this->hasMany(CollectionRun::class); }
    public function offers(): HasMany { return $this->hasMany(CollectedOffer::class); }
}
