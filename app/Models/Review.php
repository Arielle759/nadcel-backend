<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $client_id
 * @property int $appointment_id
 * @property int $salon_id
 * @property int $rating
 * @property string|null $comment
 * @property string|null $manager_response
 * @property \Illuminate\Support\Carbon|null $manager_responded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Appointment|null $appointment
 * @property-read \App\Models\User $client
 * @property-read \App\Models\Salon|null $salon
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereAppointmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereManagerRespondedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereManagerResponse($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereSalonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Review extends Model
{
    protected $fillable = [
        'client_id',
        'appointment_id',
        'salon_id',
        'rating',
        'comment',
        'manager_response',
        'manager_responded_at',
    ];

    protected $casts = [
        'manager_responded_at' => 'datetime',
    ];

    // Relations
    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function salon()
    {
        return $this->belongsTo(Salon::class);
    }
}