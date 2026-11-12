<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option d'un service (ex. « Mi-dos » pour la longueur des tresses) avec son
 * propre prix et sa propre durée.
 *
 * @property int $id
 * @property int $service_id
 * @property string $label
 * @property numeric $price
 * @property int $duration
 * @property int $position
 */
class ServiceOption extends Model
{
    // Une option ne peut pas avoir plus de 8 choix, de 15 min à 12 h.
    const MAX_PER_SERVICE = 8;

    protected $fillable = ['service_id', 'label', 'price', 'duration', 'position'];

    protected $casts = [
        'price' => 'decimal:2',
        'duration' => 'integer',
        'position' => 'integer',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
