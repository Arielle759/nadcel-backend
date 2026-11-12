<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property string|null $description
 * @property numeric $price
 * @property int $duration
 * @property string $category
 * @property string|null $image
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $appointments
 * @property-read int|null $appointments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Employee> $employees
 * @property-read int|null $employees_count
 * @property-read \App\Models\Salon|null $salon
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereDuration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereSalonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Service whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Service extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'salon_id',
        'name',
        'description',
        'price',
        'duration',
        'category',
        'option_label',
        'image',
        'is_active',
    ];

    // Les options (longueur, taille…) sont toujours renvoyées avec le service.
    protected $with = ['options'];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Relations
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /** @return BelongsToMany<Employee, $this> */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_service');
    }

    /** @return HasMany<ServiceOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ServiceOption::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Prix et durée d'un rendez-vous : ceux de l'option choisie si le service
     * en a, sinon ceux du service. Lève une erreur si l'option est absente ou
     * n'appartient pas à ce service.
     *
     * @return array{0: ?ServiceOption, 1: float, 2: int}
     */
    public function priceAndDurationFor(?int $optionId): array
    {
        if ($this->options->isEmpty()) {
            return [null, (float) $this->price, (int) $this->duration];
        }

        $option = $optionId ? $this->options->firstWhere('id', $optionId) : null;
        if (!$option) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_option_id' => [($this->option_label ?: 'Option') . ' : choisissez une option pour ce service.'],
            ]);
        }

        return [$option, (float) $option->price, (int) $option->duration];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}