<?php

use App\Ai\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.anthropic.key' => 'test-key']);
    Sleep::fake(); // don't actually wait out the retry backoff
});

it('retries a rate-limited content call and then succeeds', function () {
    Http::fakeSequence('api.anthropic.com/*')
        ->push(['type' => 'error'], 429)
        ->push([
            'content' => [['type' => 'text', 'text' => 'Recovered.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        ], 200);

    $reply = app(AnthropicClient::class)->complete('sys', [['type' => 'text', 'text' => 'hi']]);

    expect($reply->text)->toBe('Recovered.');
    Http::assertSentCount(2);
});

it('gives up on a persistent overload and reports the status', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error'], 529)]);

    expect(fn () => app(AnthropicClient::class)->complete('sys', [['type' => 'text', 'text' => 'hi']]))
        ->toThrow(RuntimeException::class, 'status 529');
});

it('does not retry a non-transient client error', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error'], 400)]);

    expect(fn () => app(AnthropicClient::class)->complete('sys', [['type' => 'text', 'text' => 'hi']]))
        ->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});
