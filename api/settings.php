<?php
header('Content-Type: application/json');
echo json_encode([
    'graph' => [
        'speed'            => 350,
        'y_max'            => 0.12,
        'spike_frequency'  => 0.08,
        'crash_frequency'  => 0.01,
        'base_level'       => 0.025,
        'spike_max'        => 0.105,
        'crash_depth'      => -0.17,
    ],
    'trade' => [
        'duration'             => 60,
        'min_stake'            => 10,
        'max_stake'            => 50000,
        'min_deposit'          => 150,
        'min_withdrawal'       => 200,
        'max_multiplier'       => 5.0,
        'prestart_wait'        => 5,
        'autosell_multiplier'  => 3.0,
    ],
    'payments' => [
        'deposit_currency' => 'kes',
        'checkout_method'  => 'megapay',
        'usd_rate'         => 0,
    ],
]);