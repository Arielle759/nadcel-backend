<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'user_id',
        'salon_id',
        'name',
        'phone',
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function salon()
    {
        return $this->belongsTo(Salon::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'employee_service');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}