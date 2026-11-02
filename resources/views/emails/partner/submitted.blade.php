Une nouvelle demande de partenariat attend une décision.

Salon     : {{ $salon->name }}
Adresse   : {{ $salon->address }}, {{ $salon->city }}
Téléphone : {{ $salon->phone }}
Demandeur : {{ $salon->manager->name }} ({{ $salon->manager->email }})

Délai annoncé au gérant : 48 heures ouvrées.

Examiner la demande :
{{ $url }}/admin/salons
