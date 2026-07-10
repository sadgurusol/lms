<?php

namespace App\Services\Publishing;

use RuntimeException;

final class PublishBlocked extends RuntimeException
{
    /** @param list<Finding> $findings */
    public function __construct(public readonly array $findings)
    {
        $count = count($findings);
        parent::__construct("This course is not publishable: {$count} error(s) must be resolved first.");
    }
}
