@if ($initiatedByClient)
Bonjour {{ $appointment->salon->manager->name }},

Le client {{ $appointment->client->name }} a annulé son rendez-vous.
@else
Bonjour {{ $appointment->client->name }},

Votre rendez-vous a été annulé par le salon.
@endif

--- Détails du rendez-vous annulé ---
Salon    : {{ $appointment->salon->name }}
Service  : {{ $appointment->service->name }}
Date     : {{ $appointment->scheduled_at->translatedFormat('l d F Y à H:i') }}
Durée    : {{ $appointment->duration }} min
Prix     : {{ number_format($appointment->price, 0, ',', ' ') }} FCFA

@if ($initiatedByClient)
Vous pouvez gérer vos rendez-vous dans l'application.
@else
Vous pouvez prendre un nouveau rendez-vous dans l'application.
@endif

L'équipe {{ $appointment->salon->name }}
