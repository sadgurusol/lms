# PostgreSQL Schema

Target: PostgreSQL 16. Extensions: `pgcrypto` (UUIDs), `ltree` (tree queries),
`pg_trgm` (fuzzy search).

Conventions:
- UUID primary keys (`uuid_generate_v7()` if you add `pg_uuidv7`, else
  `gen_random_uuid()`). v7 is worth it — time-ordered UUIDs keep B-tree inserts
  from fragmenting.
- `created_at`/`updated_at` on everything; `deleted_at` only where soft-delete
  is genuinely needed (courses, nodes, blocks, media).
- All money/score values `numeric`, never `float`.

```sql
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS ltree;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS citext;
```

> Tables below are grouped by concern, not by creation order. `course_grants`
> references `courses`, and `content_blocks` references `media`, so real
> migrations must order them accordingly (or add the FKs in a later migration).

---

## 1. Identity & access

```sql
CREATE TABLE users (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name          text NOT NULL,
    email         citext NOT NULL UNIQUE,
    password      text,                         -- null for SSO-only users
    status        text NOT NULL DEFAULT 'invited'
                  CHECK (status IN ('invited','active','suspended')),
    locale        text NOT NULL DEFAULT 'en',
    last_seen_at  timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

-- spatie/laravel-permission tables: roles, permissions,
-- model_has_roles, model_has_permissions, role_has_permissions.
-- Created by its own migration; not repeated here.

-- Scoped grants: "U authors course C".
CREATE TABLE course_grants (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    course_id  uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    role       text NOT NULL CHECK (role IN ('author','reviewer','owner')),
    granted_by uuid REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, course_id, role)
);
CREATE INDEX ON course_grants (course_id, role);
```

---

## 2. Course schemas

```sql
CREATE TABLE course_schemas (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text NOT NULL,
    slug        text NOT NULL UNIQUE,
    description text,
    created_by  uuid REFERENCES users(id),
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz
);

CREATE TABLE schema_versions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_schema_id uuid NOT NULL REFERENCES course_schemas(id) ON DELETE CASCADE,
    version          int  NOT NULL,
    status           text NOT NULL DEFAULT 'draft'
                     CHECK (status IN ('draft','published','archived')),
    notes            text,
    published_at     timestamptz,
    published_by     uuid REFERENCES users(id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (course_schema_id, version)
);
-- At most one draft version per schema at a time.
CREATE UNIQUE INDEX one_draft_per_schema
    ON schema_versions (course_schema_id) WHERE status = 'draft';

CREATE TABLE schema_levels (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    schema_version_id   uuid NOT NULL REFERENCES schema_versions(id) ON DELETE CASCADE,
    parent_level_id     uuid REFERENCES schema_levels(id) ON DELETE CASCADE,
    name                text NOT NULL,              -- "Lesson"
    plural_name         text NOT NULL,              -- "Lessons"
    depth               int  NOT NULL,              -- derived, 0-based
    sort_key            text NOT NULL,              -- order among sibling levels
    min_occurrences     int  NOT NULL DEFAULT 0 CHECK (min_occurrences >= 0),
    max_occurrences     int  CHECK (max_occurrences IS NULL OR max_occurrences >= min_occurrences),
    allows_content      boolean NOT NULL DEFAULT false,
    allowed_block_types jsonb   NOT NULL DEFAULT '[]'::jsonb,
    allows_assessment   boolean NOT NULL DEFAULT false,
    numbering_style     text NOT NULL DEFAULT 'numeric'
                        CHECK (numbering_style IN ('none','numeric','roman','alpha')),
    label_template      text NOT NULL DEFAULT '{title}',
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (jsonb_typeof(allowed_block_types) = 'array'),
    -- content-bearing levels must permit at least one block type
    CHECK (NOT allows_content OR jsonb_array_length(allowed_block_types) > 0)
);
CREATE INDEX ON schema_levels (schema_version_id);
CREATE INDEX ON schema_levels (parent_level_id);
CREATE UNIQUE INDEX ON schema_levels (schema_version_id, parent_level_id, sort_key)
    NULLS NOT DISTINCT;
```

### Immutability of published versions (I9)

