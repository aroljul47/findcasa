<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionRun extends Model
{
    protected $fillable = ['external_source_id', 'status', 'found_count', 'created_count', 'error', 'started_at', 'finished_at'];
    protected function casts(): array { return ['started_at' => 'datetime', 'finished_at' => 'datetime']; }
    public function source(): BelongsTo { return $this->belongsTo(ExternalSource::class, 'external_source_id'); }
}
