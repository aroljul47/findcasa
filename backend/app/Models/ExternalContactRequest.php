<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalContactRequest extends Model
{
    protected $fillable = ['student_id', 'property_id', 'start_date', 'duration_months', 'message', 'status'];
    protected function casts(): array { return ['start_date' => 'date']; }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
}
