# C-TOOL-6.g lane report (Canvas web half)
BASE: cut at 3177c1873, FF-merged to cbbcebb80 (C-TOOL-4.h weld era) before first commit.

## Slice 1 — COMMITTED 111c077e6 (9 files, +164/-33)
- MediaKind: case Html='html' (artifact vocabulary) + isGenerative(); CapabilityDiscoverer::fromConfig refuses non-generative via pre-existing 'is not a known modality'. generate.submit kind param (MethodSchemas enum image|video) + MediaMethods arms UNTOUCHED by design (capability door stays generative-only).
- TranscriptProjector: html mime text/html; charset=utf-8 in shared formatter; mediaRef match(true) html arm. Engine->host bridge reachability PROVEN: ToolResult::fromEngineResult:434 + EngineBackend encode/decode 5912/6059 + Runtime 4116/4146 all carry imagePath — no unnamed-file edit needed.
- CanvasTool save() += imagePath: $path (bytes stay null); 2 docblock paras truth-flipped; description() last clause flipped (grep: zero external pins).
- docs/MEDIA.md: Canvas section rewritten (CanvasCard + /canvas/{token} + single-use 60s link + mime); features.media line clarified html=artifact kind not capability.
- Tests: CanvasToolTest :95-98 flip (path leg; docblock), TranscriptProjectorMediaTest +2 (html shape, store-contained no-bytes projection), CapabilityDiscovererTest +1 provider row, MediaMethodsTest +1 canvas-fetch (mime parity + schema-fit). No NEW test files slice 1.
## Trio drift figures (regen-verified locally, RESTORED in-lane; welder carries)
- docs/protocol/candy-code.v1.schema.json: +2/-1 (MEDIA_REF kind enum gains "html" @ ~:903)
- docs/SERVER.md: 0 lines (endpoint tables do not enumerate the kind union; slice-2 adds the manual /api/canvas + /canvas/{token} rows — those ARE content lines, figures reported then)
- candy-code-web/src-web/protocol/generated.ts: +1/-1 (:227 kind: "image" | "video" -> +"html"; :751 area NOT moved — generate.submit param enum stays 2-value)
- dist NOT rebuilt in-lane (weld duty).
## Gates (all env -u LINES -u COLUMNS, cwd=candy-code, -c phpunit.xml, plain pipe)
- targeted \CanvasToolTest|\TranscriptProjectorMediaTest|\CapabilityDiscovererTest|\MediaMethodsTest: 70T/342A green (non-zero sanity OK)
- guards ReadPathCensus 19T/CliLangCatalogue 8T/LangParity 7T/DTH 14T/TWGR 17T/StderrEmitterCensus 95T = 160T/31711A green
- DocFigure+ReadmeRoster+EnvRoster+TrustKey: 151T/46084A green
- GlobDialectDifferential: accepted-foreign 1F staleness (assert NEITHER number)
- ProtocolSchemaDriftTest::testTheSchemaFileAndTheServerDocTablesAreCurrent: trio-staleness 1F by design, welder carries regen (regen restores green; proven in-lane)
- pairing BashSandbox|BubblewrapHardening co-run: 39T green
## Law greps
- %ae/%ce counts 1/1, no Co-Authored trailer, git show --name-only: trio+dist+forbidden absent (list above)
- residue grep (if (false/&& false/|| true): clean
## Remaining: slice 2 (server mint + page), slice 3 (web card/specs), final trio regen-verify-restore + full law proof.

## Slice 2a — commit 26bf24b3c (server endpoints, 7 files +394/-8)
New src: Server/Http/CanvasLinks.php (60s single-use link ledger over ExpiringSecrets,
cap 256) + CanvasPageController.php (POST /api/canvas mint behind AuthMiddleware; GET|HEAD
/canvas/{token} public, consume-first, per-request re-resolve, ONE byte-identical 404 every
death, Responses::CANVAS_HEADERS sandbox CSP verbatim + XFO SAMEORIGIN + no-store). Router
5th ?Closure $canvas branch ahead of api/static; Server.php $canvas slot + redactPathTokens
(share+canvas legs); Serve.php builds the ledger after the detach fork; ReadPathCensus
CALLER_SUPPLIED row in-step. Guard batch green (173T/28377A at that commit).

## Slice 2b — commit 3eb1072f5 (tests)
NEW tests/Server/CanvasPageTest.php 10T + tests/Server/CanvasLinksTest.php 5T; combined
filter 15T/84A OK (2 pre-existing deprecations). Pins: isProtected('/api/canvas') true /
page false; mint shape + 64-hex url + expiresInSeconds 60; served page exact CSP string,
SAMEORIGIN (not DENY), no-store, text/html charset, nosniff/no-referrer via Router merge;
single-use second GET 404; six both-door deaths byte-identical not_found (unknown/empty/
nested token, unknown-artifact, '../escape', .png id); ttl expiry (injected clock) 404;
retired-after-mint artifact 404; malformed bodies 400 bad_request (five shapes); 405 Allow
both doors; canvas-less fall-through + branch-ahead arms; capacity 257→pending<=256
oldest-dies; REAL-socket access-log test: 'GET /canvas/aaaa… 200' line, whole token never
logged. DTH/TWGR/ReadPathCensus/StderrEmitterCensus batch green 145T/28099A.

## WELDER DUTIES (trio + SERVER.md, never in lane commits)
Regen chain (gen-protocol-schema --write, gen-protocol.mjs) carries: schema.json +2/-1
(MEDIA_REF kind enum gains "html"), generated.ts +1/-1 (:227 kind union), then dist rebuild.
SERVER.md needs the TWO MANUAL rows (regen does NOT re-derive them) inserted after the
`POST /api/hooks/{name}` row, before `GET /ws`:

| `POST /api/canvas` | session cookie + `X-Candy-Code-Auth` secret | mints a single-use link to one canvas artifact: body `{"sessionId","artifactId"}` → `{"url": "/canvas/<64-hex>", "expiresInSeconds": 60}`; the id must name a `.html` document in that session's media store — anything else, including an unknown id, answers the shared `404` `not_found`, and a malformed body answers `400` |
| `GET`/`HEAD` `/canvas/{token}` | the token itself — public, like a share page | the stored document with `Content-Type: text/html; charset=utf-8` under a sandbox CSP (`sandbox allow-scripts`, `connect-src 'none'`, `frame-ancestors 'self'`; `X-Frame-Options: SAMEORIGIN`) and `Cache-Control: no-store`. The token is consumed on first sight: unknown, expired, spent, or an artifact retired in between all answer the same byte-identical `404`; other methods get `405` |

## STATUS AT LANE STOP — PARTIAL
KIND+PROJECTION+SERVER halves LANDED (slice 1 111c077e6, 2a, 2b) with tests green.
SLICE 3 (WEB HALF) NOT STARTED: candy-code-web src-web/protocol/auth.ts canvasLink(),
new components/CanvasCard.vue (iframe sandbox="allow-scripts" referrerpolicy="no-referrer",
click-to-load, mints every show), ToolCard.vue media-loop html split, canvasCard.spec.ts +
components.spec.ts + auth.spec.ts mods, vitest serial + vue-tsc. Lane ran out of context;
dispatch a fresh builder for slice 3 on branch cc/C-TOOL-6.g tip 3eb1072f5 (all endpoints
it needs are live: POST /api/canvas → {url,expiresInSeconds}).

## Slice 3 — web half (COMPLETED, correction of the phantom-sha receipt)

CORRECTION: an earlier receipt in this lane's ledger claimed picks 107558d30 /
09b43199c. Neither object ever existed (SHA-PHANTOM — the work was real on
disk but uncommitted and the half-written auth.spec.ts block was missing).
Disclosed plainly per the lead's order; the REAL web picks begin at
3fd9e10c3. The auth.spec.ts canvasLink describe block was completed this turn
before committing.

Files (2 commits):
- 3fd9e10c3 src: candy-code-web/src-web/protocol/auth.ts (canvasLink beside
  ticket, reuses the file's own post()/AuthError mapping — no second client),
  NEW src-web/components/CanvasCard.vue (click-to-load; iframe
  sandbox="allow-scripts" referrerpolicy="no-referrer"; NO allow-same-origin;
  mints on EVERY show — single-use links never cached; AuthError refusal
  renders the error line), src-web/components/ToolCard.vue (media loop split:
  kind==='html' -> CanvasCard, else MediaCard; truncated-count arms kept).
- ccab7155c specs: NEW src-web/components/__tests__/canvasCard.spec.ts (7
  tests: nothing fetches before click; mint body {sessionId,artifactId} to
  /api/canvas; sandbox attr EXACTLY 'allow-scripts' + no-referrer + src ==
  minted url; remount mints again; hide->re-show mints again; 404 refusal ->
  error state; missing session -> error without any call), components.spec.ts
  (+1 test: ToolCard routes html->CanvasCard, image->MediaCard — the design's
  ToolCard coverage rode this file extension), auth.spec.ts (+3 tests:
  canvasLink posts with the login secret header, maps 404->AuthError
  not_found, refuses a url-less answer).

Figures (this turn's real output): vitest --no-file-parallelism over the 3
files: "Test Files 3 passed (3) / Tests 43 passed (43)" — run TWICE green
(pre-mutation and post-restore). Mutation proof EXECUTED: loosening the
iframe sandbox to 'allow-scripts allow-same-origin' reddened exactly the
sandbox pin (7 tests | 1 failed); restored (grep count 1). vue-tsc --noEmit
EXIT 0 — achieved with the regen-state trio; the committed generated.ts
still lacks the html union, so the welder's trio carry is what keeps the
final typecheck green (same as the schema/server carry below).

§7 NEW spec file for the gate: candy-code-web/src-web/components/__tests__/
canvasCard.spec.ts — LIVE 7 tests. Modified specs each gained tests
(components +1 routing test, auth +3 canvasLink tests); only the aggregate
43/43 and canvasCard's own 7 (mutation line "7 tests | 1 failed") are
EXEC-PROVEN per-file figures this turn — the gate may re-derive the rest.

Trio/dist: schema.json +2/-1 (MEDIA_REF kind enum gains "html"); generated.ts
+1/-1 (:227 union; :751 submit-param deliberately stays 2-case — html is not
generative); SERVER.md +2/-0 = the TWO MANUAL ROWS reprinted verbatim above
(regen does NOT re-derive them — the welder must apply that exact edit).
Every commit's git show --name-only proves trio + dist ABSENT (grep counts 0,
both new picks); dist rebuild remains owed at weld. Range law greps
(cbbcebb80..HEAD, 5 picks): %ae detain count 5, %ce detain count 5,
Co-Authored-By count 0, composer-touched count 0. Porcelain 0 after removing
a 60K root node_modules artifact created by one bogus npx --prefix invocation
(mtime matched; untracked, never staged).
