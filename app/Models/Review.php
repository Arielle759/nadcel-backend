<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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