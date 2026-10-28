<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'metadata', 'ip_address', 'user_agent'];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }

    public static function record(Request $request, string $action, ?Model $target = null, array $metadata = []): self
    {
        return self::create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
