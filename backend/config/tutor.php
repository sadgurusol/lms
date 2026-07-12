<?php

return [
    /*
     * A per-learner cost guardrail: the most tutor tokens (input + output) one
     * learner may spend in a calendar month. null = unlimited. This is a runaway
     * -cost brake, not a product tier — set it generously.
     */
    'monthly_token_budget' => env('TUTOR_MONTHLY_TOKEN_BUDGET') !== null
        ? (int) env('TUTOR_MONTHLY_TOKEN_BUDGET')
        : null,
];
