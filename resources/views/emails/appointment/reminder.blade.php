Bonjour {{ $appointment->client->name }},

Votre rendez-vous a lieu demain. Voici un rappel des détails.

--- Détails du rendez-vous ---
Salon    : {{ $appointment->salon->name }}
Service  : {{ $appointment->service->name }}
Date     : {{ $appointment->scheduled_at->translatedFormat('l d F Y à H:i') }}
Durée    : {{ $appointment->duration }} min
Prix     : {{ number_format($appointment->price, 0, ',', ' ') }} FCFA

Vous pouvez consulter vos réservations dans l'application.

À bientôt,
L'équipe {{ $appointment->salon->name }}
