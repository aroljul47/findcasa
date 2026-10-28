<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    protected $fillable = [
        'student_id', 'room_id', 'start_date', 'end_date', 'duration_months',
        'message', 'owner_message', 'status', 'expires_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function history(): HasMany { return $this->hasMany(ReservationStatusHistory::class); }
}
