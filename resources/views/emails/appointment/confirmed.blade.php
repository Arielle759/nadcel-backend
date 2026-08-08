Bonjour {{ $appointment->client->name }},

Bonne nouvelle ! Votre rendez-vous a été confirmé.

--- Détails du rendez-vous ---
Salon    : {{ $appointment->salon->name }}
Service  : {{ $appointment->service->name }}
Date     : {{ $appointment->scheduled_at->translatedFormat('l d F Y à H:i') }}
Durée    : {{ $appointment->duration }} min
Prix     : {{ number_format($appointment->price, 0, ',', ' ') }} FCFA
Statut   : Confirmé

Vous pouvez consulter vos réservations dans l'application.

À bientôt,
L'équipe {{ $appointment->salon->name }}
