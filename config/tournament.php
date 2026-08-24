<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tournament Loss Action
    |--------------------------------------------------------------------------
    |
    | Defines what happens to a participant's level when they lose a match.
    | Options:
    |   'stay' - Participant stays at their current level (no elimination/level drop).
    |   'drop' - Participant drops down 1 level (clamped at level 1).
    |
    */
    'loss_action' => env('TOURNAMENT_LOSS_ACTION', 'stay'),
];
