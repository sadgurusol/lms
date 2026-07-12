<?php

return [
    /*
     * How long each partitioned table keeps data before its old partitions are
     * detached and dropped.
     *
     * Audit logs are a compliance artefact and outlive everything. Activity
     * events are a client's data: the retention window belongs in the contract,
     * and is what bounds the cost of a data-subject erasure request (docs/12 §6).
     */
    'audit_logs_months' => (int) env('RETENTION_AUDIT_MONTHS', 84),      // 7 years
    'activity_events_months' => (int) env('RETENTION_ACTIVITY_MONTHS', 24),

    /* The idempotency keys only need to outlive a client's offline outbox. */
    'activity_event_keys_days' => (int) env('RETENTION_EVENT_KEYS_DAYS', 45),
];
