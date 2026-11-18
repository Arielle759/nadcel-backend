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
        'pending'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'completed', 'cancelled', 'no_show'],
        'in_progress' => ['completed'],
        'completed'   => [],
        'cancelled'   => [],
        // Cliente arrivée en retard après la détection d'absence : le salon la
        // signale arrivée, si l'employé n'a pas été réservé entre-temps.
        'no_show'     => ['in_progress'],
    ];

    // Statuts qui ne bloquent plus le créneau de l'employé.
    const FREE_STATUSES = ['cancelled', 'no_show'];

    // Absence détectée automatiquement : rendez-vous confirmé dont la cliente
    // n'est pas signalée arrivée 30 min après l'heure prévue.
    const NO_SHOW_AFTER_MINUTES = 30;

    protected $fillable = [
        'client_id',
        'client_name',
        'client_phone',
        'source',
        'salon_id',
        'service_id',
        'employee_id',
        'scheduled_at',
        'duration',
        'status',
        'price',
        'notes',
        'payment_status',
        'amount_paid',
        'payment_method',
        'paid_at',
        'reminder_sent_at',
        'cancelled_late',
        'no_show_detected_at',
    ];

    /** Moyens de paiement utilisés sur place. */
    const PAYMENT_METHODS = ['especes', 'wave', 'orange_money'];

    // Canal de réservation : en ligne par la cliente, ou saisi par le salon
    // (message WhatsApp, Instagram, Facebook ou TikTok, appel, cliente venue au salon).
    const SOURCES = ['online', 'whatsapp', 'instagram', 'facebook', 'tiktok', 'phone', 'walk_in'];
    const OFFLINE_SOURCES = ['whatsapp', 'instagram', 'facebook', 'tiktok', 'phone', 'walk_in'];

    /** Nom à afficher : celui du compte client, sinon celui saisi par le salon. */
    public function clientDisplayName(): string
    {
        return $this->client?->name ?? $this->client_name ?? 'Client';
    }

    // Une cliente peut annuler en ligne jusqu'à l'heure du rendez-vous. Moins de
    // 24 h avant, l'annulation d'un rendez-vous confirmé est « tardive » : elle est
    // calculée automatiquement, signalée au salon et comptée dans le fichier clients.
    const LATE_CANCELLATION_HOURS = 24;

    // Demandes en ligne en attente autorisées par cliente et par salon.
    const MAX_PENDING_PER_SALON = 3;

    // Réservation en ligne possible jusqu'à 3 mois à l'avance. Le salon, lui,
    // peut saisir un rendez-vous plus lointain (mariage, événement).
    const MAX_ADVANCE_DAYS = 90;

    public function clientCanCancel(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true)
            && $this->scheduled_at->greaterThan(now());
    }

    /** Annulation tardive : rendez-vous confirmé annulé moins de 24 h avant. */
    public function isLateCancellation(): bool
    {
        return $this->status === 'confirmed'
            && $this->scheduled_at->lessThanOrEqualTo(now()->addHours(self::LATE_CANCELLATION_HOURS));
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    protected $casts = [
        'scheduled_at' => 'datetime',
        'price' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'cancelled_late' => 'boolean',
        'no_show_detected_at' => 'datetime',
    ];

    /** Montant réellement encaissé ; à défaut (anciens rendez-vous), le prix prévu. */
    public function collectedAmount(): float
    {
        return (float) ($this->amount_paid ?? $this->price);
    }

    // Relations
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
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