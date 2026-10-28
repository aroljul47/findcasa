<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = ['reporter_id', 'property_id', 'reason', 'description', 'status', 'admin_note'];
    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
}
