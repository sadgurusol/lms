# Assessments

## 1. Quiz vs Test

Same machinery, different defaults. `assessments.kind` selects the preset; every
setting remains overridable.

| Setting | `quiz` default | `test` default |
|---|---|---|
| `time_limit_s` | `null` | `1800` |
| `max_attempts` | `null` (unlimited) | `1` |
| `pass_percentage` | `null` (formative) | `40` |
| `shuffle_questions` | `false` | `true` |
| `shuffle_options` | `true` | `true` |
| `show_answers` | `after_submit` | `after_pass` |
| `allow_backtrack` | `true` | `true` |
| `question_pool_size` | `null` (all) | `null` |
| `counts_toward_progress` | `false` | `true` |

A quiz is a formative check inside a Topic. A test is a graded assessment at a
Chapter or Part boundary. Both attach to a node whose level has
`allows_assessment = true`.

## 2. Question bank

Questions live in a `question_bank`, never directly on an assessment.
`assessment_questions` is the pivot with a per-assessment `points` override.

Why: the same "Identify the tense" question belongs in a Topic quiz, the
Chapter test, and next year's revision test. Duplicating it means fixing a typo
in three places, and item-analysis statistics fragment across copies.

A bank is either **course-scoped** (`course_id` set) or **global**
(`course_id IS NULL`, cross-course reuse; requires `question.manage` without a
course grant, so effectively admin-curated).

`ON DELETE RESTRICT` on `assessment_questions.question_id` — you cannot delete a
question that any assessment uses. Soft-delete it instead; existing assessments
keep it, new ones cannot pick it.

## 3. Question types

`questions.grading` holds the answer key. `attempt_answers.response` holds the
learner's submission. Both are `jsonb`, both validated against per-type JSON
Schemas in `app/Assessments/schemas/`.

| Type | `grading` | `response` | Auto-graded |
|---|---|---|---|
| `mcq_single` | *(from `question_options.is_correct`)* | `{"option_id": "o2"}` | ✅ |
| `mcq_multi` | `{"scoring": "all_or_nothing" \| "partial"}` | `{"option_ids": ["o1","o3"]}` | ✅ |
| `true_false` | `{"answer": true}` | `{"answer": false}` | ✅ |
| `numeric` | `{"answer": 3.14, "tolerance": 0.01, "unit": "m"}` | `{"answer": 3.14}` | ✅ |
| `fill_blank` | `{"blanks": [{"id":"b1","accept":["ran","run"],"case_sensitive":false}]}` | `{"blanks": {"b1": "ran"}}` | ✅ |
| `match` | *(from `question_options.match_key`)* | `{"pairs": {"o1":"o5","o2":"o4"}}` | ✅ |
| `ordering` | `{"correct_order": ["o3","o1","o2"], "scoring": "exact" \| "kendall_tau"}` | `{"order": ["o3","o1","o2"]}` | ✅ |
| `short_answer` | `{"accept": ["photosynthesis"], "fuzzy": true, "max_distance": 2}` | `{"text": "photosynthesis"}` | ⚠️ best-effort, flag for review |
| `essay` | `{"rubric": [{"criterion":"Structure","max":5}], "min_words": 150}` | `{"text": "..."}` | ❌ manual |

`mcq_multi` partial scoring: `max(0, (correct_selected − incorrect_selected) / total_correct) × points`. Never award negative marks on a single item; clamp at zero.

`ordering` with `kendall_tau`: `points × (concordant_pairs / total_pairs)`.

`short_answer` with `fuzzy`: Levenshtein ≤ `max_distance` after
casefold + trim + whitespace collapse. If it matches, auto-grade correct. If it
does not, **do not auto-grade wrong** — set `is_correct = null` and put the
attempt into `awaiting_review`. A near-miss spelling is a human's call.

## 4. Attempt lifecycle

### 4.1 Starting

```
POST /assessments/{a}/attempts
```

1. Authorize: `attempt.take` + enrolled in the course + the assessment exists in
   the learner's **current publication**.
2. Enforce `max_attempts` against prior non-`expired` attempts.
3. Resolve the question set:
   - `question_pool_size = null` → all `assessment_questions`.
   - otherwise → random sample of size *n*, seeded by `attempt.id` so a retry of
     the same HTTP request is idempotent.
4. If `shuffle_questions`, shuffle. Freeze the result into
   `assessment_attempts.question_order uuid[]`.
5. Set `expires_at = now() + time_limit_s` when a limit exists.
6. Return questions via `QuestionViewerResource` — **no `is_correct`, no
   `grading`, no `explanation`.** Option order shuffled per attempt (seeded by
   attempt id, so the order survives an app restart).

