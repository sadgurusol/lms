# Domain Model

## 1. Entity catalogue

### Identity
- **User** — `name`, `email`, `password`, `status` (`active|invited|suspended`), `locale`, `last_seen_at`.
- **Role** — `admin`, `content_author`, `content_reviewer`, `viewer`. Global.
- **CourseGrant** — a *scoped* role: user U is `author` (or `reviewer`) **on course C**. Global role says what you may do in principle; the grant says where. See `03-rbac.md`.

### Schema definition
- **CourseSchema** — `name`, `slug`, `description`, `status`.
- **SchemaVersion** — `course_schema_id`, `version` (int), `status` (`draft|published|archived`), `published_at`. Immutable once published.
- **SchemaLevel** — one node type inside a version.

  | Field | Meaning |
  |---|---|
  | `parent_level_id` | `null` ⇒ this level sits directly under the course |
  | `name` / `plural_name` | "Lesson" / "Lessons" — drives all UI labels |
  | `depth` | 0-based, derived, denormalised for cheap validation |
  | `min_occurrences` | min instances of this level under one parent node |
  | `max_occurrences` | `null` = unbounded |
  | `allows_content` | may `ContentBlock`s attach to nodes at this level? |
  | `allowed_block_types` | `jsonb` array, e.g. `["rich_text","video","attachment"]` |
  | `allows_assessment` | may quizzes/tests attach here? |
  | `numbering_style` | `none｜numeric｜roman｜alpha` |
  | `label_template` | e.g. `"Unit {n}: {title}"` |

  > **Levels form a tree, not a chain.** Two levels may share a
  > `parent_level_id`, which lets a schema say "a Unit contains Lessons *or*
  > standalone Topics". Costs nothing and buys real flexibility. Most schemas
  > will still be linear.

### Course content
- **Course** — `title`, `code`, `subject`, `grade_band`, `language`, `schema_version_id`, `workflow_state`, `latest_publication_id`, `cover_media_id`.
- **CourseNode** — `course_id`, `parent_id`, `schema_level_id`, `title`, `slug`, `summary`, `sort_key`, `path` (`ltree`), `depth`.
- **ContentBlock** — `course_node_id`, `type`, `sort_key`, `payload` (`jsonb`), `media_id?`.

  Block types and their payloads:

  | `type` | `payload` shape |
  |---|---|
  | `rich_text` | `{ "format": "portable_text", "body": [...] }` |
  | `video` | `{ "media_id": "...", "captions": [...], "duration_s": 412 }` |
  | `image` | `{ "media_id": "...", "alt": "...", "caption": "..." }` |
  | `attachment` | `{ "media_id": "...", "filename": "...", "size": 91234 }` |
  | `embed` | `{ "provider": "youtube", "url": "..." }` |
  | `callout` | `{ "variant": "warning", "body": [...] }` |
  | `assessment` | `{ "assessment_id": "..." }` — inline reference |

  Each type has a JSON Schema in `app/ContentBlocks/schemas/*.json`, validated
  on write. **Do not** make one table per block type; you will regret it by the
  fourth type. Do validate — an unvalidated `jsonb` column is a landfill.

- **Media** — `disk`, `path`, `mime`, `size`, `checksum`, `provider_asset_id` (Mux/Stream), `status` (`uploading|processing|ready|failed`), `uploaded_by`.

- **CoursePublication** — `course_id`, `number` (int, monotonic), `snapshot` (`jsonb`, the whole rendered tree), `schema_version_id`, `published_by`, `published_at`, `changelog`.

### Review
- **ReviewRequest** — `course_id`, `submitted_by`, `assigned_to`, `state` (`open|approved|changes_requested|withdrawn`), `due_at`, `decided_at`, `decision_note`.
- **ReviewComment** — `review_request_id`, `author_id`, `body`, `anchor_type` (`course|node|block`), `anchor_id`, `parent_comment_id` (threading), `resolved_at`, `resolved_by`.

### Assessment
Covered in depth in `05-assessments.md`. Entities: `QuestionBank`, `Question`,
`QuestionOption`, `Assessment`, `AssessmentSection`, `AssessmentQuestion`,
`AssessmentAttempt`, `AttemptAnswer`.

### Delivery
- **Enrollment** — `user_id`, `course_id`, `enrolled_at`, `expires_at`.
- **NodeProgress** — `user_id`, `publication_id`, `course_node_id`, `state` (`not_started|in_progress|completed`), `seconds_spent`, `last_position` (video resume), `completed_at`.

### Cross-cutting
- **AuditLog** — `actor_id`, `action`, `subject_type`, `subject_id`, `before` (`jsonb`), `after` (`jsonb`), `ip`, `created_at`.

---

## 2. Invariants

