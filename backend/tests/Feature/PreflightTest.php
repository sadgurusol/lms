<?php

it('passes preflight in a working environment', function () {
    $this->artisan('app:preflight')->assertExitCode(0);
});

it('fails preflight when the app key is missing', function () {
    config(['app.key' => '']);

    $this->artisan('app:preflight')->assertExitCode(1);
});

it('fails preflight in production with a wildcard CORS origin', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['cors.allowed_origins' => ['*']]);

    $this->artisan('app:preflight')->assertExitCode(1);
});
