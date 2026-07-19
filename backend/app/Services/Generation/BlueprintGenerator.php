<?php

namespace App\Services\Generation;

use App\Ai\AnthropicClient;
use App\Models\CourseGeneration;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
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
        );

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
     * JSON, but a stray sentence must not break the run).
     *
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('The AI did not return a usable outline.');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The AI outline was not valid JSON.');
        }

        return $decoded;
    }
}
