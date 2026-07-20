<?php

namespace App\Services\Generation;

use App\Models\AppSetting;

/**
 * Admin-editable guidance for course generation. The base prompts are fixed (they
 * carry the machine contract — schema, JSON shape, plain-text rules); admins add
 * their own house-style guidance on top, which is appended to each prompt. This
 * is where you steer things like how figures are described. See the studio's
 * Generate → Settings page and docs/14-course-generation.md.
 */
class GenerationSettings
{
    public const OUTLINE_KEY = 'generation.outline_instructions';

    public const CONTENT_KEY = 'generation.content_instructions';

    /** The fixed structure prompt. `{{name}}` and `{{schema}}` are filled per run. */
    public const BASE_OUTLINE_PROMPT = <<<'PROMPT'
        You are an expert curriculum designer. Outline a complete, well-organised course
        titled "{{name}}" as a nested STRUCTURE that conforms EXACTLY to the schema below.

        ## Schema (use these exact level names and nesting; respect the occurrence limits)
        {{schema}}

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

    /** The fixed per-topic content prompt. */
    public const BASE_CONTENT_PROMPT = <<<'PROMPT'
        You are an expert teacher writing the lesson for ONE topic in a course.

        Rules:
        - Write clear, accurate teaching material: explanations, worked examples, key points.
        - Plain text only. Blank lines between paragraphs. You may use "## " / "### " for
          subheadings and "- " for bullet lists. No other markup, no JSON, no preamble.
        - Cover just the topic you are asked about, at an appropriate depth. Do not repeat
          the topic title as a heading, and do not write content for other topics.
        - The learner sees only your text — no images or figures are rendered. So describe
          any diagram, shape, or figure fully in words; never write "see Figure 1" or refer
          to a picture the learner cannot see.
        PROMPT;

    public function outlineInstructions(): string
    {
        return trim((string) AppSetting::query()->whereKey(self::OUTLINE_KEY)->value('value'));
    }

    public function contentInstructions(): string
    {
        return trim((string) AppSetting::query()->whereKey(self::CONTENT_KEY)->value('value'));
    }

    /** The full content prompt sent to the model: base plus any admin guidance. */
    public function contentPrompt(): string
    {
        return $this->withGuidance(self::BASE_CONTENT_PROMPT, $this->contentInstructions());
    }

    /** The full structure prompt (tokens already substituted) plus any admin guidance. */
    public function outlinePrompt(string $name, string $schema): string
    {
        $base = str_replace(['{{name}}', '{{schema}}'], [$name, $schema], self::BASE_OUTLINE_PROMPT);

        return $this->withGuidance($base, $this->outlineInstructions());
    }

    public function save(string $outline, string $content): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::OUTLINE_KEY], ['value' => trim($outline) ?: null]);
        AppSetting::query()->updateOrCreate(['key' => self::CONTENT_KEY], ['value' => trim($content) ?: null]);
    }

    private function withGuidance(string $base, string $guidance): string
    {
        return $guidance === '' ? $base : $base."\n\n## Additional guidance\n".$guidance;
    }
}
