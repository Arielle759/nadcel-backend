<?php

// Seul le site Nadcel (Vercel en production, localhost:3000 en développement)
// peut appeler l'API depuis un navigateur. Plusieurs adresses : séparées par
// des virgules dans CORS_ALLOWED_ORIGINS. Par défaut : FRONTEND_URL.
$origins = env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000'));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) $origins),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Authentification par jeton (en-tête Authorization), pas par cookie.
    'supports_credentials' => false,

];
