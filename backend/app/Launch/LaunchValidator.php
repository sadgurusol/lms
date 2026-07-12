<?php

namespace App\Launch;

interface LaunchValidator
{
    /** @throws InvalidLaunch */
    public function validate(string $token): LaunchRequest;
}
