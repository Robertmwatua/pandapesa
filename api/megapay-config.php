<?php
// No real credentials here on purpose — this file is committed. Real values
// come from environment variables: Azure App Service Application Settings in
// production, or a local shell export (see local.env.sh, gitignored) in dev.
return [
    'api_key'  => getenv('MEGAPAY_API_KEY') ?: '',
    'email'    => getenv('MEGAPAY_EMAIL') ?: '',
    'api_base' => 'https://megapay.co.ke/backend/v1',
];
