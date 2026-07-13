<?php

return [
    /*
     * A per-learner cost guardrail: the most tutor tokens (input + output) one
     * learner may spend in a calendar month. null = unlimited. This is a runaway
     * -cost brake, not a product tier — set it generously.
     */
    // `filled()`, not `!== null`: an empty `TUTOR_MONTHLY_TOKEN_BUDGET=` in .env
    // is an empty string, which must mean "unlimited" — never a cap of 0.
    'monthly_token_budget' => filled(env('TUTOR_MONTHLY_TOKEN_BUDGET'))
        ? (int) env('TUTOR_MONTHLY_TOKEN_BUDGET')
        : null,
];
