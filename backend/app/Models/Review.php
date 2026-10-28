<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = ['student_id', 'property_id', 'reservation_id', 'rating', 'comment', 'status'];
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
}
