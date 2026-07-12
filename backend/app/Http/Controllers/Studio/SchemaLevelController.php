<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\ContentBlocks\BlockType;
use App\Http\Controllers\Controller;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Support\FractionalIndex;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchemaLevelController extends Controller
{
    public function store(Request $request, SchemaVersion $version): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_UPDATE), 403);

        $data = $this->validated($request, $version);
        $parent = $data['parent_level_id'] === null
            ? null
            : SchemaLevel::where('schema_version_id', $version->id)->findOrFail($data['parent_level_id']);

        try {
            // Postgres aborts a transaction on any error. Wrapping the write
            // makes it a SAVEPOINT, so a trigger's rejection rolls back to here
            // rather than poisoning an enclosing transaction and taking the
            // `back()` redirect down with it.
            DB::transaction(fn () => SchemaLevel::create([
                ...$data,
                'schema_version_id' => $version->id,
                // Depth is derived, never supplied.
                'depth' => $parent === null ? 0 : $parent->depth + 1,
                'sort_key' => $this->nextSortKey($version, $parent?->id),
            ]));
        } catch (QueryException $e) {
            return back()->with('error', $this->humanise($e));
        }

        return back()->with('success', "Added level “{$data['name']}”.");
    }

    public function update(Request $request, SchemaLevel $level): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_UPDATE), 403);

        $version = $level->schemaVersion;
        $data = $this->validated($request, $version, $level);

        // parent_level_id is not editable: moving a level would silently
        // reinterpret depth and every course authored against it. Delete and
        // re-add instead — the version is a draft, so nothing depends on it yet.
        unset($data['parent_level_id']);

        try {
            DB::transaction(fn () => $level->update($data));
        } catch (QueryException $e) {
            return back()->with('error', $this->humanise($e));
        }

        return back()->with('success', "Updated “{$level->name}”.");
    }

    public function destroy(Request $request, SchemaLevel $level): RedirectResponse
    {
        abort_unless($request->user()->can(Permissions::SCHEMA_UPDATE), 403);

        $name = $level->name;

        try {
            // Child levels cascade: a level with no parent has no meaning.
            DB::transaction(fn () => $level->delete());
        } catch (QueryException $e) {
            return back()->with('error', $this->humanise($e));
        }

        return back()->with('success', "Removed “{$name}” and its child levels.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, SchemaVersion $version, ?SchemaLevel $level = null): array
    {
        return $request->validate([
            'parent_level_id' => [
                'present', 'nullable', 'uuid',
                Rule::exists('schema_levels', 'id')->where('schema_version_id', $version->id),
                // A level cannot parent itself.
                Rule::notIn([$level?->id]),
            ],
            'name' => ['required', 'string', 'max:60'],
            'plural_name' => ['required', 'string', 'max:60'],
            'min_occurrences' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_occurrences' => ['present', 'nullable', 'integer', 'min:1', 'max:1000', 'gte:min_occurrences'],
            'allows_content' => ['required', 'boolean'],
            'allowed_block_types' => ['array'],
            'allowed_block_types.*' => [Rule::in(BlockType::names())],
            'allows_assessment' => ['required', 'boolean'],
            'numbering_style' => ['required', Rule::in(SchemaLevel::NUMBERING_STYLES)],
            'label_template' => ['required', 'string', 'max:120'],
        ]);
    }

    private function nextSortKey(SchemaVersion $version, ?string $parentLevelId): string
    {
        $last = SchemaLevel::where('schema_version_id', $version->id)
            ->where('parent_level_id', $parentLevelId)
            ->orderByDesc('sort_key')
            ->value('sort_key');

        return FractionalIndex::between($last, null);
    }

    /**
     * The database is the authority on what a schema may be, and it says so in
     * SQL. Translate rather than duplicate the rule here.
     */
    private function humanise(QueryException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'cannot be modified') => 'This version is published and immutable. Clone it into a draft first.',
            str_contains($message, 'schema_levels_content_needs_block_types') => 'A level that allows content must permit at least one block type.',
            str_contains($message, 'schema_levels_max_occurrences_check') => 'The maximum must not be below the minimum.',
            default => 'The database refused that change.',
        };
    }
}
