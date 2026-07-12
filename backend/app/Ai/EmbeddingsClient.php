<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A thin wrapper over the Voyage AI embeddings API.
 *
 * Voyage distinguishes `document` from `query` input, which measurably improves
 * retrieval, so the two entry points are separate. Fakeable via `Http::fake`.
 */
class EmbeddingsClient
{
    private const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

    public function configured(): bool
    {
        return (string) config('services.voyage.key') !== '';
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedDocuments(array $texts): array
    {
        return $texts === [] ? [] : $this->embed($texts, 'document');
    }

    /**
     * @return list<float>
     */
    public function embedQuery(string $text): array
    {
        return $this->embed([$text], 'query')[0];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function embed(array $texts, string $inputType): array
    {
        $key = (string) config('services.voyage.key');

        if ($key === '') {
            throw new RuntimeException('Embeddings are not configured (missing VOYAGE_API_KEY).');
        }

        $response = Http::withToken($key)
            ->timeout(60)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.voyage.model'),
                'input' => $texts,
                'input_type' => $inputType,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("The embeddings service failed (status {$response->status()}).");
        }

        // The API may return items out of order; sort by index before stripping.
        $data = $response->json('data', []);
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            fn (array $item) => array_map('floatval', $item['embedding'] ?? []),
            $data,
        );
    }
}
