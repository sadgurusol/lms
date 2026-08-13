<?php

namespace App\Console\Commands;

use App\Models\ContentBlock;
use App\Services\Generation\ContentWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Reformats existing rich_text blocks through the improved markdown-to-Portable
 * Text converter (inline bold/italic/code marks, per-line lists), fixing content
 * authored before the converter understood inline marks. Idempotent: re-running
 * is a no-op once content is already well-formed.
 */
class ReformatRichTextCommand extends Command
{
    protected $signature = 'lms:reformat-rich-text';

    protected $description = 'Re-parse rich_text blocks so inline markdown renders instead of showing literally';

    public function handle(ContentWriter $writer): int
    {
        // A one-off backfill: the block save hook lazy-loads node/course relations
        // for its checks — fine here, unlike in a request.
        Model::preventLazyLoading(false);

        $changed = 0;
        $total = 0;

        ContentBlock::where('type', 'rich_text')->chunkById(200, function ($blocks) use ($writer, &$changed, &$total) {
            foreach ($blocks as $block) {
                $total++;
                $body = $block->payload['body'] ?? null;
                if (! is_array($body)) {
                    continue;
                }

                $new = $writer->toPortableText($writer->bodyToMarkdown($body));

                if ($new !== $body) {
                    $block->update(['payload' => ['format' => 'portable_text', 'body' => $new]]);
                    $changed++;
                }
            }
        });

        $this->info("Reformatted {$changed} of {$total} rich_text blocks.");

        return self::SUCCESS;
    }
}