These are the rules the system must never violate. Enforce each at the layer
noted — application-only enforcement will drift.

| # | Invariant | Enforced by |
|---|---|---|
| I1 | A course's `schema_version_id` never changes after the first node is created. | Policy + DB trigger |
| I2 | `node.parent` is null ⟺ `node.level.parent_level_id` is null. | FormRequest + trigger |
| I3 | If `node.parent` is non-null, then `node.level.parent_level_id == node.parent.level_id`. | Trigger (`assert_node_level_matches_parent`) |
| I4 | `node.schema_level_id` belongs to `course.schema_version_id`. | FK-ish check in trigger |
| I5 | A `ContentBlock` may only exist on a node whose level has `allows_content = true`, and its `type` ∈ `allowed_block_types`. | FormRequest + trigger |
| I6 | An `Assessment` may only attach to a node whose level has `allows_assessment = true`. | FormRequest |
| I7 | Sibling `sort_key`s are unique within `(parent_id)`. | Unique index |
| I8 | `node.path` is always `parent.path || node.id`; `node.depth = parent.depth + 1`. | Trigger on insert/update of `parent_id` |
| I9 | A published `SchemaVersion` is immutable. | Trigger rejecting `UPDATE`/`DELETE` where `status='published'` |
| I10 | A `CoursePublication` is immutable. | Trigger |
| I11 | `publication.number` is contiguous and monotonic per course. | Unique index + `SELECT … FOR UPDATE` on course row |
| I12 | Cardinality (`min_occurrences`/`max_occurrences`) is checked **at publish time**, not on every save. | Publish validator |
| I13 | An `AttemptAnswer` references a `Question` that is in the attempt's `Assessment`. | FK on `(attempt_id, assessment_question_id)` |
| I14 | Correct-answer data never reaches a Viewer before submission. | API resource layer (separate `QuestionViewerResource`) |

> **I12 is deliberate.** Enforcing "a Unit must have ≥ 1 Lesson" on every save
> makes the editor unusable — you cannot create an empty Unit and then fill it.
> Validate structure at the publish gate; surface violations continuously in the
> editor as *warnings*.

---

## 3. State machines

### 3.1 SchemaVersion

```
draft ──publish──► published ──deprecate──► archived
  │
  └──discard──► (deleted)
```
Editing a `published` version is not possible. "Edit schema" in the UI clones
the version into a new `draft` with `version = max+1`.

### 3.2 Course workflow

```
                ┌───────────────────────────────┐
                │                               │
draft ──submit──► in_review ──request_changes──►│ changes_requested
  ▲                   │                          │        │
  │                   │ approve                  │        │ resume
  │                   ▼                          │        ▼
  └───── edit ──── approved ──publish──► published ───────┘
                                              │
                                              └──archive──► archived
```

`published` is not a terminal, editable-frozen state. The moment an Author
touches a published course the *draft tree* diverges from the *latest
publication*; `workflow_state` returns to `draft` while
`latest_publication_id` stays put. Viewers are unaffected. This is the whole
point of the snapshot design.

### 3.3 AssessmentAttempt

```
in_progress ──submit──► submitted ──auto_grade──► graded
     │                      │                        ▲
     │ timeout              │ needs manual grading   │
     ▼                      ▼                        │
  expired              awaiting_review ──grade───────┘
```

`awaiting_review` exists only when the assessment contains
`short_answer` / `essay` questions.

---

## 4. Worked example — schema "Part → Chapter → Topic"

```jsonc
// SchemaVersion 1 of CourseSchema "Textbook (3-tier)"
[
  { "id": "L1", "parent_level_id": null, "name": "Part",
    "min_occurrences": 1, "max_occurrences": null,
    "allows_content": false, "allows_assessment": false,
    "numbering_style": "roman", "label_template": "Part {n}" },

  { "id": "L2", "parent_level_id": "L1", "name": "Chapter",
    "min_occurrences": 1, "max_occurrences": null,
    "allows_content": true, "allowed_block_types": ["rich_text","callout"],
    "allows_assessment": true,          // end-of-chapter test
    "numbering_style": "numeric", "label_template": "Chapter {n}: {title}" },

  { "id": "L3", "parent_level_id": "L2", "name": "Topic",
    "min_occurrences": 1, "max_occurrences": 40,
    "allows_content": true,
    "allowed_block_types": ["rich_text","video","image","attachment","embed"],
    "allows_assessment": true,          // inline quiz
    "numbering_style": "numeric", "label_template": "{n}. {title}" }
]
```

Note `Chapter` has `allows_content: true` — it carries an intro paragraph *and*
has children. **The bottom level is not the only level that may hold content.**
The brief said "bottom level of the course content could be descriptive
content" — modelling it as a per-level boolean is strictly more general and no
harder to build. See `08-improvements.md` §1.
