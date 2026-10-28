<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $fillable = ['property_id', 'name', 'rent', 'deposit', 'advance', 'additional_fees', 'status'];

    protected function casts(): array
    {
        return ['rent' => 'decimal:2', 'deposit' => 'decimal:2', 'advance' => 'decimal:2', 'additional_fees' => 'decimal:2'];
    }

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function reservations(): HasMany { return $this->hasMany(Reservation::class); }
}
