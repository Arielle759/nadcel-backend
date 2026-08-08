<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $client_id
 * @property int $salon_id
 * @property int $service_id
 * @property int|null $employee_id
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property int $duration
 * @property string $status
 * @property numeric $price
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $client
 * @property-read \App\Models\Employee|null $employee
 * @property-read \App\Models\Review|null $review
 * @property-read \App\Models\Salon|null $salon
 * @property-read \App\Models\Service $service
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereEmployeeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereSalonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereScheduledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereServiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Appointment withoutTrashed()
 * @mixin \Eloquent
 */
class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    const ALLOWED_TRANSITIONS = [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'client_id',
        'salon_id',
        'service_id',
        'employee_id',
        'scheduled_at',
        'duration',
        'status',
        'price',
        'notes',
        'payment_status',
        'reminder_sent_at',
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    protected $casts = [
        'scheduled_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    // Relations
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}