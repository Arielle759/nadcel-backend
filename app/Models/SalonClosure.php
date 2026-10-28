<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $salon_id
 * @property \Illuminate\Support\Carbon $closed_date
 * @property string|null $reason
 * @property-read \App\Models\Salon $salon
 */
class SalonClosure extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id',
        'closed_date',
        'reason',
    ];

    protected $casts = [
        'closed_date' => 'date',
    ];

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
