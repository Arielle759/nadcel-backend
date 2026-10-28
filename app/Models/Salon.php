<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $manager_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $address
 * @property string $city
 * @property string $phone
 * @property string|null $email
 * @property string|null $logo
 * @property string|null $cover
 * @property numeric $rating
 * @property bool $is_active
 * @property bool $is_verified
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Appointment> $appointments
 * @property-read int|null $appointments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Employee> $employees
 * @property-read int|null $employees_count
 * @property-read \App\Models\User $manager
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Review> $reviews
 * @property-read int|null $reviews_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Service> $services
 * @property-read int|null $services_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereManagerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Salon withoutTrashed()
 * @mixin \Eloquent
 */
class Salon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'manager_id',
        'name',
        'slug',
        'description',
        'address',
        'city',
        'phone',
        'email',
        'logo',
        'cover',
        'rating',
        'opening_hours',
    ];

    protected $casts = [
        'rating' => 'decimal:2',
        'is_active' => 'boolean',
        'is_verified' => 'boolean',
        'opening_hours' => 'array',
    ];

    private const DEFAULT_OPEN = '09:00';
    private const DEFAULT_CLOSE = '19:00';
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function verify(): void
    {
        $this->is_verified = true;
        $this->is_active = true;
        $this->save();
    }

    public function reject(): void
    {
        $this->is_verified = false;
        $this->is_active = false;
        $this->save();
    }

    public function suspend(): void
    {
        $this->is_active = false;
        $this->save();
    }

    public function reactivate(): void
    {
        $this->is_active = true;
        $this->save();
    }

    // Relations
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(SalonClosure::class);
    }

    /**
     * Whether $user may see this salon regardless of its verification/active
     * status (its own manager, or an admin) — everyone else only sees it
     * when it's active and verified.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user && ($user->id === $this->manager_id || $user->hasRole('admin'))) {
            return true;
        }

        return $this->is_active && $this->is_verified;
    }

    /** @param Builder<Salon> $query */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->hasRole('admin')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_active', true)->where('is_verified', true);
            if ($user) {
                $q->orWhere('manager_id', $user->id);
            }
        });
    }

    /**
     * Opening hours for a given day, or null if the salon is closed that day
     * (explicit weekly closing, or a one-off entry in `salon_closures`).
     * Falls back to a 9h-19h default when no `opening_hours` has been set.
     *
     * @param  list<string>|null  $closedDates  pre-fetched 'Y-m-d' closures, to avoid
     *                                          one query per day when resolving a whole month
     * @return array{open: Carbon, close: Carbon}|null
     */
    public function openingHoursFor(Carbon $day, ?array $closedDates = null): ?array
    {
        $dateString = $day->toDateString();
        $isClosed = $closedDates !== null
            ? in_array($dateString, $closedDates, true)
            : $this->closures()->whereDate('closed_date', $dateString)->exists();

        if ($isClosed) {
            return null;
        }

        if ($this->opening_hours === null) {
            return [
                'open' => $day->copy()->setTimeFromTimeString(self::DEFAULT_OPEN),
                'close' => $day->copy()->setTimeFromTimeString(self::DEFAULT_CLOSE),
            ];
        }

        $dayKey = self::DAY_KEYS[$day->dayOfWeekIso - 1];
        $hours = $this->opening_hours[$dayKey] ?? null;

        if (empty($hours['open']) || empty($hours['close'])) {
            return null;
        }

        $open = $day->copy()->setTimeFromTimeString($hours['open']);
        $close = $day->copy()->setTimeFromTimeString($hours['close']);

        return $open->lt($close) ? ['open' => $open, 'close' => $close] : null;
    }
}