Freezing `question_order` is what makes an attempt resumable and auditable. Do
not recompute the shuffle on each request.

### 4.2 Answering

```
PUT /attempts/{id}/answers/{assessment_question_id}
```
Upsert into `attempt_answers`. Idempotent. Grading fields stay null.

Rejected if: attempt not `in_progress`; `now() > expires_at`; or
`allow_backtrack = false` and the question index is behind the furthest reached
(track `max_index_reached` in the attempt).

**Offline:** the Flutter client queues answers locally and flushes on
reconnect, each `PUT` carrying `client_answered_at`. The server accepts them
inside the window with a small grace period (60 s) and stores
`answered_at = client_answered_at`. Timed tests should refuse offline entry
altogether — say so in the UI rather than silently voiding an attempt.

### 4.3 Submitting

```
POST /attempts/{id}/submit
```

```php
DB::transaction(function () use ($attempt) {
    $attempt = AssessmentAttempt::lockForUpdate()->find($attempt->id);
    abort_if($attempt->state !== 'in_progress', 409);

    $attempt->update(['state' => 'submitted', 'submitted_at' => now()]);

    $needsHuman = false;
    foreach ($attempt->answers as $answer) {
        $result = $this->graders->for($answer->question->type)->grade($answer);
        $answer->update([
            'is_correct'     => $result->isCorrect,   // null ⇒ needs a human
            'points_awarded' => $result->points,
        ]);
        $needsHuman = $needsHuman || $result->isCorrect === null;
    }

    // Unanswered questions score zero — record them explicitly so item
    // analysis can distinguish "skipped" from "never shown".
    $this->recordSkipped($attempt);

    $attempt->update($needsHuman
        ? ['state' => 'awaiting_review']
        : ['state' => 'graded', 'graded_at' => now(), ...$this->tally($attempt)]);
});
```

`tally()` sets `score`, `max_score`, and
`passed = pass_percentage === null ? null : (score / max_score * 100) >= pass_percentage`.

### 4.4 Expiry

A scheduled command every minute:

```php
AssessmentAttempt::where('state', 'in_progress')
    ->where('expires_at', '<', now()->subSeconds(60))   // grace
    ->each(fn ($a) => SubmitAttempt::dispatch($a, reason: 'timeout'));
```
An expired attempt is **submitted and graded**, not discarded. Learners who lose
connectivity mid-test should not lose their work. Mark it
`meta.auto_submitted = true` so a teacher can see why.

### 4.5 Manual grading

`GET /attempts?state=awaiting_review` for graders.
`PATCH /attempts/{id}/answers/{aq}` with `{points_awarded, grader_note}` →
recompute tally; when no `is_correct IS NULL` answers remain, transition to
`graded`.

## 5. Anti-cheat (proportionate, not paranoid)

Ship these:
- Per-attempt question pooling + shuffling (already above).
- `show_answers: after_pass` keeps the key out of circulation.
- Server-authoritative `expires_at`; never trust a client clock.
- Rate-limit answer submission (a bot answering 60 questions in 3 s is visible).
- Log `attempt.state` transitions to `audit_logs`.

Do **not** build webcam proctoring, keystroke biometrics, or focus-loss
detection for a school LMS. They generate false positives, invite legal
exposure, and are trivially defeated. If a customer insists, integrate a
third-party proctoring vendor behind an interface rather than owning the
liability.

## 6. Analytics worth having

A nightly materialised view drives the item-analysis screen:

```sql
CREATE MATERIALIZED VIEW question_stats AS
SELECT aq.question_id,
       count(*)                                         AS attempts,
       avg(aa.points_awarded / NULLIF(aq.points, 0))    AS mean_score,
       stddev_pop(aa.points_awarded)                    AS score_stddev,
       avg(CASE WHEN aa.is_correct THEN 1 ELSE 0 END)   AS facility,   -- p-value
       corr(aa.points_awarded, att.score)               AS discrimination
  FROM attempt_answers aa
  JOIN assessment_questions aq ON aq.id = aa.assessment_question_id
  JOIN assessment_attempts att ON att.id = aa.attempt_id
 WHERE att.state = 'graded'
 GROUP BY aq.question_id;
```

- **Facility** near 1.0 → question is too easy; near 0.0 → broken or miskeyed.
- **Discrimination** below 0.2 → the question does not separate strong from weak
  learners. Below 0 → it is almost certainly miskeyed. Surface these two numbers
  to authors and the question bank improves itself.
