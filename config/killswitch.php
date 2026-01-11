<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill Switch Configuration
    |--------------------------------------------------------------------------
    |
    | Emergency toggles to disable critical operations.
    | Requires maker-checker approval to toggle.
    |
    */

    'global' => env('FEATURE_KILL_SWITCH_GLOBAL', false),

    'deposits' => env('FEATURE_KILL_SWITCH_DEPOSITS', false),

    'withdrawals' => env('FEATURE_KILL_SWITCH_WITHDRAWALS', false),

    'escrow_create' => env('FEATURE_KILL_SWITCH_ESCROW_CREATE', false),

    'escrow_release' => env('FEATURE_KILL_SWITCH_ESCROW_RELEASE', false),

    'escrow_refund' => env('FEATURE_KILL_SWITCH_ESCROW_REFUND', false),

];
