<?php

namespace App\Services\Generation;

use App\Ai\AnthropicClient;
use App\Models\CourseGeneration;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns a PDF textbook or a topic brief into a course, in two phases so it scales
 * to whole textbooks without one giant, timeout-prone request:
 *
 *   - {@see outline()}     — one small request for the course STRUCTURE (levels
 *     and titles only, no teaching text). Structure is compact, so it fits.
 *   - {@see contentFor()}  — one request per content-bearing node for just that
 *     topic's teaching text. The orchestration ({@see GenerateCourseJob},
 *     {@see GenerateContentJob}) runs these as separate short queue jobs.
 *
 * A PDF source is sent once per request and prompt-cached, so the per-node
 * content calls reuse it cheaply. See docs/14-course-generation.md.
 */
final class BlueprintGenerator
{
    public function __construct(
        private readonly AnthropicClient $ai,
        private readonly GenerationSettings $settings,
    ) {}

    /** Base64 of the source PDF, read and encoded once per instance. */
    private ?string $pdfBase64 = null;

    /**
     * Phase 1: ask for the nested course structure (titles only) and parse it.
     *
     * @return array{blueprint: array<string, mixed>, inputTokens: int, outputTokens: int}
     */
    public function outline(CourseGeneration $generation): array
    {
        $reply = $this->ai->complete(
            $this->settings->outlinePrompt($generation->name, $this->hierarchy($generation->schemaVersion)),
            [...$this->sourceBlocks($generation), [
                'type' => 'text',
                'text' => 'Produce the course STRUCTURE — levels and titles only, no teaching content — following the schema and rules.',
            ]],
            (int) config('services.anthropic.generation_max_tokens', 16000),
        );

        if ($reply->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The course has too many sections to outline in one pass. Narrow the '
                .'scope (fewer chapters) and try again.'
            );
        }

        return [
            'blueprint' => $this->parse($reply->text),
            'inputTokens' => $reply->inputTokens,
            'outputTokens' => $reply->outputTokens,
        ];
    }

    /**
     * Phase 2, one node: teaching text for the topic at $path (root title first,
     * this topic's title last), grounded in the source material.
     *
     * @param  list<string>  $path
     * @return array{text: string, inputTokens: int, outputTokens: int}
     */
    public function contentFor(CourseGeneration $generation, array $path): array
    {
        $title = end($path) ?: 'this topic';
        $location = implode(' > ', $path);

        $reply = $this->ai->complete(
            $this->settings->contentPrompt(),
            [...$this->sourceBlocks($generation), [
                'type' => 'text',
                'text' => "Write the teaching content for the topic \"{$title}\" "
                    ."(its place in the course: {$location}), grounded in the source material above.",
            ]],
            4000,
        );

        return [
            'text' => trim($reply->text),
            'inputTokens' => $reply->inputTokens,
            'outputTokens' => $reply->outputTokens,
        ];
    }

    /** Render the level tree as an indented, annotated outline for the prompt. */
    private function hierarchy(SchemaVersion $version): string
    {
        $levels = $version->levels()->get();
        $byParent = $levels->groupBy(fn (SchemaLevel $l) => $l->parent_level_id ?? 'root');

        $lines = [];
        $walk = function (string $parentKey, int $indent) use (&$walk, $byParent, &$lines): void {
            foreach ($byParent->get($parentKey, collect()) as $level) {
                /** @var SchemaLevel $level */
                $max = $level->max_occurrences === null ? '∞' : (string) $level->max_occurrences;
                $kind = $level->allows_content ? 'content' : 'grouping';
                $lines[] = str_repeat('  ', $indent)
                    ."- {$level->name} ({$level->min_occurrences}..{$max} per parent; {$kind})";
                $walk($level->id, $indent + 1);
            }
        };
        $walk('root', 0);

        return implode("\n", $lines);
    }

    /**
     * The grounding blocks shared by both phases: the PDF document (sent once,
     * prompt-cached so per-node content calls reuse it cheaply) or the brief.
     *
     * @return list<array<string, mixed>>
     */
    private function sourceBlocks(CourseGeneration $generation): array
    {
        if ($generation->source_type === CourseGeneration::SOURCE_PDF) {
            return [[
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'application/pdf',
                    'data' => $this->pdfBase64($generation),
                ],
                // Cache the (large) textbook so the many content calls don't
                // re-pay full input cost for it.
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        }

        $brief = trim((string) $generation->brief);

        return [[
            'type' => 'text',
            'text' => "Source material — a brief; use your own knowledge of the subject to expand it:\n\n{$brief}",
        ]];
    }

    private function pdfBase64(CourseGeneration $generation): string
    {
        if ($this->pdfBase64 === null) {
            $bytes = Storage::disk(config('filesystems.default'))->get((string) $generation->pdf_path);
            if ($bytes === null) {
                throw new RuntimeException('The uploaded PDF could not be read.');
            }
            $this->pdfBase64 = base64_encode($bytes);
        }

        return $this->pdfBase64;
    }

    /**
     * Extract the JSON object from the model's reply (it is told to return only
     * JSON, but a stray sentence or code fence must not break the run).
     *
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            $this->logRaw($text);
            throw new RuntimeException('The AI did not return a usable outline.');
        }

        $json = substr($text, $start, $end - $start + 1);

        // Models routinely emit long "content" fields with real newlines/tabs,
        // which are invalid inside a JSON string; escape those before decoding.
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            $decoded = json_decode($this->escapeControlCharsInStrings($json), true);
        }

        if (! is_array($decoded)) {
            $this->logRaw($text);
            throw new RuntimeException('The AI outline was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Escape raw control characters (newlines, tabs, etc.) that appear *inside*
     * JSON string literals, which PHP's json_decode rejects. Walks the text
     * tracking string/escape state so structural whitespace is left untouched.
     */
    private function escapeControlCharsInStrings(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $len = strlen($json);

        for ($i = 0; $i < $len; $i++) {
            $ch = $json[$i];

            if ($escaped) {
                $out .= $ch;
                $escaped = false;

                continue;
            }

            if ($ch === '\\') {
                $out .= $ch;
                $escaped = true;

                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;
                $out .= $ch;

                continue;
            }

            if ($inString && ord($ch) < 0x20) {
                $out .= match ($ch) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\f" => '\\f',
                    "\x08" => '\\b',
                    default => sprintf('\\u%04x', ord($ch)),
                };

                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    private function logRaw(string $text): void
    {
        Log::warning('Course generation: unparseable AI outline.', [
            'length' => strlen($text),
            'head' => mb_substr($text, 0, 500),
            'tail' => mb_substr($text, -500),
        ]);
    }
}
