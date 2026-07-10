# Authoring, Review & Publishing

## 1. The schema drives the editor

The authoring UI never hardcodes "Unit" or "Lesson". Given a selected node, the
editor asks the API what may be created beneath it:

```
GET /api/courses/{course}/nodes/{node}/allowed-children
→ [ { "schema_level_id": "...", "name": "Lesson", "plural_name": "Lessons",
      "remaining": 12, "numbering_style": "numeric" } ]
```

`remaining` is `max_occurrences - current_child_count` (`null` = unbounded). The
UI renders "+ Add Lesson", disabled when `remaining == 0`. For a root selection
the same endpoint is `GET /api/courses/{course}/allowed-children`.

Likewise the block palette on a node is
`schema_level.allowed_block_types` — nothing more, nothing less. An author
authoring against a schema whose `Chapter` level permits only `rich_text` and
`callout` simply does not see a "Add Video" button.

**Consequence:** adding a new schema, or a new level to a schema, requires zero
frontend changes. That is the payoff for the whole design.

## 2. Editing rules

| Operation | Rule |
|---|---|
| Create node | Level must be an allowed child of the parent's level (I3). `max_occurrences` enforced *hard* — you cannot exceed it. |
| Move node | Target parent's level must equal the node's level's `parent_level_id`. Subtree moves wholesale; `path`/`depth` rewritten (see `02-§3`). |
| Reorder | Assign a new `sort_key` between neighbours. Single-row update. |
| Delete node | Soft-delete, cascading soft-delete to descendants and blocks. `min_occurrences` violations become publish-time errors, not delete-time blocks. |
| Change course schema version | Forbidden once any node exists (I1). Offer "Clone course onto new schema" instead — a migration wizard that maps old levels to new. |
| Concurrent edit | Optimistic locking: every mutating request carries `If-Match: <etag>` derived from `updated_at`. `409 Conflict` on mismatch. |

## 3. Publish-time validation

Run as a pure function over the draft tree. Returns a list of findings, each
with a severity, an anchor, and a human message. **Errors block publication;
warnings do not.**

| Code | Severity | Rule |
|---|---|---|
| `E_MIN_OCCURRENCES` | error | Node of level *L* has fewer than `min_occurrences` children of a required child level. |
| `E_MAX_OCCURRENCES` | error | …more than `max_occurrences`. (Should be unreachable; hard-enforced on create.) |
| `E_EMPTY_LEAF` | error | A node at a level with `allows_content = true` and no child levels has zero content blocks. |
| `E_ORPHAN_LEVEL` | error | A required root level has zero instances. |
| `E_MEDIA_NOT_READY` | error | A `video`/`image`/`attachment` block references media with `status != 'ready'`. |
| `E_ASSESSMENT_EMPTY` | error | An assessment has zero questions, or `total_points = 0`. |
| `E_ASSESSMENT_POOL` | error | `settings.question_pool_size` exceeds the number of linked questions. |
| `E_BLOCK_SCHEMA` | error | A block's `payload` fails its JSON Schema. |
| `E_BROKEN_REF` | error | A `rich_text` internal link points at a node not in this course. |
| `W_UNRESOLVED_COMMENTS` | warning | Open review comments remain. |
| `W_NO_ASSESSMENT` | warning | A level that `allows_assessment` has none anywhere in the course. |
| `W_LONG_NODE` | warning | Node has > 30 content blocks. |
| `W_MISSING_ALT` | warning | Image block with empty `alt`. Accessibility. |
| `W_NO_CAPTIONS` | warning | Video block with no caption track. Accessibility. |

```php
// app/Services/Publishing/CourseValidator.php
final class CourseValidator
{
    /** @return Finding[] */
    public function validate(Course $course): array;
}
```

Expose it as `GET /api/courses/{course}/validate` so the editor can show a
live "Readiness" panel. Same code path as publish — never two implementations.

## 4. Review flow

1. Author calls `POST /courses/{c}/submit-review` → `workflow_state = in_review`,
   a `review_request` opens, assignee notified. The draft tree is **not** frozen;
   authors may keep editing (freezing it causes more pain than it prevents).
2. Reviewer walks the tree. `POST /review-requests/{r}/comments` with
   `anchor_type`/`anchor_id` pins a comment to a node or a block. Threading via
   `parent_comment_id`.
3. Reviewer decides:
   - `POST /review-requests/{r}/approve` → `workflow_state = approved`.
   - `POST /review-requests/{r}/request-changes` → `workflow_state = changes_requested`.
4. Author resolves comments (`PATCH /comments/{id}` `{"resolved": true}`) and
   resubmits. A new `review_request` row; the old one is closed. History is kept.
5. Admin publishes an `approved` course.

Guardrail: `one_open_review_per_course` unique index prevents a double
submission race.

Separation of duties (`03-§5`) means the approve button is invisible to the
author, not merely 403 on click.

## 5. The publish pipeline

