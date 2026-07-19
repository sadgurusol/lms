<?php

namespace App\Services\Generation;

use App\Ai\AiReply;
use App\Ai\AnthropicClient;
use App\Models\CourseGeneration;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns a PDF textbook or a topic brief into a course blueprint for
 * {@see CourseBuilder}, in two phases so it scales to whole textbooks:
 *
 *   1. Outline  — one small request for the course STRUCTURE (levels + titles
 *      only, no teaching text). Structure is compact, so it fits comfortably.
 *   2. Content  — a separate request per content-bearing node to write just
 *      that topic's teaching text. Each stays well under the token ceiling.
 *
 * A PDF source is sent once and prompt-cached, so the per-node content calls
 * reuse it cheaply. See docs/14-course-generation.md.
 */
final class BlueprintGenerator
{
    public function __construct(private readonly AnthropicClient $ai) {}

    private int $inputTokens = 0;

    private int $outputTokens = 0;

    /** Base64 of the source PDF, read and encoded once per run. */
    private ?string $pdfBase64 = null;

    /**
     * @return array{blueprint: array<string, mixed>, inputTokens: int, outputTokens: int}
     */
    public function generate(CourseGeneration $generation): array
    {
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->pdfBase64 = null;

        $version = $generation->schemaVersion;

        // Which level names carry teaching content (lowercased for matching).
        $contentLevels = $version->levels()->get()
            ->filter(fn (SchemaLevel $l) => $l->allows_content)
            ->map(fn (SchemaLevel $l) => mb_strtolower($l->name))
            ->values()
            ->all();

        // Phase 1: structure only.
        $outline = $this->requestOutline($generation, $version);
        $nodes = $outline['nodes'] ?? [];
        if (! is_array($nodes)) {
            $nodes = [];
        }

        // Phase 2: fill teaching content per content-bearing node.
        $this->fillContent($nodes, $contentLevels, $generation, [$generation->name]);

        return [
            'blueprint' => ['nodes' => $nodes],
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
        ];
    }

    /** Phase 1: ask for the nested structure (titles only), and parse it. */
    private function requestOutline(CourseGeneration $generation, SchemaVersion $version): array
    {
        $reply = $this->ai->complete(
            $this->outlineSystemPrompt($version, $generation->name),
            [...$this->sourceBlocks($generation), [
                'type' => 'text',
                'text' => 'Produce the course STRUCTURE — levels and titles only, no teaching content — following the schema and rules.',
            ]],
            (int) config('services.anthropic.generation_max_tokens', 16000),
        );
        $this->tally($reply);

        if ($reply->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The course has too many sections to outline in one pass. Narrow the '
                .'scope (fewer chapters) and try again.'
            );
        }

        return $this->parse($reply->text);
    }

    /**
     * Phase 2: walk the outline and, for each content-bearing node, request its
     * teaching text and set it on the node. Mutates $nodes in place.
     *
     * @param  list<mixed>  $nodes
     * @param  list<string>  $contentLevels
     * @param  list<string>  $path  Titles from the course root down to here.
     */
    private function fillContent(array &$nodes, array $contentLevels, CourseGeneration $generation, array $path): void
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            $level = mb_strtolower(trim((string) ($node['level'] ?? '')));
            $title = trim((string) ($node['title'] ?? '')) ?: 'Untitled';
            $here = [...$path, $title];

            if (in_array($level, $contentLevels, true)) {
                $node['content'] = $this->requestContent($generation, $here);
            }

            $children = $node['children'] ?? null;
            if (is_array($children)) {
                $this->fillContent($children, $contentLevels, $generation, $here);
                $node['children'] = $children;
            }
        }
        unset($node);
    }

    /**
     * Phase 2, one node: write teaching text for the topic at $path. A failure
     * here leaves the node without content rather than sinking the whole course.
     *
     * @param  list<string>  $path
     */
    private function requestContent(CourseGeneration $generation, array $path): string
    {
        $title = end($path) ?: 'this topic';
        $location = implode(' > ', $path);

        try {
            $reply = $this->ai->complete(
                $this->contentSystemPrompt(),
                [...$this->sourceBlocks($generation), [
                    'type' => 'text',
                    'text' => "Write the teaching content for the topic \"{$title}\" "
                        ."(its place in the course: {$location}), grounded in the source material above.",
                ]],
                4000,
            );
            $this->tally($reply);

            return trim($reply->text);
        } catch (\Throwable $e) {
            Log::warning('Course generation: content pass failed for a node.', [
                'topic' => $location,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function outlineSystemPrompt(SchemaVersion $version, string $name): string
    {
        $hierarchy = $this->hierarchy($version);

        return <<<PROMPT
        You are an expert curriculum designer. Outline a complete, well-organised course
        titled "{$name}" as a nested STRUCTURE that conforms EXACTLY to the schema below.

        ## Schema (use these exact level names and nesting; respect the occurrence limits)
        {$hierarchy}

        ## Rules
        - Every node has a "level" (one of the level names above) and a "title".
        - Nest nodes to match the hierarchy: a level's nodes go inside its parent level.
        - Do NOT write any teaching content — titles and structure only. A short one-line
          "summary" per node is allowed but optional.
        - Cover the subject thoroughly but stay within each level's occurrence limits.

        ## Output
        Return ONLY a JSON object, no prose, in this shape (no "content" fields):
        {"nodes":[{"level":"<name>","title":"...","summary":"...","children":[
          {"level":"<name>","title":"..."}]}]}
        PROMPT;
    }

    private function contentSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are an expert teacher writing the lesson for ONE topic in a course.

        Rules:
        - Write clear, accurate teaching material: explanations, worked examples, key points.
        - Plain text only. Blank lines between paragraphs. You may use "## " / "### " for
          subheadings and "- " for bullet lists. No other markup, no JSON, no preamble.
        - Cover just the topic you are asked about, at an appropriate depth. Do not repeat
          the topic title as a heading, and do not write content for other topics.
        PROMPT;
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

    private function tally(AiReply $reply): void
    {
        $this->inputTokens += $reply->inputTokens;
        $this->outputTokens += $reply->outputTokens;
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
