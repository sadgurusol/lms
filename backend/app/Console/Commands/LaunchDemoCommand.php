<?php

namespace App\Console\Commands;

use App\Launch\CustomJwtValidator;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientKey;
use App\Models\Course;
use App\Models\Product;
use App\Services\Launch\HandleLaunch;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mints a real B2B launch ticket for the demo course, so the Flutter launch flow
 * can be exercised without a live SIS. Dev only.
 *
 * It plays the client's role: generates a keypair, registers the public key,
 * signs a launch JWT, and runs it through the very validator and handler a real
 * launch would — then prints the deep link the app opens.
 */
class LaunchDemoCommand extends Command
{
    protected $signature = 'launch:demo';

    protected $description = 'Create a demo B2B launch ticket and print the app deep link';

    public function handle(CustomJwtValidator $validator, HandleLaunch $handler): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing to run outside the local environment.');

            return self::FAILURE;
        }

        $course = Course::where('code', 'DEMO-101')->first();
        $product = Product::where('sku', 'DEMO-101')->first();
        if ($course === null || $product === null) {
            $this->error('Run `php artisan db:seed --class=DemoContentSeeder` first.');

            return self::FAILURE;
        }

        $client = Client::firstOrCreate(
            ['slug' => 'demo-school'],
            ['name' => 'Demo School', 'status' => Client::ACTIVE, 'integration' => Client::CUSTOM_JWT],
        );

        // The client (not the learner) is entitled to the product on launch.
        ClientEntitlement::firstOrCreate(
            ['client_id' => $client->id, 'product_id' => $product->id, 'starts_at' => now()->subDay()],
            ['seat_model' => ClientEntitlement::UNLIMITED, 'ends_at' => now()->addYear(), 'status' => 'active'],
        );

        // Play the client: a fresh keypair whose public half we register.
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);
        $publicKey = openssl_pkey_get_details($resource)['key'];
        $kid = 'demo-'.Str::lower(Str::random(6));
        ClientKey::create([
            'client_id' => $client->id, 'kid' => $kid, 'algorithm' => 'RS256',
            'public_key' => $publicKey, 'status' => 'active',
        ]);

        $token = JWT::encode([
            'iss' => $client->slug,
            'aud' => config('launch.audience'),
            'sub' => 'demo-student-1',
            'jti' => (string) Str::uuid7(),
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds(120)->timestamp,
            'nonce' => Str::random(16),
            'name' => 'Demo Student',
            'role' => 'learner',
            'context' => ['id' => 'demo-class', 'title' => 'Demo Class'],
            'resource' => ['resource_link_id' => 'rl-demo', 'course_code' => 'DEMO-101'],
        ], $privateKey, 'RS256', $kid);

        $launch = $validator->validate($token);
        $result = $handler->handle($launch, '127.0.0.1', 'artisan launch:demo');
        $ticket = $result['ticket'];

        $this->newLine();
        $this->info('Launch ticket minted (valid a few minutes). Open in the app:');
        $this->line('  Native  : <comment>exquislearner://l/'.$ticket.'</comment>');
        $this->line('  Web      : <comment>'.url("/l/{$ticket}").'</comment>  (or point the running web app at /l/'.$ticket.')');
        $this->newLine();

        return self::SUCCESS;
    }
}
