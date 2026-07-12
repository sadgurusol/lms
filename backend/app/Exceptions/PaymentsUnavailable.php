<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The payment provider can't open a checkout right now — it isn't configured, or
 * it couldn't be reached. Distinct from a user error (already subscribed, not on
 * sale) so the controller can answer 503, not 422.
 */
class PaymentsUnavailable extends RuntimeException {}
