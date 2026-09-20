<?php
// Copy this file to payhero-config.php for local dev, or set these same names
// as Azure App Service Application Settings in production.
return [
    'channel_id'      => (int)(getenv('PAYHERO_CHANNEL_ID') ?: 0),
    'provider'        => 'm-pesa',
    'network_code'    => '63902', // Safaricom
    'basic_auth'      => getenv('PAYHERO_BASIC_AUTH') ?: 'Basic <base64 username:password from PayHero dashboard>',
    'api_base'        => 'https://backend.payhero.co.ke/api/v2',
];
