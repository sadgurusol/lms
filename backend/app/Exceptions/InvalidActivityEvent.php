<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An event the client should not have sent. Reported per-event in a batch,
 * never as a failure of the whole batch — one bad event must not reject an hour
 * of a learner's work.
 */
final class InvalidActivityEvent extends RuntimeException {}