```sql
CREATE OR REPLACE FUNCTION forbid_published_schema_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_status text;
BEGIN
    SELECT status INTO v_status FROM schema_versions
     WHERE id = COALESCE(NEW.schema_version_id, OLD.schema_version_id);
    IF v_status <> 'draft' THEN
        RAISE EXCEPTION 'schema version % is % and cannot be modified',
            COALESCE(NEW.schema_version_id, OLD.schema_version_id), v_status
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN COALESCE(NEW, OLD);
END $$;

CREATE TRIGGER trg_schema_levels_immutable
    BEFORE INSERT OR UPDATE OR DELETE ON schema_levels
    FOR EACH ROW EXECUTE FUNCTION forbid_published_schema_mutation();
```

---

## 3. Courses & content tree

```sql
CREATE TABLE courses (
    id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    title                 text NOT NULL,
    code                  text UNIQUE,             -- "ENG-G10"
    subject               text,
    grade_band            text,                    -- "Grade 10"
    language              text NOT NULL DEFAULT 'en',
    schema_version_id     uuid NOT NULL REFERENCES schema_versions(id),
    workflow_state        text NOT NULL DEFAULT 'draft'
        CHECK (workflow_state IN ('draft','in_review','changes_requested',
                                  'approved','published','archived')),
    latest_publication_id uuid,                    -- FK added after publications
    cover_media_id        uuid,
    created_by            uuid REFERENCES users(id),
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    deleted_at            timestamptz
);
CREATE INDEX ON courses (workflow_state);
CREATE INDEX ON courses (schema_version_id);

CREATE TABLE course_nodes (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_id       uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    parent_id       uuid REFERENCES course_nodes(id) ON DELETE CASCADE,
    schema_level_id uuid NOT NULL REFERENCES schema_levels(id),
    title           text NOT NULL,
    slug            text NOT NULL,
    summary         text,
    sort_key        text NOT NULL,        -- fractional index, see §7
    depth           int  NOT NULL,
    path            ltree NOT NULL,
    search_vector   tsvector GENERATED ALWAYS AS (
                        to_tsvector('english', coalesce(title,'') || ' ' || coalesce(summary,''))
                    ) STORED,
    created_by      uuid REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz
);
CREATE INDEX ON course_nodes USING gist (path);
CREATE INDEX ON course_nodes (course_id, parent_id);
CREATE INDEX ON course_nodes USING gin (search_vector);
CREATE UNIQUE INDEX node_sibling_order_child
    ON course_nodes (parent_id, sort_key) WHERE parent_id IS NOT NULL;
CREATE UNIQUE INDEX node_sibling_order_root
    ON course_nodes (course_id, sort_key) WHERE parent_id IS NULL;
CREATE UNIQUE INDEX ON course_nodes (course_id, parent_id, slug) NULLS NOT DISTINCT;
```

### Structural triggers (I2, I3, I4, I8)

```sql
CREATE OR REPLACE FUNCTION course_nodes_enforce_structure()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    lvl_parent  uuid;
    lvl_version uuid;
    course_ver  uuid;
    parent_lvl  uuid;
    parent_path ltree;
    parent_depth int;
BEGIN
    SELECT parent_level_id, schema_version_id
      INTO lvl_parent, lvl_version
      FROM schema_levels WHERE id = NEW.schema_level_id;

    SELECT schema_version_id INTO course_ver FROM courses WHERE id = NEW.course_id;

    -- I4: level must belong to the course's bound schema version
    IF lvl_version IS DISTINCT FROM course_ver THEN
        RAISE EXCEPTION 'schema_level % does not belong to the course schema version', NEW.schema_level_id;
    END IF;

    IF NEW.parent_id IS NULL THEN
        -- I2
        IF lvl_parent IS NOT NULL THEN
            RAISE EXCEPTION 'level % requires a parent node', NEW.schema_level_id;
        END IF;
        NEW.depth := 0;
        NEW.path  := text2ltree(replace(NEW.id::text, '-', ''));
    ELSE
        SELECT schema_level_id, path, depth
          INTO parent_lvl, parent_path, parent_depth
          FROM course_nodes WHERE id = NEW.parent_id;
        -- I2 + I3
        IF lvl_parent IS NULL THEN
            RAISE EXCEPTION 'level % is a root level and cannot have a parent', NEW.schema_level_id;
        END IF;
        IF lvl_parent <> parent_lvl THEN
            RAISE EXCEPTION 'level % may not nest under a node of level %', NEW.schema_level_id, parent_lvl;
        END IF;
        -- I8
        NEW.depth := parent_depth + 1;
        NEW.path  := parent_path || text2ltree(replace(NEW.id::text, '-', ''));
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER trg_course_nodes_structure
    BEFORE INSERT OR UPDATE OF parent_id, schema_level_id ON course_nodes
    FOR EACH ROW EXECUTE FUNCTION course_nodes_enforce_structure();
```

