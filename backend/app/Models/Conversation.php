<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['property_id', 'student_id', 'owner_id'];
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }
}
