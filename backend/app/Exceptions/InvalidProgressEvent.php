<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A progress event the client should not have sent: an unknown node, an unknown
 * state. Reported per-event in a batch flush rather than failing the batch.
 *
 * Distinct from a QueryException or a connection failure — those are ours, not
 * the client's, and must not be quietly reported back as "rejected".
 */
final class InvalidProgressEvent extends RuntimeException {}