> Moving a node must rewrite `path`/`depth` for the whole subtree. Do it in one
> statement, in the same transaction as the trigger-driven update of the moved
> node:
> ```sql
> UPDATE course_nodes c
>    SET path  = :new_parent_path || subpath(c.path, nlevel(:old_path) - 1),
>        depth = nlevel(:new_parent_path) + nlevel(c.path) - nlevel(:old_path)
>  WHERE c.path <@ :old_path AND c.id <> :moved_id;
> ```

### Content blocks (I5)

```sql
CREATE TABLE content_blocks (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_node_id uuid NOT NULL REFERENCES course_nodes(id) ON DELETE CASCADE,
    type           text NOT NULL,
    sort_key       text NOT NULL,
    payload        jsonb NOT NULL DEFAULT '{}'::jsonb,
    media_id       uuid REFERENCES media(id) ON DELETE SET NULL,
    created_by     uuid REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE UNIQUE INDEX ON content_blocks (course_node_id, sort_key);
CREATE INDEX ON content_blocks USING gin (payload jsonb_path_ops);

CREATE OR REPLACE FUNCTION content_blocks_enforce_level()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ok boolean;
BEGIN
    SELECT sl.allows_content
           AND sl.allowed_block_types @> to_jsonb(NEW.type)
      INTO ok
      FROM course_nodes cn
      JOIN schema_levels sl ON sl.id = cn.schema_level_id
     WHERE cn.id = NEW.course_node_id;

    IF NOT COALESCE(ok, false) THEN
        RAISE EXCEPTION 'block type % not permitted on this node''s level', NEW.type;
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER trg_content_blocks_level
    BEFORE INSERT OR UPDATE OF type, course_node_id ON content_blocks
    FOR EACH ROW EXECUTE FUNCTION content_blocks_enforce_level();
```

---

## 4. Media

```sql
CREATE TABLE media (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    disk              text NOT NULL DEFAULT 's3',
    path              text NOT NULL,
    original_filename text,
    mime              text NOT NULL,
    size_bytes        bigint,
    checksum_sha256   text,
    kind              text NOT NULL CHECK (kind IN ('image','video','document','audio')),
    provider          text,                 -- 'mux' | 'cloudflare' | null
    provider_asset_id text,
    playback_id       text,
    duration_s        int,
    status            text NOT NULL DEFAULT 'uploading'
                      CHECK (status IN ('uploading','processing','ready','failed')),
    meta              jsonb NOT NULL DEFAULT '{}'::jsonb,
    uploaded_by       uuid REFERENCES users(id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    deleted_at        timestamptz
);
CREATE INDEX ON media (checksum_sha256);   -- dedupe identical uploads
```

---

## 5. Publications

```sql
CREATE TABLE course_publications (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_id         uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    number            int  NOT NULL,
    schema_version_id uuid NOT NULL REFERENCES schema_versions(id),
    snapshot          jsonb NOT NULL,      -- full rendered tree, see 04-§5
    snapshot_etag     text NOT NULL,       -- sha256 of snapshot, for client sync
    media_manifest    jsonb NOT NULL DEFAULT '[]'::jsonb,
    changelog         text,
    published_by      uuid REFERENCES users(id),
    published_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (course_id, number)
);

ALTER TABLE courses
    ADD CONSTRAINT courses_latest_publication_fk
    FOREIGN KEY (latest_publication_id) REFERENCES course_publications(id);

CREATE OR REPLACE FUNCTION forbid_publication_mutation()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'course publications are immutable';
END $$;

CREATE TRIGGER trg_publications_immutable
    BEFORE UPDATE OR DELETE ON course_publications
    FOR EACH ROW EXECUTE FUNCTION forbid_publication_mutation();
```

