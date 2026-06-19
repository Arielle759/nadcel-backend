<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'salon_id',
        'name',
        'description',
        'price',
        'duration',
        'category',
        'image',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relations
    public function salon()
    {
        return $this->belongsTo(Salon::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_service');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}