<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'contact' => [
        'email' => env('FINDCASA_CONTACT_EMAIL', 'aroldeha@gmail.com'),
        'phone' => env('FINDCASA_CONTACT_PHONE', '0157728807'),
        'address' => env('FINDCASA_CONTACT_ADDRESS', 'Calavi Zogbadjè'),
    ],
    'max_monthly_rent' => 45000,
    'property_types' => [
        'Chambre salon simple',
        'Chambre salon sanitaire',
        'Chambre salon semi-sanitaire',
        'Double chambre salon simple',
        'Double chambre salon sanitaire',
        'Double chambre salon semi-sanitaire',
        'Entrée coucher simple',
        'Entrée coucher sanitaire',
        'Entrée coucher semi-sanitaire',
    ],
];