```php
// app/Services/Publishing/PublishCourse.php
public function handle(Course $course, User $actor, ?string $changelog): CoursePublication
{
    return DB::transaction(function () use ($course, $actor, $changelog) {
        $course = Course::lockForUpdate()->findOrFail($course->id);   // serialise (I11)

        $findings = $this->validator->validate($course);
        if ($errors = array_filter($findings, fn ($f) => $f->isError())) {
            throw new ValidationFailed($errors);
        }

        $snapshot = $this->snapshotBuilder->build($course);           // §5.1
        $etag     = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        $publication = CoursePublication::create([
            'course_id'         => $course->id,
            'number'            => $course->publications()->max('number') + 1,
            'schema_version_id' => $course->schema_version_id,
            'snapshot'          => $snapshot,
            'snapshot_etag'     => $etag,
            'media_manifest'    => $this->mediaManifest($snapshot),
            'changelog'         => $changelog,
            'published_by'      => $actor->id,
        ]);

        $course->update([
            'latest_publication_id' => $publication->id,
            'workflow_state'        => 'published',
        ]);

        AuditLog::record($actor, 'course.published', $course, after: ['publication' => $publication->number]);

        return $publication;
    });
}
// after commit:
//   dispatch(new WarmPublicationCache($publication));
//   dispatch(new NotifyEnrolledUsers($publication));
//   dispatch(new IndexPublicationForSearch($publication));
```

Dispatch jobs **after commit** (`DB::afterCommit()` or `->afterCommit()` on the
job) or a fast worker will read a publication that does not exist yet.

### 5.1 Snapshot shape

Self-contained. A client that has this JSON plus the media files can render the
entire course offline with no further API calls.

```jsonc
{
  "publication": { "id": "...", "number": 7, "published_at": "2026-07-10T09:00:00Z" },
  "course": {
    "id": "...", "title": "Grade 10 English", "code": "ENG-G10",
    "subject": "English", "grade_band": "Grade 10", "language": "en",
    "cover": { "media_id": "...", "url": "https://cdn/…" }
  },
  "schema": {
    "id": "...", "version": 1, "name": "Textbook (3-tier)",
    "levels": [
      { "id": "L1", "parent_level_id": null, "name": "Part",  "plural_name": "Parts",
        "numbering_style": "roman",   "label_template": "Part {n}" },
      { "id": "L2", "parent_level_id": "L1", "name": "Chapter", "plural_name": "Chapters",
        "numbering_style": "numeric", "label_template": "Chapter {n}: {title}" },
      { "id": "L3", "parent_level_id": "L2", "name": "Topic",   "plural_name": "Topics",
        "numbering_style": "numeric", "label_template": "{n}. {title}" }
    ]
  },
  "tree": [
    {
      "id": "n1", "level_id": "L1", "title": "Language & Grammar",
      "number": "I", "label": "Part I", "path": "n1",
      "blocks": [],
      "assessments": [],
      "children": [
        {
          "id": "n2", "level_id": "L2", "title": "Tenses",
          "number": "1", "label": "Chapter 1: Tenses", "path": "n1.n2",
          "blocks": [
            { "id": "b1", "type": "rich_text", "payload": { "format": "portable_text", "body": [/*…*/] } }
          ],
          "assessments": [
            { "id": "a1", "kind": "test", "title": "Chapter test",
              "question_count": 20, "total_points": 20,
              "settings": { "time_limit_s": 1800, "max_attempts": 2, "pass_percentage": 60,
                            "shuffle_questions": true, "show_answers": "after_submit" } }
          ],
          "children": [
            {
              "id": "n3", "level_id": "L3", "title": "Simple Past",
              "number": "1", "label": "1. Simple Past", "path": "n1.n2.n3",
              "blocks": [
                { "id": "b2", "type": "video",
                  "payload": { "media_id": "m9", "playback_id": "abc123",
                               "duration_s": 412, "captions": [{ "lang": "en", "url": "…" }] } },
                { "id": "b3", "type": "rich_text", "payload": { /*…*/ } }
              ],
              "assessments": [],
              "children": []
            }
          ]
        }
      ]
    }
  ],
  "media_manifest": [
    { "media_id": "m9", "kind": "video", "bytes": 48210233, "playback_id": "abc123",
      "poster_url": "https://cdn/…/poster.jpg" }
  ]
}
```

Notes:
- **Numbering is baked in at publish.** `number` and `label` are computed from
  `numbering_style` + `label_template` + sibling position. The client never
  computes "Chapter 3" — otherwise two clients disagree after a reorder.
- Assessments carry `question_count`, not questions. Questions are fetched when
  an attempt starts (`05-§4`), so the answer key is never in the snapshot.
- `media_manifest` is what the Flutter app iterates to pre-download for offline.

### 5.2 Changelog diffing

`number = 7` vs `number = 6`: diff the two snapshots by node id to produce
"3 topics added, 1 chapter reordered, 2 videos replaced". Cheap, and it makes
the publication history genuinely useful. Store the computed diff in
`changelog` at publish time (not on read — snapshot 6 might be archived to S3).

## 6. Rollback

`POST /courses/{c}/publications/{n}/promote` sets
`latest_publication_id` to an earlier publication. Nothing is mutated; the old
snapshot is still there. This is the strongest argument for the snapshot design
— rollback is one UPDATE, and no author's in-flight work is disturbed.

Attempts and progress reference `publication_id`, so historical data stays
correctly attributed to the version the learner actually saw.

## 7. Caching

- `GET /courses/{c}/publications/latest` returns `ETag: <snapshot_etag>` and
  `Cache-Control: private, max-age=0, must-revalidate`.
- Client sends `If-None-Match`; server answers `304` from Redis without touching
  Postgres. A course snapshot is read thousands of times and written monthly.
- Key: `pub:{course_id}:latest` → `{etag, body}`. Invalidated in
  `WarmPublicationCache`.
