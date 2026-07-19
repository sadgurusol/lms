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
 * Asks the model for a course outline that conforms to a schema, from a PDF
 * textbook or a topic brief. Returns the parsed blueprint {@see CourseBuilder}
 * consumes, plus token usage. See docs/14-course-generation.md.
 */
final class BlueprintGenerator
{
    public function __construct(private readonly AnthropicClient $ai) {}

    /**
     * @return array{blueprint: array<string, mixed>, inputTokens: int, outputTokens: int}
     */
    public function generate(CourseGeneration $generation): array
    {
        $version = $generation->schemaVersion;

        $reply = $this->ai->complete(
            $this->systemPrompt($version, $generation->name),
            $this->userContent($generation),
            (int) config('services.anthropic.generation_max_tokens', 16000),
        );

        // A reply cut off at the token ceiling is incomplete JSON — say so plainly
        // rather than letting it fail as "not valid JSON".
        if ($reply->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'The outline was too long and got cut off. Narrow the scope '
                .'(fewer chapters, or generate chapter by chapter), then try again.'
            );
        }

        return [
            'blueprint' => $this->parse($reply->text),
            'inputTokens' => $reply->inputTokens,
            'outputTokens' => $reply->outputTokens,
        ];
    }

    private function systemPrompt(SchemaVersion $version, string $name): string
    {
        $hierarchy = $this->hierarchy($version);

        return <<<PROMPT
        You are an expert curriculum designer. Build a complete, well-organised course
        titled "{$name}" as a nested outline that conforms EXACTLY to the schema below.

        ## Schema (use these exact level names and nesting; respect the occurrence limits)
        {$hierarchy}

        ## Rules
        - Every node has a "level" (one of the level names above) and a "title".
        - Nest nodes to match the hierarchy: a level's nodes go inside its parent level.
        - Only levels marked "content" carry a "content" field — write the actual teaching
          text there (clear explanations, examples). Grouping levels have children, not content.
        - Write "content" as plain text with blank lines between paragraphs. You may use
          "## " / "### " for subheadings and "- " for bullet lists. No other markup.
        - Add a short "summary" (one sentence) to grouping nodes where helpful.
        - Cover the material thoroughly but stay within each level's occurrence limits.

        ## Output
        Return ONLY a JSON object, no prose, in this shape:
        {"nodes":[{"level":"<name>","title":"...","summary":"...","children":[
          {"level":"<name>","title":"...","content":"teaching text..."}]}]}
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
     * The user message: a PDF document plus instructions, or just the brief.
     *
     * @return list<array<string, mixed>>
     */
    private function userContent(CourseGeneration $generation): array
    {
        if ($generation->source_type === CourseGeneration::SOURCE_PDF) {
            $bytes = Storage::disk(config('filesystems.default'))->get((string) $generation->pdf_path);
            if ($bytes === null) {
                throw new RuntimeException('The uploaded PDF could not be read.');
            }

            return [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => base64_encode($bytes),
                    ],
                ],
                ['type' => 'text', 'text' => 'Build the course from this textbook, following the schema and rules.'],
            ];
        }

        $brief = trim((string) $generation->brief);

        return [[
            'type' => 'text',
            'text' => "Build the course from this brief, using your own knowledge of the subject:\n\n{$brief}",
        ]];
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
