# Flutter Client

One codebase, three shells: **Authoring** (web/desktop-first), **Review**
(web), **Learner** (mobile-first). Ship them as flavors of one app, or as
`lms_author` + `lms_learn` binaries sharing a `packages/lms_core`. Prefer the
latter — the learner APK should not carry a rich-text editor.

## 1. Package layout

```
apps/
  lms_learn/           # mobile-first learner app
  lms_studio/          # authoring + review (web, desktop)
packages/
  lms_core/            # models, API client, error types
  lms_content/         # snapshot parser + block renderers (shared)
  lms_offline/         # drift DB, sync engine, media cache
  lms_ui/              # design system
```

## 2. State & data

| Concern | Choice |
|---|---|
| State management | Riverpod 2 (`AsyncNotifier`) |
| Routing | `go_router`, deep links `lms://course/{id}/node/{id}` |
| HTTP | `dio` + interceptors (auth refresh, `X-Request-Id`, retry with jitter) |
| Serialization | `freezed` + `json_serializable` |
| Local DB | `drift` (SQLite) |
| Secrets | `flutter_secure_storage` |
| Media | `video_player` + `better_player` for HLS; `cached_network_image` |

Do not hand-roll the token refresh. One `dio` interceptor, a single-flight
mutex around refresh, queued retries. Two concurrent 401s must produce one
refresh call.

## 3. Rendering the snapshot

The snapshot (`04-§5.1`) is a tree of nodes, each with typed blocks. The
renderer is a registry, not a switch statement:

```dart
typedef BlockBuilder = Widget Function(BuildContext, ContentBlock);

final blockRegistry = <String, BlockBuilder>{
  'rich_text':  (c, b) => RichTextBlock(doc: PortableText.fromJson(b.payload['body'])),
  'video':      (c, b) => VideoBlock(playbackId: b.payload['playback_id']),
  'image':      (c, b) => ImageBlock(mediaId: b.payload['media_id'], alt: b.payload['alt']),
  'attachment': (c, b) => AttachmentBlock(...),
  'embed':      (c, b) => EmbedBlock(...),
  'callout':    (c, b) => CalloutBlock(...),
  'assessment': (c, b) => AssessmentLauncher(id: b.payload['assessment_id']),
};

Widget buildBlock(BuildContext c, ContentBlock b) =>
    (blockRegistry[b.type] ?? _unknownBlock)(c, b);
```

`_unknownBlock` renders a neutral "This content requires an app update" card
instead of throwing. A backend that ships a new block type must not brick
installed clients. This costs four lines and saves a forced-upgrade incident.

Navigation is generated from `snapshot.schema.levels`: level depth 0 → tabs or
a top-level list, deepest content-bearing level → the reader page. Labels come
from `node.label`, already computed server-side. **The client never derives
"Chapter 3".**

## 4. Rich text: not HTML

Store rich text as structured JSON (Portable Text or ProseMirror doc), never as
an HTML string.

- HTML in Flutter means `flutter_html`, which is a lossy, slow, unmaintained
  approximation of a browser.
- HTML from authors means an XSS sanitisation problem on every surface.
- Structured JSON gives you: identical rendering on mobile/web, native Flutter
  widgets, cheap diffing for the changelog, and machine-readable content for
  search indexing and accessibility.

Marks to support: `strong`, `em`, `underline`, `code`, `link`, `sub`, `sup`.
Blocks: paragraph, h2–h4, bullet/ordered list, blockquote, code block, table,
math (`katex` string; render with `flutter_math_fork` — Grade 12 Economics will
have formulae).

## 5. Offline

The killer feature for the likely deployment context. Design for it now, not
in v3.

### 5.1 Model

```dart
// packages/lms_offline
class CoursePack {
  final String courseId;
  final int publicationNumber;
  final String etag;
  final DownloadState state;   // none | metadata | partial | complete
  final int bytesTotal, bytesDownloaded;
}
```

1. `GET /me/courses/{c}/content` → store the snapshot JSON in `drift`, keyed by
   `(course_id, publication_number)`.
2. Walk `media_manifest`. Queue downloads through `flutter_downloader`
   (background, resumable). Store under
   `<app_support>/packs/{course}/{publication}/{media_id}`.
3. Mark `complete` when every manifest entry is present and checksum-verified.
4. On launch, `HEAD /me/courses/{c}/content` with `If-None-Match: <etag>`.
   `304` → nothing to do. `200` → a new publication exists; prompt "Update
   available (12 MB)". **Never auto-swap content mid-lesson.**

Keep the previous pack until the new one verifies. Then delete. A half-downloaded
update must never replace a working course.

### 5.2 Video offline

HLS segments cannot simply be `File`-cached. Either:
- use the provider's offline SDK (Mux/Cloudflare both expose downloadable
  renditions), or
- store a single progressive MP4 rendition per video specifically for offline,
  and stream HLS when online.

The second is simpler and costs one extra encode. Take it.

### 5.3 Write-side sync

Progress and attempt answers are writes that must survive being offline.

```
outbox(id, kind, payload, created_at, attempts, last_error)
```
A single `SyncEngine` drains the outbox on connectivity, with exponential
backoff. Every mutation carries a client-generated UUID → the server's
`Idempotency-Key`. Replaying the outbox after a crash must be safe.

`node_progress` conflicts resolve **last-write-wins on `seconds_spent` max**,
not on timestamp: two devices watching the same video should sum to the larger
value, not the newer one.

Timed tests: refuse to start offline. Say why.

## 6. Authoring shell notes

- Tree editor: `flutter_fancy_tree_view` or a custom `SliverList` with
  reorderable drag targets. Drag validity is derived from
  `allowed-children` — a Topic cannot be dropped onto a Part, and the UI must
  show that during the drag, not after the failed request.
- Every mutation is optimistic with rollback on `409`, and carries `If-Match`.
- Autosave rich text on a 2 s debounce; show an explicit "Saved" indicator.
  Authors do not trust silent saves.
- The readiness panel polls `GET /courses/{c}/validate` on a 30 s interval and
  after every structural change.

## 7. Accessibility

- Every `ImageBlock` requires `alt`; the authoring UI blocks save without it
  (the backend only warns — the client should be stricter).
- Captions on video: `W_NO_CAPTIONS` at publish; a `VTT` track in the player.
- `Semantics` widgets on every custom block. Test with TalkBack and VoiceOver.
- Minimum 4.5:1 contrast; respect `MediaQuery.textScalerOf(context)` — do not
  clamp text scaling below 1.3×.

## 8. Testing

| Layer | Tool |
|---|---|
| Models / grading parity | `test` — port the grading rules and assert they match backend fixtures |
| Renderer | `golden_toolkit` — one golden per block type, light + dark |
| Sync engine | fake clock + fake `Connectivity`, property tests on outbox replay |
| E2E | `patrol` against a seeded staging API |

Golden-test the block renderers. A content platform's regressions are visual,
and unit tests will not catch them.
