<?php
// No real credentials here on purpose — this file is committed. Real values
// come from environment variables: Azure App Service Application Settings in
// production, or a local shell export (see local.env.sh, gitignored) in dev.
// Rotate the API key from the PayHero dashboard if it's ever exposed outside
// a proper secrets store.
return [
    'channel_id'      => (int)(getenv('PAYHERO_CHANNEL_ID') ?: 0),
    'provider'        => 'm-pesa',
    'network_code'    => '63902', // Safaricom
    'basic_auth'      => getenv('PAYHERO_BASIC_AUTH') ?: '',
    'api_base'        => 'https://backend.payhero.co.ke/api/v2',
];
