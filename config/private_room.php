<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Private Room Configuration Options
    |--------------------------------------------------------------------------
    */

    'allowed_max_players' => [2, 4],

    'allowed_turn_seconds' => [10, 15, 30],

    'default_turn_seconds' => 15,

    'allowed_entry_fees' => [0, 500, 1000, 5000],

    'code_length' => 6,

    'join_throttle' => [
        'max_attempts' => 10,
        'decay_seconds' => 60,
    ],
];
