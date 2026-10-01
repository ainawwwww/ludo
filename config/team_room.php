<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Team Room (2v2 Mode) Configuration Options
    |--------------------------------------------------------------------------
    */

    'allowed_max_players' => [2],

    'allowed_turn_seconds' => [15, 30, 45],

    'default_turn_seconds' => 15,

    'allowed_entry_fees' => [0, 500, 1000, 2500, 5000, 10000],

    'code_length' => 6,

    'waiting_ttl_minutes' => 30,

    'platform_cut_percentage' => 0,
];