> `snapshot` for a very large course can exceed comfortable JSONB sizes. If a
> course snapshot passes ~2 MB, switch to storing the snapshot as a gzipped
> object in S3 and keep only `snapshot_uri` + `snapshot_etag` in the row. Design
> the API to hide which one you did.

---

## 6. Review

```sql
CREATE TABLE review_requests (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_id      uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    submitted_by   uuid NOT NULL REFERENCES users(id),
    assigned_to    uuid REFERENCES users(id),
    state          text NOT NULL DEFAULT 'open'
                   CHECK (state IN ('open','approved','changes_requested','withdrawn')),
    note           text,
    due_at         timestamptz,
    decided_at     timestamptz,
    decided_by     uuid REFERENCES users(id),
    decision_note  text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX one_open_review_per_course
    ON review_requests (course_id) WHERE state = 'open';

CREATE TABLE review_comments (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    review_request_id uuid NOT NULL REFERENCES review_requests(id) ON DELETE CASCADE,
    parent_comment_id uuid REFERENCES review_comments(id) ON DELETE CASCADE,
    author_id         uuid NOT NULL REFERENCES users(id),
    body              text NOT NULL,
    anchor_type       text NOT NULL CHECK (anchor_type IN ('course','node','block')),
    anchor_id         uuid,
    resolved_at       timestamptz,
    resolved_by       uuid REFERENCES users(id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON review_comments (review_request_id, anchor_type, anchor_id);
```

---

## 7. Assessments

```sql
CREATE TABLE question_banks (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text NOT NULL,
    course_id   uuid REFERENCES courses(id) ON DELETE CASCADE,  -- null = global bank
    created_by  uuid REFERENCES users(id),
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE questions (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    question_bank_id uuid NOT NULL REFERENCES question_banks(id) ON DELETE CASCADE,
    type             text NOT NULL CHECK (type IN (
                        'mcq_single','mcq_multi','true_false','numeric',
                        'short_answer','essay','match','ordering','fill_blank')),
    stem             jsonb NOT NULL,        -- rich text
    explanation      jsonb,                 -- shown after grading
    default_points   numeric(6,2) NOT NULL DEFAULT 1,
    difficulty       text CHECK (difficulty IN ('easy','medium','hard')),
    tags             text[] NOT NULL DEFAULT '{}',
    grading          jsonb NOT NULL DEFAULT '{}'::jsonb,  -- type-specific answer key
    media_id         uuid REFERENCES media(id),
    created_by       uuid REFERENCES users(id),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz
);
CREATE INDEX ON questions USING gin (tags);
CREATE INDEX ON questions (question_bank_id, type);

CREATE TABLE question_options (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    body        jsonb NOT NULL,
    is_correct  boolean NOT NULL DEFAULT false,
    feedback    text,
    sort_key    text NOT NULL,
    match_key   text,                       -- for 'match' type pairing
    UNIQUE (question_id, sort_key)
);

CREATE TABLE assessments (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    course_id      uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    course_node_id uuid REFERENCES course_nodes(id) ON DELETE CASCADE,
    kind           text NOT NULL CHECK (kind IN ('quiz','test')),
    title          text NOT NULL,
    instructions   jsonb,
    settings       jsonb NOT NULL DEFAULT '{}'::jsonb,
    -- settings: { time_limit_s, max_attempts, pass_percentage, shuffle_questions,
    --             shuffle_options, show_answers: never|after_submit|after_pass,
    --             allow_backtrack, question_pool_size }
    total_points   numeric(8,2) NOT NULL DEFAULT 0,   -- denormalised
    created_by     uuid REFERENCES users(id),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    deleted_at     timestamptz
);
CREATE INDEX ON assessments (course_id);
CREATE INDEX ON assessments (course_node_id);

CREATE TABLE assessment_questions (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    assessment_id uuid NOT NULL REFERENCES assessments(id) ON DELETE CASCADE,
    question_id   uuid NOT NULL REFERENCES questions(id) ON DELETE RESTRICT,
    points        numeric(6,2) NOT NULL,
    sort_key      text NOT NULL,
    UNIQUE (assessment_id, question_id),
    UNIQUE (assessment_id, sort_key)
);

CREATE TABLE assessment_attempts (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    assessment_id   uuid NOT NULL REFERENCES assessments(id) ON DELETE CASCADE,
    publication_id  uuid NOT NULL REFERENCES course_publications(id),
    user_id         uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    attempt_number  int  NOT NULL,
    state           text NOT NULL DEFAULT 'in_progress'
                    CHECK (state IN ('in_progress','submitted','awaiting_review','graded','expired')),
    question_order  uuid[] NOT NULL,        -- frozen per-attempt shuffle/pool
    started_at      timestamptz NOT NULL DEFAULT now(),
    expires_at      timestamptz,
    submitted_at    timestamptz,
    graded_at       timestamptz,
    score           numeric(8,2),
    max_score       numeric(8,2),
    passed          boolean,
    UNIQUE (assessment_id, user_id, attempt_number)
);
CREATE INDEX ON assessment_attempts (user_id, assessment_id);
CREATE INDEX ON assessment_attempts (state) WHERE state = 'in_progress';

CREATE TABLE attempt_answers (
    id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    attempt_id             uuid NOT NULL REFERENCES assessment_attempts(id) ON DELETE CASCADE,
    assessment_question_id uuid NOT NULL REFERENCES assessment_questions(id) ON DELETE CASCADE,
    response               jsonb NOT NULL,   -- shape depends on question type
    is_correct             boolean,
    points_awarded         numeric(6,2),
    grader_id              uuid REFERENCES users(id),   -- manual grading
    grader_note            text,
    answered_at            timestamptz NOT NULL DEFAULT now(),
    UNIQUE (attempt_id, assessment_question_id)
);
```

---

## 8. Delivery & progress

> There is no `enrollments` table. Read access comes from `EntitlementResolver`
> (doc `11`) — a client contract, a subscription, a purchase, or a comp grant.
> An enrollment row would be a fourth source of truth that drifts from the other
> three.
>
> Tables for clients, launches, products, entitlements, subscriptions, and
> activity events live in docs `10`, `11`, and `12`. They all sit *below* the
> authoring line and none of them touches the content tree.

```sql
CREATE TABLE node_progress (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    publication_id uuid NOT NULL REFERENCES course_publications(id) ON DELETE CASCADE,
    course_node_id uuid NOT NULL,      -- intentionally not FK: nodes may be deleted
    state          text NOT NULL DEFAULT 'not_started'
                   CHECK (state IN ('not_started','in_progress','completed')),
    seconds_spent  int  NOT NULL DEFAULT 0,
    last_position  int,                -- video resume point, seconds
    completed_at   timestamptz,
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (user_id, publication_id, course_node_id)
);
CREATE INDEX ON node_progress (user_id, publication_id);
```

---

## 9. Audit

```sql
CREATE TABLE audit_logs (
    id           bigserial PRIMARY KEY,
    actor_id     uuid REFERENCES users(id),
    action       text NOT NULL,          -- 'course.published'
    subject_type text NOT NULL,
    subject_id   uuid,
    before       jsonb,
    after        jsonb,
    ip           inet,
    user_agent   text,
    created_at   timestamptz NOT NULL DEFAULT now()
) PARTITION BY RANGE (created_at);
-- monthly partitions, created by a scheduled command
CREATE INDEX ON audit_logs (subject_type, subject_id, created_at DESC);
```

---

## 10. Ordering: fractional indexing

`sort_key` is a lexicographically-sortable string (LexoRank / "fractional
index"). Inserting between `"a0"` and `"a1"` yields `"a0V"` — one row updated,
not N. Renumbering a 200-lesson unit on every drag is the thing you are
avoiding.

```php
// app/Support/FractionalIndex.php
FractionalIndex::between(?string $prev, ?string $next): string
```
Use the algorithm from `rocicorp/fractional-indexing` (base-62, midpoint of the
two keys, appending a digit when the gap closes). Add a periodic rebalance job
that rewrites keys once any single key exceeds ~30 chars — in practice, never.

---

## 11. Cascade & retention notes

- `courses.deleted_at` soft-deletes; a nightly job hard-deletes courses soft-deleted > 90 days, cascading to nodes/blocks/assessments.
- **Never** hard-delete a `course_publication` that has attempts or progress against it. Archive the course instead.
- `node_progress.course_node_id` has no FK on purpose: progress must survive an author deleting a node from the draft tree.
