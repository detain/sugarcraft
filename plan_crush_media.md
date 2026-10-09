# plan_crush_media — Image & Video Generation for sugar-crush

**Created:** 2026-10-08 · **Status:** executable master plan · **Sole deliverable of:** plan-authoring task; touches no code.
**Mission:** ship first-class text-to-image / image-to-image / inpaint / image-to-video / text-to-video generation in sugar-crush (console TUI first; server/web phase-2 shaped from day one): an A1111-dialect media client, `GenerateImage`/`GenerateVideo` tools, a full-band popup form exposing the ~90-control A1111 tunable surface, best-available terminal display with a user-switchable render mode, in-terminal video playback, and WebSocket/`sugar-crush-web` parity.

**Source docs (authoritative — read the cited sections before executing any step):**
- canonical consolidated record (1,327 ln), embedded verbatim in **Appendix F** of this file, cited below as `crush_media §N`; its five input reports (Appendix D) carry the file:line evidence and were verified against the live tree 2026-10-07.
- sibling implementation-brief report (415 ln), embedded verbatim in **Appendix G** of this file, cited as `mystage §N`. Its 8 unique facts are folded into steps below and indexed here: **(a)** heartbeat/cancellation mandate → W1.5, W2.1, W5.2; **(b)** `inputSchema` empty-object `new \stdClass()` → W2.1, W5.3; **(c)** never reuse WebFetch's SSRF dialing → W1.3; **(d)** tool positions 33/34 + re-derive micro-step → W0.2, W2.1, W5.3; **(e)** proven-negatives (TCD/regional-seeds/per-axis-weights/vary-region/diffuse-noise/Prompt-S-R-standalone are Forge/SD.Next, NOT stock A1111) → W3.4 note + W7.1; **(f)** base64-MP4 has no in-tree ingestion → temp-file-first → W5.2; **(g)** mode-ladder asymmetry + force-cell-renderers-for-grids law → W2.2, W2.4, W5.7; **(h)** legacy-endpoint path map → W1.4. Also folded: mystage §8.1 new-code map (file → pattern-source, distributed across waves) and the `ui-config.json` display-override sidecar analog + filename-token save naming (mystage §3.4, crush_media Q-9/§5.5) → W3.9, W1.9, W4.5.
- Conflict ledger C-1…C-4 (crush_media §0.3) is ADJUDICATED — treat as law: `inpainting_fill` = exactly 4 values 0–3; no Ctrl+Enter binding exists in A1111's own JS (define our chords deliberately); ENSD default 0; `uni_pc_order` slider 1–50.
- The standalone crush_media.md files were merged verbatim into Appendices F/G and deleted; '/tmp/opencode/crush-media/*' report paths were relocated to `.crush-media-reports/` at the repo root — resolve all citations within this plan file + that directory.

## Execution laws (binding on every step, every lane)

### Commit law
- ALL commits go **direct to `master`** at `/home/sites/sugarcraft` — **no branches, no PRs, NEVER push**.
- Author AND committer `Joe Huss <detain@interserver.net>`, assembled from split shell vars so no literal email appears in command text:
  `A='detain'; B='interserver.net'; MAIL="${A}@${B}"; GIT_AUTHOR_NAME='Joe Huss' GIT_AUTHOR_EMAIL="$MAIL" GIT_COMMITTER_NAME='Joe Huss' GIT_COMMITTER_EMAIL="$MAIL" git commit --author="Joe Huss <$MAIL>" -m "media: <step id> — <summary>"`
- After EVERY commit verify the two fields SEPARATELY: `git log -1 --format='%ae' | grep -c detain` → 1 AND `git log -1 --format='%ce' | grep -c detain` → 1 (a joined `%ae|%ce` grep is ambiguous). No `Co-Authored-By` trailers.
- Shared tree: commit only your paths — `git commit -o -- <own paths>`; untracked files need `git add -N` first. Foreign working-tree dirt exists (e.g. untracked `.sugar-crush/`); NEVER `git checkout`/`stash`/`clean` anything you do not own.

### Test law
- Every step ships/updates **its own targeted tests** and runs **only that step's filter** (`cd sugar-crush && vendor/bin/phpunit --filter '<SuitePattern>'`; sibling-lib steps run their lib's own filter from the lib dir).
- The **FULL sugar-crush suite runs exactly ONCE at the END of each wave, by a SINGLE gate agent** — synchronous plain bash-tool pipe (NEVER tmux/setsid — `sugar-crush/tests/bootstrap.php` stdin-repair requires a non-tty, and a pty ctty false-reddens `TerminalSizeFallbackIsolationTest`), `cwd=sugar-crush`, `env -u LINES -u COLUMNS`, linked mode verified FIRST via `php scripts/refresh-deps.php --status` (a bare `composer update` swaps symlinked siblings for Packagist copies and voids every figure).
- `sugar-crush/tests/Config/Support/suite-figure.json`, the README suite headline, and `scripts/parallel-tests-durations.tsv` are re-pinned **ONLY in the wave-gate commit** by the gate agent. Mid-wave the `ReadmeSuiteFigureDriftTest` staleness pair is the EXPECTED-only red; each lane reports its +T/+A delta to the gate.
- Every NEW test file lands its `scripts/parallel-tests-durations.tsv` row in its own step commit (unlisted files run in ZERO shards).
- Sibling-lib suites (candy-forms, candy-mosaic, sugar-reel, candy-core) gate their own wave steps the same targeted way from the lib dir, linked mode.
- Assertion modes follow AGENTS.md tea-snapshot taxonomy: snapshot-byte / cell-grid / behaviour / coercion / golden. **candy-vt CANNOT verify graphics protocols** (its DA1 omits `;4`; DCS is a no-op) — assert sixel/kitty/iTerm2 output as raw bytes or fake detection via candy-mosaic `Detect::setProbeStdin()` (`candy-mosaic/src/Detect.php:105`, reset seam `:82`).

### Review law
- After each wave: spawn a **reviewer agent** on the wave's commit range → findings → **fixer agent** cures → a **NEW reviewer** on the fixed diff → repeat until **zero CRITICAL / zero MAJOR**. Minors may land as noted follow-ups recorded in the wave closeout.
- Reviewers verify beyond-diff per each step's **Review brief**; reviewer agents must not leave tree dirt; builds AND reviews run as `task` subagent_type=`coder` (project directive).

### Concurrency law
- Lanes within a wave run concurrently **only where "Files likely touched" sets are DISJOINT**. **Serialization targets** — touchable by at most ONE lane per wave (owner named per step/wave; the wave summary table repeats the bottleneck):
  1. `sugar-crush/README.md`
  2. `sugar-crush/docs/*` rosters (ARCHITECTURE.md, SETTINGS.md, ENVIRONMENT.md, COMMANDS.md, KEYS doc)
  3. `sugar-crush/src/Cli/Bootstrap.php`
  4. `sugar-crush/src/Chat.php`
  5. `sugar-crush/src/Renderer.php` — the TRANSCRIPT renderer (renderToolResults :4632 / renderToolImage :4923 region)
  6. `sugar-crush/src/Tui/Renderer.php` — the FRAME/overlay renderer (renderSettingsEditor :847 / render-branch :566 region). BOTH files exist; each is its own target; a step must name which.
  7. `sugar-crush/lang/en.php`
  8. `sugar-crush/tests/Config/Support/suite-figure.json`
- `tools/gen-tool-docs.php` / `gen-command-docs.php` / `gen-settings-doc.php` **regeneration runs exactly ONCE per wave at the gate, by the gate agent** (they rewrite README + docs + counts in bulk — mid-wave `--write` runs would collide with every lane). Lanes still hand-write their OWN roster rows in-step where a drift test demands it; if the row lives in a serialization target, the wave's docs owner lands the combined edit.

### Agent-failure law
- Blank/no-response agent = dead: resume the **SAME task_id** with the literal word `continue`, **≥10 consecutive blank-resumes** before any other action (no fresh re-cut, no reworded prompt, no work-elsewhere first).
- An agent returning CONTENT — even repeated/complaining — is ALIVE and DONE: treat the report as output, verify claims against disk (`git log`, `git cat-file` every claimed SHA, file existence, grep each cited marker), then instruct or close.
- Gate every claimed "DONE" on the LAND check (branch/ref + `cat-file` + artifacts dir + tree diff) BEFORE queueing review (fabricated-SHA death-mode).

## Wave summary table

| Wave | Goal | Parallel lanes | Serial bottleneck file(s) |
|---|---|---|---|
| 0 | Ground truth & contract lock | o1–o4 read-only probes, concurrent | none (no repo writes) |
| 1 | Media core `src/Media/`, no UI | a=DTOs+Job · b=SdClient · c=Infotext · d=PNG · e=config authority · f=MediaStore | docs/* + LayeredSettings (e only) |
| 2 | Console t2i end-to-end (pre-form) | a=GenerateImage · b=render-mode wiring · c=/generate cmd · d=live preview | Chat.php (c), src/Renderer.php (d), Bootstrap.php (b), README+docs (e) |
| 3 | The popup form | u=candy-forms widgets (own lib) · a=MediaForm shell · b=sections · c=scripts+advanced · d=presets/ui-config · f=keys+lang+palette | Chat.php+App.php+Tui/Renderer.php (a), lang/en.php (f), docs (f+gate) |
| 4 | img2img/inpaint + import | a=image ingress · b=mask · c=PNG round-trips · d=batch/grid · (s=sketch deferred) | Chat.php (a→c sequenced), ImageSection.php (a→b sequenced), GenerateImage.php (d) |
| 5 | Video | a=require+job client+tool · b=player screen · c=form tab · d=audio probe | Chat.php (a), check-child-lifetimes roster (b), README/docs+composer (a) |
| 6 | Server/web parity | P1=PHP events · P2=PHP methods · W1=regen+reducer · W2=MediaCard/ToolCard · W3=fetch route · W4=ask-relay form | EventType/EventSchemas (P1), reducer.ts (W1), sugar-crush/composer.json (none — W5 owns it) |
| 7 | Hardening & finish | mostly serial | README+docs (single owner), suite-figure (gate) |

---

# Wave 0 — Ground truth & contract lock

No repo writes. Four read-only investigator lanes, concurrent. Output = evidence under `/tmp/opencode/plan-crush-media/w0/<lane>/`; decision records land in this plan's Appendix C via the orchestrator.

## W0.1 — Re-probe skynet2:30001
**Goal:** capture the live endpoint contract of the media server, which refused TCP at the 2026-10-07 probe (crush_media §1a: port sweep found 30000/22/80/443 OPEN, 30001 refused; SGLang chat runs on 30000). This decides the Wave-1 dialect default (W0.3) and answers Q-1/Q-10 (crush_media §11.1).
**Probe first:** whether the operator brought the service up / whether we run from a permitted network (previous probe host `my-web-2.interserver.net` → `173.225.108.102`); DNS still resolves.
**Files likely touched:** none in repo (evidence dir `/tmp/opencode/plan-crush-media/w0/skynet/`).
**Lane:** o1 ; **Depends on:** none ; **Shared-file risk:** none
**Build steps (micro):**
1. TCP + `GET /` on 30001 — if refused, re-sweep the crush_media §1a port list and record; close with a "still dark" verdict. GET/HEAD/TCP only, ZERO mutating requests (§1c budget discipline).
2. If up: `GET /model_info`, `GET /openapi.json` (and `/docs`), `GET /sdapi/v1/sd-models`, `GET /v1/models` — save bodies VERBATIM under `raw/`.
3. Family checklist, explicit present/absent/untested verdicts: `/sdapi/v1/*` (A1111) · `/v1/images/generations|edits` (OpenAI/SGLang-Diffusion) · `/prompt`+`/history`+`/view`+`/object_info` (ComfyUI) · any video job routes (`/v1/videos`, `/jobs`, `/tasks`) field-for-field (mystage §7: video contract is NOT A1111-standard and must be read off the live server).
4. Answer Q-10 from `/openapi.json` schema only (never a paid generation): does the generations route accept `guidance` vs `cfg_scale`?
5. `REPORT.md` ≤40 lines, verdict-first.
**Tests (ship in-step):** none (probe lane).
**Definition of done:** REPORT.md exists; all four endpoint families carry a verdict; raw bodies on disk.
**Review brief:** raw responses substantiate every claim; curl log shows zero POSTs; if dark, confirm the config-authority + fail-open default (crush_media §10.1-7) still stands and W0.3 records "unresolved — default dialect order applies".

## W0.2 — File-drift probe for Waves 1–3
**Goal:** re-confirm at CURRENT master every file:line this plan cites (research pinned 2026-10-07; crush moves daily). Output corrected lists; every step's "Probe first" is executed here once.
**Probe first:** the anchor set: `sugar-crush/composer.json:47` (candy-forms) + `:55` (candy-mosaic) · `src/ToolResult.php:49-66,113-115,152-186,424` · `src/Cli/Bootstrap.php:1390` (mosaic threading) · `src/Renderer.php:1682,2122,4058,4632,4845,4923,4934-4951,4965,4971-4985` · `src/Tui/Renderer.php:566,847` · `src/App/App.php:327,2344,2796,2942,3379-3390` · `src/Backend/EngineBackend.php:3526-3542,3564-3590,4219-4225,5057,5135` · `src/Chat.php:5149,5502,697-707,7486,7832,9663,10086,10685,11486-11491,12926,16796,17603-17628,17919` · `src/Providers/ProviderFactory.php:85-120,380-397,403,428` · `src/Providers/SglangServerInfo.php:63,67` + `rootUrl()` · `src/Providers/Concerns/HttpClientDefaults.php` (connect-bound/no-total-timeout docblock + 120 s idle-bound narrative) · `src/Providers/CompleteRequest.php:144-156` · `src/Tools/AcceptsHeartbeat.php` + `src/Backend/CancellationToken.php` (EXACT signatures of `executeWithHeartbeat($args,$heartbeat)` / `cancelTool($id)`) · `src/Permissions/FetchTarget.php` · `src/Tools/BuiltIn/WebFetch.php:74,266` · `src/Tools/BuiltIn/Bash.php` (heartbeat use) · `src/Tools/BuiltIn/Doctor.php:11,144` · `src/Tools/Catalog/{ToolCatalog.php:222,BuiltInTool.php,ToolPermissionClass.php:29-32,BuildsFromCatalog.php,ToolBuildContext.php}` · `src/Permissions/PermissionGate.php:84,587,1414,1450` · `src/Commands/{CommandRegistry.php:36,89,CommandSpec.php:18-36,NoticesCommand.php}` · `src/Config/LayeredSettings.php:474-504` (`settings:layered-keys:begin` generated block — find ITS generator) · `src/Config/Settings/Definitions/` siblings + `SettingCategory` enum live path · `src/Config/SettingsSchema` (`all :72`/`byKey :97`/`inCategory :115`/`layeredKeys :128`) · `src/Tui/Settings/{SettingsEditor.php:75-77,SettingsFieldFactory.php,SettingsTabStrip.php:19}` · `src/Palette/{PaletteState.php:28,PaletteAction.php}` · `src/Host/{TranscriptProjector.php:219-246 (+MAX_EVENT_CONTENT_BYTES),TurnRunner.php:844,SessionHost.php}` · `src/Protocol/{EventType.php:29-114,SessionFeed.php,EventEnvelope.php:30-36,123,Methods/ToolMethods.php:30-57,Schema/EventSchemas.php:27-38}` · `src/Events/` roster · `src/Server/{Server.php,DiscoveryFile.php:8-18}` · `src/ClipboardImagePastedMsg.php` + `src/AttachmentType.php:12-13` · `src/Message.php:75` · candy-mosaic `Mosaic.php:111,319,352,598` + `Detect.php:17,82,105` + `ImageSource.php:138,215,29,38` · candy-forms `Field/{Slider.php:111,221,Select.php:101,233,MultiSelect.php:92,Confirm.php:42,Input.php:120,Text.php:25,FilePicker.php:37,Note.php:24}.php`, `Form.php:40,91,554,624`, `KeyMap.php:28`, `Viewport.php:329,375-408`, `candy-forms/composer.json` (does forms already depend on candy-mouse/candy-zone?) · sugar-reel `Player.php:170,402,871,1385,1631`, `Render/Mode.php:15-36`, `RendererFactory::autoMode()`, `Source/Probe.php` · `sugar-crush-web` files listed in Appendix E (spot-check line anchors).
2. Re-derive the taken BuiltInTool position set live (mystage fact (d) precondition): `grep -rhoP 'position:\s*\K[0-9]+' sugar-crush/src/ | sort -n | uniq` — authoring-time truth (verified 2026-10-08): `1..22,30,31,32` → **33/34 free**. Any future occupant of 33/34 shifts NOTHING in this plan — re-check and bump to next free pair, recording the collision in the drift report.
**Files likely touched:** none (report `/tmp/opencode/plan-crush-media/w0/drift/REPORT.md`).
**Lane:** o2 ; **Depends on:** none ; **Shared-file risk:** none
**Build steps (micro):**
1. Per anchor: confirm the symbol at/near the cited line; emit `path|symbol|cited|live|status` rows; flag drift >±10 lines as `DRIFT:` with the corrected location.
2. The position re-derivation above (exact command, reproducible).
3. Confirm `sugar-crush/tests/bootstrap.php` LoopPin-first + `!stream_isatty` stdin-guard shape (serial-run law).
4. Command pattern correction: there is NO `src/Commands/builtin-commands/` directory (checked 2026-10-08 — mystage §6.2 path `builtin-commands/4000-generate.php` is FALSE for this tree); the live pattern is `src/Commands/<Name>Command.php` + registry row + `Chat::submit()` dispatch arm (NoticesCommand trio). Record live line anchors for all three parts.
5. Confirm the `settings:layered-keys:begin` generator tool path (so W1.8/gate run it instead of hand-editing).
**Tests (ship in-step):** none.
**Definition of done:** every Wave-1/2/3 citation has a live line or `DRIFT:` row; position set + its re-derivation command recorded.
**Review brief:** spot-check 10 rows with `grep -n`; re-run the position derivation verbatim.

## W0.3 — Dialect-strategy decision record
**Goal:** lock the client dialect order — sdapi-first vs OpenAI-images-first vs capability-detected — into Appendix C (orchestrator lands the edit from this step's recommendation + W0.1 evidence).
**Probe first:** W0.1 outcome; crush_media §1c (SGLang-Diffusion OpenAI-shape expected), §9.7 (`EndpointFamily` enum sketch incl. `ComfyUi`), mystage §7 ("A1111 core does not do video, explicitly"; the user's Flux/LTX/Wan are not stock A1111).
**Files likely touched:** this plan (Appendix C row, orchestrator-authored — lane produces the TEXT, not the edit).
**Lane:** o3 ; **Depends on:** W0.1 ; **Shared-file risk:** none
**Build steps (micro):**
1. Recommended default (pre-committed unless W0.1 contradicts): **capability-detected, sdapi-implemented-first** — infotext, script-args encodings, progress/preview, interrupt/skip semantics are all richer under `/sdapi/v1`, and the legacy-path map (h) only speaks to an sdapi-family server; OpenAI-images stays a thin alternate generator behind `EndpointFamily` (crush_media §9.7 `OpenAiImagesGenerator`).
2. Record: video jobs are ALWAYS a separate dialect/namespace regardless of image family (mystage §7); the W1.2 Job model generalizes both (A1111 sync `/txt2img` is just a 1-step job).
3. Record the fail-open law verbatim: config declares capability authority; the live probe is an opportunistic override, never a gate (crush_media §10.1-7; `SglangServerInfo` precedent).
4. If W0.1 returns ComfyUI-only: flip primary to `ComfyUiGenerator` (`/prompt` + `/history` poll + `/view` fetch, crush_media §9.7), sdapi lane still ships tested against committed fixtures; record that.
**Tests (ship in-step):** none.
**Definition of done:** Appendix C "C-1" filled with ruling + rationale + evidence pointers.
**Review brief:** ruling consistent with W0.1 raw bodies; no downstream step contradicts it (grep plan for `EndpointFamily` usage).

## W0.4 — Baseline suite + sibling figures
**Goal:** record the pre-project floor so each wave's figure delta is attributable: crush serial figures, four sibling lib suite figures, and repo gates.
**Probe first:** linked mode (`php scripts/refresh-deps.php --status` — expect sugar-crush linked; ka-lane lesson: a partially-linked sibling vendor (candy-pty) poisons 5 `InteractivePromptContainmentTest` env reds — verify ALL 18/19 links before trusting any serial); enumerate and EXCLUDE foreign working-tree dirt (list it in the report; touch nothing).
**Files likely touched:** evidence dir `/tmp/opencode/plan-crush-media/w0/baseline/`.
**Lane:** o4 ; **Depends on:** none ; **Shared-file risk:** none
**Build steps (micro):**
1. Serial: `cd sugar-crush && env -u LINES -u COLUMNS vendor/bin/phpunit` via plain bash-tool pipe → keep log + junit xml. Era figure at authoring time ~21,196T/406,402A/1S (2026-10-07 pin, memory) — RE-MEASURE, cite `git rev-parse HEAD` alongside.
2. `wc -l scripts/parallel-tests-durations.tsv`.
3. Siblings from their dirs: candy-forms, candy-mosaic, sugar-reel, candy-core full runs (they are each their own gate; ~30–40 s each expected).
4. `php tools/check-path-repos.php --no-lib-path-repos` and `php tools/check-child-lifetimes.php` exit codes.
5. `baseline.md`: all figures + HEAD shas + dirt inventory.
**Tests (ship in-step):** none (measurement step).
**Definition of done:** baseline.md complete; the Wave-1 gate diffs against it.
**Review brief:** re-run one sibling figure claim independently; confirm the serial log is pipe-shaped (keystone early-return tells).

---

# Wave 1 — Media core (`src/Media/`, no UI)

All new code under `sugar-crush/src/Media/` + `src/Support/MediaStore.php`; unit tests only. No Chat/Renderer/Bootstrap behavior changes (W1.8 touches config plumbing only). Lanes a–f concurrent after W0.2; file sets disjoint by construction. Mystage §8.1's new-code map assigns every Wave-1 file a pattern-source; each step cites it.

## W1.1 — Generation DTOs (`MediaRequest`, `MediaResponse`, `MediaArtifact`, `GenerationParams`)
**Goal:** the one-param-registry-many-projections core (crush_media §5.8 mirror-item 1, §9.7 close): plain serializable value objects that later render into the TUI form, serialize into provider requests, persist into PNG infotext, and cross the RPC wire unchanged (mystage §6.4 design consequence: "keep the generation request/response, the form spec, and the artifact reference as plain serializable DTOs from day one").
**Probe first:** W0.2 anchors for `src/Providers/EmbeddingsRequest.php`/`EmbeddingsResponse.php` (the DTO-pair precedent, crush_media §9.1) and `ProviderInterface.php:11-18,72` (capability-flag shape); house immutable-fluent canonical (`candy-sprinkles/src/Style.php`, trait `candy-core/src/Concerns/Mutable.php` — AGENTS.md).
**Files likely touched:**
- `sugar-crush/src/Media/MediaKind.php` (new) — `enum MediaKind { case Image; case Video; }` (crush_media §9.7).
- `sugar-crush/src/Media/MediaRequest.php` (new) — the full §4.2+§4.3 field set (txt2img+img2img union) with API defaults (steps 50, denoising 0.75, padding 0 — divergence-from-UI-defaults note in docblock; UI defaults 20/0.7/32 are the FORM's, crush_media §2.2 non-conflict row).
- `sugar-crush/src/Media/GenerationParams.php` (new) — knobs bundle (sampler/scheduler/steps/cfg/size/seed-block/hires-block/mask-block) immutable tree, `with*()`, `XSet` sentinels on every nullable so unset ≠ 0 (this distinction IS the A1111 `None`-default semantics, §4.2 `restore_faces: bool = None→opts` rows).
- `sugar-crush/src/Media/MediaArtifact.php` (new) — kind, stored path, optional bytes-on-hand, protocol hint, per-artifact infotext array, seed token value.
- `sugar-crush/src/Media/MediaResponse.php` (new) — `list<MediaArtifact>`, raw `info`, echoed `parameters`, optional job ref.
- `sugar-crush/tests/Media/MediaRequestTest.php`, `sugar-crush/tests/Media/GenerationParamsTest.php` (new) + durations rows.
**Lane:** a ; **Depends on:** W0.2 ; **Shared-file risk:** none
**Build steps (micro):**
1. `declare(strict_types=1)`, `final`, private ctor + `::new()` factory (AGENTS.md factory law: never `create()/make()/default()`); bare accessors; every `with*()` through private `mutate()`; docblocks cite `Mirrors stable-diffusion-webui StableDiffusionProcessing.<field>` + crush_media §4.2/§4.3 table row.
2. `toArray(): array` emits ONLY set fields, keys exactly the wire names (`n_iter`, not `batchCount`); `fromArray()` round-trips; names in `API_NOT_ALLOWED` (crush_media §4.1 list) refused on parse (Fail Fast).
3. Edge guards: positive ints where required; `subseed_strength` outside 0–1 throws; NO clamping of out-of-range generation values — clamp lives in the FORM widget (coercion), the DTO stays honest to what the caller meant (Parse-don't-validate law).
4. `mediaRequestFrom(GenerationParams $p, string $prompt)` composer + `toParamsProjection()` — the two directions between the form-shaped tree and the wire-shaped flat request; this pair is the single conversion point later waves reuse.
**Tests (ship in-step):** coercion: set/unset round-trip byte-stable; `withSteps(0)` ≠ never-set sentinel pin; API_NOT_ALLOWED refusal; float/int precision (seed int64 never crosses float). Behaviour: composer projection table. Filter: `--filter 'Media\\(MediaRequest|GenerationParams)Test'`.
**Definition of done:** 100% of §4.2/§4.3 fields represented or explicitly marked out-of-scope in a const `NOT_SUPPORTED_V1` list with reason (e.g. `latent_mask` — init=False, UI/canvas-side, §4.3 row).
**Review brief:** field list diffed against crush_media §4.2+§4.3 tables row-by-row (missing/misnamed = MAJOR); sentinel discipline per AGENTS.md; mutation: drop one `XSet` flag → the unset≠0 pin reds; mutation: emit an unset field → round-trip pin reds.

## W1.2 — CapabilityDescriptor + async Job model
**Goal:** `MediaCapability`/`EndpointFamily` descriptors + `MediaJob` state machine generalizing `force_task_id` + submit/status/fetch polling — the shared skeleton for A1111-sync-as-1-step-job AND minutes-long video jobs (crush_media §4.6 task registry, §7 "async job semantics"; mystage §7).
**Probe first:** crush_media §9.7 `Capability/` layout; `SglangServerInfo.php:63` (`DISCOVERY_TIMEOUT_SECONDS` 3.0 s metadata-exemption pattern) + `:67` + `rootUrl()`; `ProviderFactory.php:85-120` per-type schema table; W0.3 record if landed.
**Files likely touched:**
- `sugar-crush/src/Media/Capability/EndpointFamily.php` (new) — `enum`: `SdApi | OpenAiImages | SglangDiffusion | ComfyUi | Custom` (crush_media §9.7 superset; SGLang-Diffusion expected shape per §1c mirrors 30000: root `/model_info` + OpenAI-compatible `/v1/images/generations`).
- `sugar-crush/src/Media/Capability/MediaCapability.php` (new) — immutable descriptor: kind(s), family, baseUrl, apiKey-ref (never the secret itself — `${VAR}` name only), bounds (size/steps/duration), `declared` vs `probed` flag provenance.
- `sugar-crush/src/Media/Capability/CapabilityDiscoverer.php` (new) — ladder of discovery GETs (`/sdapi/v1/cmd-flags`, `/sdapi/v1/sd-models`, `/v1/models`, `/model_info`, `/object_info`); every failure swallows to absent; merge **config-declares > server-probes > none**; the probe NEVER gates the feature (fail-open law, crush_media §10.1-7); `diagnose()` returns per-route verdicts for a future doctor surface.
- `sugar-crush/src/Media/MediaJob.php` (new) — `queued|running|done|failed|cancelled` transitions via `withStatus()`; `taskId` (client-chosen — `force_task_id` rides it, §4.2 row: "pin your own task with force_task_id"); artifact paths; progress frames ring (bounded, last N); terminal-state guard (done→running throws).
- `sugar-crush/tests/Media/CapabilityDiscovererTest.php`, `tests/Media/MediaJobTest.php` (new) + durations rows.
**Lane:** a ; **Depends on:** W1.1 ; **Shared-file risk:** none
**Build steps (micro):**
1. Discovery uses plain bounded connects on a TRANSPORT INTERFACE (injectable callable/`SdTransport` from W1.3 — define the seam here, W1.3 implements) so the discoverer is testable with arrays of canned replies.
2. `forProvider(array $providerConfig, ?SdTransport $t = null): ?MediaCapability` — reads W1.8's `mediaKinds` key; probe refines bounds only, never negates declared capability (docblock states the ruling + W0.3 cite).
3. Job id format note: A1111 uses `task(type-XXXXXXX)` (§4.6) — we store server-task-id opaquely plus OUR own job uuid; correlation is the client's book (mystage §1.3 force_task_id law).
**Tests (ship in-step):** behaviour: canned-transport family matrix (sdapi-only / openai-only / comfyui / none); fail-open pin (ALL transports throw → declared capability survives intact); job transition coercion table (illegal transitions throw). No real sockets, no timers. Filter: `--filter 'Media\\(CapabilityDiscoverer|MediaJob)Test'`.
**Definition of done:** video jobs modelable with zero image-specific assumptions; the §10.1-7 doctrine is executable code, not prose.
**Review brief:** the fail-open direction is config-wins — mutation: flip merge order (probe-negates-declared) → exactly one pin reds; confirm the 3.0 s exemption is applied ONLY to discovery GETs, never generation POSTs (read the transport args); Q-3 honesty: capability says nothing about price — no cost fields invented here.

## W1.3 — SdClient: own egress path, auth, error shape
**Goal:** the media HTTP client with its OWN dialing. **Mystage fact (c) is law here: NEVER reuse WebFetch's dialing — `src/Permissions/FetchTarget` carries an SSRF blocklist that refuses LAN/private hosts, which is exactly where the SD server lives.** Permission story instead: the tool is Ask-class + the operator CONFIGURED the host (crush_media §10.1-3).
**Probe first:** W0.2 lines for `HttpClientDefaults.php` (Guzzle, bounded connect, deliberately NO total timeout on completion paths — docblock law), `WebFetch.php:74` (attribute exemplar to CONTRAST, not copy), `FetchTarget.php` (confirm what we must not import); Guzzle availability in sugar-crush/composer.json; mystage §1.1 contract: API exists only with `--api`; Basic auth when `--api-auth`; errors are JSON `{error, detail, body, errors}` with exception `status_code` else 500; every response carries `X-Process-Time`.
**Files likely touched:**
- `sugar-crush/src/Media/Sd/SdTransport.php` (new) — minimal interface `request(string $method, string $path, array $json = [], array $query = []): {status, body}` (the W1.2 seam).
- `sugar-crush/src/Media/Sd/GuzzleTransport.php` (new) — implements it: connect-bounded, read-idle generous, no wall-clock total (E646 blanket-timeout ban, crush_media §10.1-2); Basic-auth header iff credentials configured.
- `sugar-crush/src/Media/Sd/UrlGuard.php` (new) — media egress guard: only the CONFIGURED origin (scheme+host+port equality per request; redirects re-checked and refused off-origin; userinfo in URL refused). Docblock: WHY this exists instead of FetchTarget (verbatim fact (c) cite).
- `sugar-crush/src/Media/Sd/Client.php` (new) — typed methods for: `txt2img`, `img2img`, `extraSingleImage`, `extraBatchImages`, `pngInfo`, `interrogate`, `progress(bool $withPreview)`, `interrupt`, `skip`, `options` GET/POST, `cmdFlags`, `samplers`, `schedulers`, `upscalers`, `latentUpscaleModes`, `sdModels`, `sdVae`, `promptStyles`, `scripts`, `scriptInfo`, `memory`; bodies from `MediaRequest::toArray()`; `send_images`/`save_images` handling per §4.2 note (handler overwrites do_not_save_* from save_images — expose our own flag).
- `sugar-crush/src/Media/Sd/SdException.php` (new) — status/error/detail/body/errors fields verbatim from the mystage §1.1 shape.
- `sugar-crush/tests/Media/Sd/ClientTest.php`, `tests/Media/Sd/UrlGuardTest.php` (new) + durations rows.
**Lane:** b ; **Depends on:** W1.1, W1.2 (transport seam) ; **Shared-file risk:** none
**Build steps (micro):**
1. Every URL built by joining configured base + `Endpoints::resolve(path)` (W1.4) — no caller-supplied absolute URLs EVER reach the transport (pin).
2. Non-2xx → parse JSON error body into SdException; non-JSON body → SdException with raw detail + status (never throw raw).
3. b64 payload fields (`init_images`, `mask`, pngInfo `image`) accepted with OR without `data:` prefix — normalize at client edge (strip), document (mystage §5.4 ingestion row 1).
4. Zero ReactPHP loop/stream usage, zero timers in this file (execution context = forked tool child, W2.1; loop-thread HTTP is §10.1-1's named failure).
5. Census pin test: `grep -l 'FetchTarget' src/Media/**` == empty, asserted by a test walking the directory (machine-lawed fact (c)).
**Tests (ship in-step):** behaviour (MockHandler doubles: 200 roundtrip; 422 A1111 error JSON → SdException fields populated; 500 non-JSON → raw kept; redirect off-origin refused; Basic header present iff creds), coercion (data-URI strip both shapes; URL with userinfo refused). Filter: `--filter 'Media\\Sd\\(Client|UrlGuard)Test'`.
**Definition of done:** an operator pointing `sd.baseUrl` at `http://skynet2.lan:30001` gets service; WebFetch policy would have refused — pin that asymmetry in a test comment with the fact-(c) citation.
**Review brief:** grep census `src/Media/ -l FetchTarget` → 0; mutation: accept absolute cross-origin redirect → UrlGuard pin reds; mutation: add a total-timeout → fails the E646/§10.1-2 house law (reviewer greps `timeout` literals — Guzzle `connect_timeout` OK, `timeout` NOT); check no secret ever logged (SdException carries server body — verify bodies with obvious key echoes are truncated).

## W1.4 — Endpoint table + legacy path map
**Goal:** mystage fact (h) as code: the client maps legacy endpoints to current equivalents and NEVER guesses. Also locks the endpoint consts against the A1111 clone's live route registrations (mystage §1.2 "verified against this tree").
**Probe first:** mystage §1.2 endpoint table + its explicit non-existence list; crush_media §4.4 shapes table; live route registrations in `/home/sites/sdg-assets/stable-diffusion-webui/modules/api/api.py` (clone exists per crush_media §0.2/§ verification note — read-only grep of `@self.add_api_route`); W0.1 result (if server answered, its `/openapi.json` is a second oracle).
**Files likely touched:**
- `sugar-crush/src/Media/Sd/Endpoints.php` (new) — path consts for every W1.3 method; `const LEGACY_ALIASES = [...]` mapping EACH of the 12 mystage-(h) legacy names to a live equivalent or a reject sentinel — the list verbatim: `/sd-metadata.json`, `/internal/info`, `/sdapi/v1/settings`→`/sdapi/v1/options`, `/option/{key}`→options-GET, `/tick`, `/txt2img/progress|skip|interrupt` (per-tab)→`/sdapi/v1/progress|skip|interrupt`, `/v1/images`→`/v1/images/generations` (OpenAI family note), `/v1/embeddings`→ reject in media scope (chat-side family), `/refresh-checkpoints-models`→`/sdapi/v1/refresh-checkpoints`, `/reload-clips`, `/mem-usage`→`/sdapi/v1/memory`, `/img2img-grids`→ reject (no equivalent — record why).
- `sugar-crush/src/Media/Sd/Response.php` (new) — parsed generation response: `images[]` (b64 stripped→bytes), `parameters`, `info` JSON decoded incl. `infotexts[]`, `all_seeds`, `all_subseeds`, `index_of_first_image`; **grid-prepend law** (mystage §1.3, crush_media §4.2 response note): when `index_of_first_image === 1`, image[0] is the grid, NOT a sample.
- `sugar-crush/tests/Media/Sd/EndpointsTest.php`, `tests/Media/Sd/ResponseTest.php` (new) + durations rows.
**Lane:** b ; **Depends on:** W1.3 ; **Shared-file risk:** none
**Build steps (micro):**
1. `resolve(string $maybeLegacy): string` — live paths pass through; legacy names rewrite; unknown → SdException naming the closest suggestion (fail-fast + helpful, the "client maps, never guesses" doctrine).
2. Bidirectional roster test: every LEGACY_ALIASES target resolves to a const path that exists in the clone's api.py route set (hardcoded expected-list fixture derived FROM the clone at step-probe time — a committed `tests/fixtures/sd/clone-routes.txt` snapshot so CI stays hermetic).
3. Response fixture decode: `tests/fixtures/sd/txt2img-response.json` (shape from clone api, keys per §4.2 Response note; NO real base64 blobs in the JSON fixture — tiny 1x1 stubs).
**Tests (ship in-step):** coercion (all 12 legacy entries map per table; a live path is NOT rewritten; two rejects carry reason strings), behaviour (grid fixture: 7 images + index_of_first_image=1 → 6 artifacts + gridArtifact slot; missing index → none assumed). Filter: `--filter 'Media\\Sd\\(Endpoints|Response)Test'`.
**Definition of done:** every endpoint the client can hit is enumerated; every dead legacy name has a mapping-or-reject verdict in code.
**Review brief:** reviewer re-greps the clone's `add_api_route` set and diffs against Endpoints consts — a §4.4-listed endpoint missing from the client = MAJOR (admin/train/server-kill family intentionally absent = correct: scope is read + generate + progress-control only; mystage §1.2 lists them, we EXPOSE none of `/train/*`, `/create/*`, `/server-kill|-restart|-stop`, `/flush-memory` — mutation idea: adding one must NOT break a pin, since these are allowlist-by-omission: verify via the roster test's closed-set equality which WILL red on unregistered additions).

## W1.5 — Heartbeat progress loop
**Goal:** mystage fact (a) made executable: EngineBackend's 120 s `COMPLETE_TIMEOUT_SECONDS` is an **IDLE ceiling re-armed per streamed progress frame** — any generation tool must beat it. `AcceptsHeartbeat::executeWithHeartbeat($args, $heartbeat)` + `CancellationToken::cancelTool($id)` is MANDATORY. This step builds the reusable client-side loop that fires `$heartbeat()` on each `/progress` poll; W2.1/W5.3 wire it into tool contracts.
**Probe first:** W0.2 exact signatures of `src/Tools/AcceptsHeartbeat.php` / `src/Backend/CancellationToken.php` (names/arity from the live files, not this doc); `src/Tools/BuiltIn/Bash.php` heartbeat consumption (mystage §6.1 copy-target list: "Doctor (image result), WebFetch (schema+Ask+stream dialing), Bash (heartbeat) are the copy targets"); `CompleteRequest.php:144-156` blocking-curl + heartbeat options precedent; crush_media §4.7 progress math + `skip_current_image` + `current_task` fields + §4.6 note that public `/progress` reads the SHARED UI state singleton (not per-task — per-task is `/internal/progress`, Gradio-side; our v1 uses public /progress and documents the co-tenant ambiguity).
**Files likely touched:**
- `sugar-crush/src/Media/Sd/ProgressLoop.php` (new) — `run(Client $c, callable $heartbeat, callable $isCancelled, ?callable $onPreviewFrame = null, float $intervalSeconds = 0.5, ?callable $sleeper = null): ProgressSummary`; loop body: poll `/progress?skip_current_image=<bool>` → fire `$heartbeat` EVERY poll before sleeping (per-frame re-arm law) → check `$isCancelled()` between polls → `$onPreviewFrame(current_image_b64)` when present → exit when the awaited generation completed (caller-side sentinel via `$isCancelled`-style done flag) or 3 consecutive transport failures (bounded fail-fast).
- `sugar-crush/src/Media/Sd/ProgressState.php` (new) — decoded `{progress, etaRelative, state{jobNo,jobCount,samplingStep,samplingSteps,skipped,interrupted,finished}, textinfo, currentTask}`; the §4.7 math quoted in a docblock so readers can audit server output.
- `sugar-crush/tests/Media/Sd/ProgressLoopTest.php` (new) + durations row.
**Lane:** b ; **Depends on:** W1.3, W1.4 ; **Shared-file risk:** none
**Build steps (micro):**
1. Sleeper/clock seams (house timing-free pin pattern — sugar-readline E713 lesson: injectable sleeper ⇒ deterministic tests, no wall-clock sleeps in CI).
2. Beat BEFORE first sleep (a 120 s silent generation must not die in its first interval).
3. `interrupt()`/`skip()` passthrough helpers on ProgressLoop (POST `/sdapi/v1/interrupt|skip` — global-state semantics documented per §4.6; the two-stage A1111 interrupt (interrupt_after_current, §4.7) surfaces as `requestInterrupt(bool $afterCurrent)`).
4. NO wall-clock deadline anywhere (E646); progress monotonicity is NOT assumed (server shared-state can interleave other tenants — loop tolerates non-monotonic frames, reports them raw).
**Tests (ship in-step):** behaviour: canned progress sequence → heartbeat call-count == poll-count, preview frames forwarded in order, cancel mid-loop exits ≤1 tick, interrupt passthrough fires POST, 3-failure streak raises SdException (not 4 — bounded); coercion: malformed progress payload → skipped frame, loop survives. Filter: `--filter 'Media\\Sd\\ProgressLoopTest'`.
**Definition of done:** any long generation can survive the idle watchdog BY CONSTRUCTION; the beat cadence is provably per-poll.
**Review brief:** mutation: fire heartbeat only on progress CHANGE → constant-progress fixture reds (proves per-poll beat); mutation: remove the 3-fail bound → hang-guard test (fake never-ending 404 storm) reds; confirm zero `React\` imports in the file (loop purity); W0.2 signature diff: the closure shapes here must match what `AcceptsHeartbeat` actually receives.

## W1.6 — Infotext emit/parse module
**Goal:** byte-compatible A1111 `parameters` string, BOTH directions (crush_media §3.5 exact payload spec + §3.6 round-trip contract + mystage §1.4 grammar block): emit on save/display, parse-back for paste/import, `applyTo(onlyUnset)` free state restoration.
**Probe first:** crush_media §3.5 payload line + conditional-emission rules (Variation-seed only if strength≠0, Clip skip only if >1, ENSD only uses_ensd samplers, Init image hash only img2img, RNG only non-GPU, Tiling only True, extra_generation_params spliced before Version, User tail); quoting rule ("any value containing `,` `\n` `:` is json.dumps-quoted"); §3.6 `apply_infotext`/`paste_fields` semantics + `infotext_versions` legacy-spelling note; C-3/C-4 values ride this module.
**Files likely touched:**
- `sugar-crush/src/Media/Infotext.php` (new) — `emit(GenerationParams $p, array $extra = []): string`, `parse(string $s): array` (flat `Label => value` map, first-line prompt + second-line negative per §1.4 shape), `applyTo(MediaRequest $r, array $parsed, bool $onlyUnset = true): MediaRequest`.
- `sugar-crush/src/Media/InfotextFields.php` (new) — the two-way label↔field table (§3.6 directive "implement the same two-way label↔field table"): every A1111 label verbatim (`'CFG scale'=>'cfg_scale'`, `'Sampling steps'=>'steps'`… full list from §2 tables' infotext columns incl. §2.10 sampler-params family `Eta`/`Sigma churn`/`UniPC variant`/`NGMS`…) + legacy spellings map (parse-tolerant, emit-canonical).
- `sugar-crush/tests/Media/InfotextTest.php` (new) + durations row.
**Lane:** c ; **Depends on:** W1.1 ; **Shared-file risk:** none
**Build steps (micro):**
1. Golden pair: compose the full canonical string from mystage §1.4's block with realistic values; `parse()` → field map equals a committed expectation array; `emit()` of that map → BYTE-IDENTICAL string (the interop contract).
2. Emission order fixed per §3.5; pairs joined `", "`; empty-negative line omitted; `#` comment stripping default-on behind an `$stripComments` flag (§3.5 1.8+ behavior, `enable_prompt_comments` analog).
3. Quoting: values containing `,`/`\n`/`:` json-quoted on emit, unquoted on parse (round-trip property test).
4. `<lora:…>` / `[from:to:when]` schedule syntax PASS THROUGH untouched — parse must not mistake `:` inside brackets for a pair separator (the parser splits on `, ` at depth-0, then `: ` first-occurrence — pin both traps).
5. `applyTo` fills ONLY unset request fields (the `onlyUnset` law) — set fields win over pasted text.
**Tests (ship in-step):** snapshot-byte golden; property round-trip (emit→parse→emit stable over a generator of param combos incl. adversarial punctuation prompts); coercion (garbage string → empty map no-throw; legacy spelling parses to canonical key; quoted-value survival). Filter: `--filter 'Media\\InfotextTest'`.
**Definition of done:** a real A1111-saved PNG's parameters string re-loads into our DTOs verbatim (§3.6 directive) — golden fixture taken from the clone docs/Features cite, not folklore.
**Review brief:** reviewer runs the mystage §1.4 sample through parse+applyTo and diffs; mutation: emit Clip skip when value==1 (conditional rule broken) → golden red; mutation: parse pair-split on naive `:` → lora/schedule pin reds; verify our future Version stamp policy: we emit the BACKEND's version from `/cmd-flags`-adjacent info or the §3.5 `Version:` key untouched when replaying, and add our own key (planned: `Sugar-crush: <ver>` as an extra_generation_params-style splice before Version — record in docblock, pin in golden).

## W1.7 — PNG tEXt chunk read/write + encode helper
**Goal:** stamp/read the `parameters` tEXt chunk ourselves and encode gd→PNG bytes — the two missing pieces crush_media §8.7-3 names ("missing: encode-to-PNG helper… natural home new src/Encoder.php in candy-mosaic OR ext-gd imagepng directly in crush"). **Ruling for this plan: crush-local `src/Media/Png.php`** (keeps candy-mosaic's API frozen; revisit upstreaming as a Wave-7 backlog note). Existing-chunk preservation per §3.5 (`existing_pnginfo` copy law).
**Probe first:** §3.5 container facts (tEXt keyword `parameters` default `pnginfo_section_name`, `modules/images.py:565,624` cite); `ImageSource` decode ceilings (`MAX_PIXELS=50M`, `MAX_BYTES=64MiB` — ImageSource.php:29,:38) as our size-ceiling siblings; ext-gd availability (crush_media §8.9: gd PNG+JPEG+WebP true; tests must still pass where webp absent — gd_info() conditional skip NOT needed since we only touch PNG); how A1111 writes tEXt (latin1 raw) vs iTXt (UTF-8) — interop ruling in build steps.
**Files likely touched:**
- `sugar-crush/src/Media/Png.php` (new) — `readText(string $pngBytes, string $keyword = 'parameters'): ?string` (chunk walk; tEXt+iTXt; compressed iTXt via gzuncompress), `writeText(string $pngBytes, string $keyword, string $text): string` (full-file rebuild inserting/replacing the tEXt before IEND, CRC32 chunks, preserve ALL other chunks; existing `parameters` REPLACED not duplicated), `encodeGd(\GdImage $im): string` (imagepng to buffer), `encodeTextAsPng?` — no: raster creation stays gd; `isPng(string $bytes): bool` (8-byte signature).
- `sugar-crush/tests/Media/PngTest.php` (new) + `sugar-crush/tests/fixtures/png/tiny-parameters.png` (new, deterministic 4x4 + tEXt, generated then frozen — its bytes reviewed into the commit).
**Lane:** d ; **Depends on:** W1.6 (payload text origin) ; **Shared-file risk:** none
**Build steps (micro):**
1. Chunk framing strict: length(4)+type(4)+data+crc(4); stop at IEND; refuse non-PNG, refuse total > 64MiB, refuse truncated tail (Early Exit guards; reader returns null on damage, never throws — mirrors the "decode failures cost one transcript line" resilience law, crush_media §8.6-4).
2. Encoding choice for text: tEXt (latin1) when `mb_check_encoding($text,'ASCII')`… precisely: A1111 writes tEXt with the raw str; non-latin1 bytes → iTXt(compressed, language tag '') and docblock-disclose the interop edge (some legacy readers miss iTXt) + `writeText` returns which strategy used.
3. Write ordering: chunk inserted immediately before IEND; CRC = `crc32($type.$data)` (common trap: type INCLUDED — pin test with a hand-computed expected chunk byte).
4. `encodeGd` wraps `imagepng($im)` output buffering; no resample/blend logic here (mosaic's job).
**Tests (ship in-step):** snapshot-byte golden round-trip (fixture emit→embed→read == input string; chunk bytes exact-match a committed hex block); coercion (truncated PNG → null; garbage keyword → null; oversized → refusal; unicode prompt → iTXt path asserted by strategy return + re-read equality; double-write replaces not appends). Filter: `--filter 'Media\\PngTest'`.
**Definition of done:** saving a generated PNG stamps infotext; reading a real A1111 PNG extracts it (verify with one genuine-clone-produced fixture if the reviewer supplies one byte-checked from the clone's test data — else from §3.5 spec).
**Review brief:** verify CRC-with-type claim against PNG spec §5.3 (a no-type CRC compiles fine and breaks every reader — mutation: drop `$type` from crc input → golden byte pin reds); chunk-replace ordering vs `existing_pnginfo` preservation law (a rewrite must not drop unrelated tEXt like `Software` — fixture carries two chunks to pin this).

## W1.8 — Provider/config authority (`mediaKinds`) + media settings
**Goal:** make capability DECLARABLE with zero code (mystage §6.3: "`flux2dev` supports image gen" rides the existing providers map): add optional `mediaKinds` to the provider schema table + land the `MediaSettings` category with `sd.baseUrl/apiKey/timeoutSeconds`, `ui.imageRenderMode`, and the two opaque maps W3.9 will write — config-side of the fail-open design.
**Probe first:** W0.2 lines: `ProviderFactory.php:85-120` (per-type schema: sglang required `[baseUrl,model]`, optional `[apiKey, toolCallParser, discoverServerInfo, supportsVision, modelPrices, contextWindow, fallbackModels]` — append `mediaKinds`, `mediaBaseUrl`, `mediaApiKey` to sglang+custom optionals), `:380-397` `${VAR}` interpolation (secret channel law — mystage §6.3: `${VAR}` placeholder in config.json is THE sanctioned secret path), `:428` `projectProviderConfig()`; `LayeredSettings.php:474-504` generated block + its GENERATOR tool (W0.2 step 5); `Definitions/ModelProviderSettings.php` sibling shape (RiskClass/ApplyMode/withLayered idioms, env-backing pattern); `SettingsSchema::all/byKey/inCategory/layeredKeys`; SettingCategory enum live file.
**Files likely touched:**
- `sugar-crush/src/Providers/ProviderFactory.php` (edit) — schema optionals above; absent-keys behavior byte-identical (negative pin).
- `sugar-crush/src/Config/Settings/Definitions/MediaSettings.php` (new) — rows: `sd.baseUrl` (String, `RiskClass::Egress`, layered, env `SUGARCRUSH_SD_BASE_URL`, UrlValidator), `sd.apiKey` (Security tier, user-config-only, `${VAR}` doc), `sd.timeoutSeconds` (Int, `ApplyMode::NextTurn`), `sd.defaultModel` (String), `ui.imageRenderMode` (String enum-validated: `auto` + the `fromModeString` vocabulary `sixel|kitty|iterm2|halfblock|half|ansi|quarterblock|quarter|ascii|ansi256|truecolor|chafa` verbatim + env `SUGARCRUSH_MEDIA_RENDER_MODE` — Q-7 ruling adopted: fromModeString's words as-is plus `auto`), `sd.presets` (Map, opaque), `ui.mediaDisplayOverrides` (Map, opaque), `sd.savePattern` (String, default `[date]-[time]-[seed]-[number]`, W1.9/W4.5 consume).
- `sugar-crush/src/Config/Settings/SettingCategory.php` (edit — live enum path per W0.2) — `media` case.
- `sugar-crush/src/Config/LayeredSettings.php` (edit — via the generated-block generator ONLY, never hand).
- `sugar-crush/docs/SETTINGS.md`, `sugar-crush/docs/ENVIRONMENT.md` (edit — lane e owns docs this wave; rows staged in the step).
- `sugar-crush/tests/Media/MediaSettingsTest.php`, `tests/Providers/ProviderFactoryMediaKindsTest.php` (new) + durations rows.
**Lane:** e ; **Depends on:** W0.2 ; **Shared-file risk:** docs/* (e owns), LayeredSettings.php, ProviderFactory.php
**Build steps (micro):**
1. Render-mode validator closure: accepts iff `'auto'` OR `Mosaic::fromModeString($v) !== null` (candy-mosaic `Mosaic.php:598` — dependency ALREADY present, no require-bump); invalid → `SettingsWriter::refusal()` message naming the allowed set.
2. mediaKinds validator: subset of `{image, video}`, else refusal; ordering irrelevant, dupes collapse.
3. Run the layered-keys generator; diff shows ONLY the new keys; `EnvRosterDriftTest` demands the two env rows in ENVIRONMENT.md — land them in-step (docs owner e owns both anyway).
4. `${VAR}` round-trip: unset env → honest-null capability (provider declared mediaKinds but no resolvable key → still usable UNLESS the server demands auth — server will 401, surfaced as SdException, not a crush-side hard-fail; docblock this).
**Tests (ship in-step):** coercion (bad render-mode refused with allowed-set message; mediaKinds junk refused; `${MISSING_VAR}` → null apiKey not literal string), behaviour (provider WITHOUT mediaKinds config reads byte-identical to before — golden on the factory output array; negative pin), docs pair (EnvRoster + ConfigWriteProducer gates green). Filter: `--filter 'Media\\MediaSettingsTest|Providers\\ProviderFactoryMediaKindsTest|EnvRosterDriftTest|ConfigWriteProducerDocumentationDriftTest|TrustKeyDocumentationDriftTest'`.
**Definition of done:** operator declares a media backend entirely in config (mystage §6.3 promise) and can switch render mode via settings/env.
**Review brief:** TrustKey tier audit (per-row user/project/flag vs siblings — wrong tier on apiKey = security MAJOR); StderrEmitterCensus must stay green (zero new emitters); mutation: accept junk render-mode → coercion pin reds; mutation: drop `withLayered()` from baseUrl → LayeredSettings test reds; `php tools/check-path-repos.php --no-lib-path-repos` still 0 (no dep edges).

## W1.9 — MediaStore artifact storage
**Goal:** the artifact home: `~/.sugar-crush/media/<session>/` with 0700 dirs / 0600 files, `.partial`+rename atomicity, filename-token naming (crush_media §8.7 storage note + mystage §6.5 storage law verbatim: "generated artifacts → ~/.sugar-crush/media/<session>/ … following Server/StateDir/PrivateRetainedDir discipline … ToolOutputSpill/ClipboardImage temp dirs have the wrong lifetime (7-day sweep) … store artifacts at a path, never only in memory"). Q-4 (auto-save workspace-vs-home) defaults to HOME store with a Wave-7 revisit note in Appendix C.
**Probe first:** W0.2 lines for `src/Server/StateDir.php`/`PrivateRetainedDir` equivalents (live symbol names for the perms idiom + home-dir resolution; also `HomeSandbox`-style env HOME handling in tests); candy-core `AtomicJsonFile::withPermissions` ordering law (chmod on TEMP before payload/rename — structural-ordering test idiom from the repo's own precedent); session-id source (`src/Host/SessionHost` or `Sessions/*` live path per W0.2).
**Files likely touched:**
- `sugar-crush/src/Support/MediaStore.php` (new) — `forSession(string $sessionId): self` (root: `<crush-home>/media/<sanitized-session>/`, create 0700); `put(string $bytes, string $ext, array $tokens): MediaArtifact-ref` — filename from `sd.savePattern` tokens (palette: `[date] [time] [seed] [steps] [cfg] [sampler] [model_name] [width] [height] [number] [batch_number] [generation_number] [job_timestamp]` — A1111 §3.4/§5.7 subset; prompt-derived tokens EXCLUDED in v1, docblock states the injection-surface ruling), sanitize + 128-char cap (`--filenames-max-length 128` analog), `.partial`→chmod 0600→write→rename, `[number]` collision increment; `list()`, `pathFor(artifactId)`, `delete(id)`; NO auto-sweep (retention ruling = Wave-7/Appendix C).
- `sugar-crush/tests/Support/MediaStoreTest.php` (new) + durations row.
**Lane:** f ; **Depends on:** W1.1 (token source), W1.8 (`sd.savePattern`) ; **Shared-file risk:** none
**Build steps (micro):**
1. Session-id sanitize: strip separators, clamp length, non-empty fallback `default` (path-traversal early-exit — `..`/slash inside id refused, not stripped-and-continued).
2. Token fill: unknown/missing token → literal kept small-safe (empty string) with a debug note in return metadata; values sanitized per token type (numeric tokens validated numeric else refused).
3. Ordering law test STRUCTURAL (mirror the candy-core permissions-structure pin): read our own source region and assert chmod happens between fopen-temp and rename — functional perms check alone can pass a chmod-after-rename implementation, the structural arm cannot.
4. Return absolute path + stable artifact id (basename-minus-ext) — ToolResult `$imagePath` will carry it (mystage §6.5).
**Tests (ship in-step):** coercion (pattern tokens, 128 cap, collision increment, traversal refusals), perms pin (0700/0600 via substr(sprintf('%o', …),-4) after clearstatcache), atomicity (no `.partial` residue after success AND after injected mid-write failure via token that trips a throwing writer), structural-ordering pin. Filter: `--filter 'Support\\MediaStoreTest'`.
**Definition of done:** every later wave's saves flow through this class; nothing ever lands world-readable.
**Review brief:** mutation: chmod after rename → STRUCTURAL pin reds (functional may stay green — that's the point, reviewer note); mutation: session id `../../evil` → refusal pin reds; confirm HOME override respected under tests (HomeSandboxTrait usage per the repo's snapshot-ordering law from lane-cf: snapshot BEFORE env unset loop, re-arm after).

---

# Wave 2 — Console t2i end-to-end (pre-form)

Goal: a console user (or the model) produces a painted image from text via tool + minimal command, before the big popup exists. Lanes: a=GenerateImage tool · b=render-mode wiring (owns Bootstrap.php) · c=/generate command (owns Chat.php) · d=live preview (owns src/Renderer.php) · e=docs owner (owns README/docs).

## W2.1 — `GenerateImage` tool
**Goal:** first production consumer of Wave 1. `#[BuiltInTool(name:'GenerateImage', permission: ToolPermissionClass::Ask, position: 33, gloss:'…')]` implementing `AcceptsHeartbeat` + `BuildsFromCatalog::fromCatalog(ToolBuildContext)` — **mystage fact (d):** positions 1–22, 30–32 are taken (re-derived 2026-10-08; W0.2 step 2 re-derives again at build time before assigning — if 33 is taken by then, take next free and update W5.3's pair accordingly, keeping the two adjacent). Result flows through the EXISTING image pipeline (`ToolResult::okWithImage` — crush_media §8.6: "an image-generation tool lands on the working display path with zero new renderer work").
**Probe first:** W0.2 anchors (`WebFetch.php:74` attribute+schema exemplar; `Bash.php` heartbeat; `ToolResult.php:152-186` okWithImage live signature; `EngineBackend.php:3564-3590` fork site — the tool executes IN the forked child (§10.1-1; degraded no-pcntl path `completeAsyncBlocking()` blocks the UI — document, don't fight it); `ToolBuildContext` "has no settings field — launch-wide config arrives via a new context field or the per-turn `ToolLimits::applyTo()` rebind" (mystage §6.1) — probe `ToolBuildContext.php` + how WebFetch/Doctor get config today, pick the minimal honest channel (planned: fromCatalog receives the app config accessor already present in the catalog build — verify live).
**Files likely touched:**
- `sugar-crush/src/Tools/BuiltIn/GenerateImage.php` (new)
- `sugar-crush/tests/Tools/GenerateImageTest.php` (new) + durations row
- (README tool roster row + ARCHITECTURE row — staged text handed to lane e; per wave law the gen-tool-docs run itself waits for the gate)
**Lane:** a ; **Depends on:** W1.1–W1.6, W1.8, W1.9 ; **Shared-file risk:** README (e owns)
**Build steps (micro):**
1. `inputSchema()` — v1 properties: `prompt` (string, required), `negative_prompt`, `width`, `height`, `steps`, `cfg_scale`, `seed`, `sampler_name`, `scheduler`, `batch_size`, `n_iter`, `save_to_disk` (bool). **Mystage fact (b): every empty-object position encodes `new \stdClass()`** — a bare `[]` json_encodes as array and strict servers reject; apply to `properties`/`required` when empty and to any nested object default; pin by asserting `json_encode($schema)` contains `"properties":{}`.
2. Execute: capability gate first (config `mediaKinds`∋image via W1.8 → `MediaCapability`; absent → refusal ToolResult, denial wording dodging the DenialPrefixRosterTest off-roster shapes — reuse an EXISTING denial frame or plain prose, probe first); build MediaRequest (args ∩ DTO + settings defaults); POST inside the tool-child; wrap the wait in `executeWithHeartbeat($args, $heartbeat)` → `ProgressLoop::run(heartbeat: $heartbeat, isCancelled: fn() => CancellationToken::cancelTool($id))` (exact live signatures from W0.2 step 1).
3. On response: decode `Response` (grid law); per artifact: `Infotext::emit` → `Png::writeText` → `MediaStore::put`; result content = human line + artifact path list; `ToolResult::okWithImage(name, text, $firstPngBytes)` + `imagePath` populated (mystage §6.5: bytes for TUI now, path for web later); `imageProtocol` stamps free via existing factory (`ToolResult.php:167,186`).
4. Attributes: `Ask` + position + gloss; implements `ParallelSafe`? — NO (single-server queue_lock §4.6; declaring parallel-safe would interleave batches) — check the interface roster in `src/Tools/` and OMIT parallel-safety; `TruncatesOutput` behavior honored by existing machinery.
5. Catalog smoke: test performs a REAL `ToolCatalog` lookup (not reflection-only) asserting name/position/permission-class and that the rules grammar `'GenerateImage(...)'` parses (mystage §6.1: "rules grammar works once cataloged").
**Tests (ship in-step):** behaviour (fake transport fixture server: happy path writes artifact files under a temp HOME and returns bytes-equal imageBytes; cancel mid-loop; server 422 → error result not throw; no-capability → refusal shape pin), coercion (schema `{}` stdClass pin; unknown arg ignored per house schema-consume rules; seed int64 exactness through the args array). No timers; ProgressLoop driven via its sleeper seam. Filter: `--filter 'Tools\\GenerateImageTest'`.
**Definition of done:** the model can generate an image end-to-end; idle-watchdog survival pinned.
**Review brief:** (1) position 33 vs W0.2's re-derived set (collision = CRITICAL); (2) trace that no network happens on the loop thread when pcntl exists (read EngineBackend dispatch path for this tool class); (3) mutation: comment the heartbeat wrap → the beat-count pin reds; (4) mutation: return content-only (drop imageBytes) → display-path pin reds; (5) drift guards that bite at gate: gen-tool-docs count + README roster (e's job) + DocFigure if prose numerals used — this step's gloss/description must avoid numerals; (6) transcript-literal law: any denial/example strings dodge DenialPrefixRosterTest vocabulary AND GlobDialectDifferential glob-shaped token shapes (space-bearing substrings, no bare `*`-literals).

## W2.2 — `ui.imageRenderMode` wired into the paint path
**Goal:** crush_media §8.7 gap-1 closed: `ToolResult::probeMosaic()` memoizes `Mosaic::auto()` unconditionally (`ToolResult.php:424`) — make it mode-aware so the W1.8 setting takes effect; the render LRU is ALREADY protocol-keyed (`xxh3(bytes):COLSxROWS:protocol`, `Renderer.php:4934-4951`) so mode switches invalidate for free (crush_media §8.7: "cache already keyed by protocol so mode switches invalidate for free").
**Probe first:** W0.2 lines `ToolResult.php:49-66,424`, `Cli/Bootstrap.php:1390` threading site, `Mosaic.php:598` fromModeString vocabulary, `Renderer.php:4965` inline-vs-blob switch (`$mosaic->isInline() ? body : $images->place(...)` — unchanged); reset/memoization story: static-per-process means a mid-session setting change needs either restart-honesty (ApplyMode::NextTurn — pick this) or a reset seam; ruling: **mode-keyed static memo array** (cleanest: `private static array $mosaics = []` keyed by mode string) + `setRenderMode(string)` static set by Bootstrap at boot; live-switch later (wave 7 polish note).
**Files likely touched:**
- `sugar-crush/src/ToolResult.php` (edit) — probeMosaic mode-aware per ruling; default `auto` behavior byte-identical (golden pin).
- `sugar-crush/src/Cli/Bootstrap.php` (edit) — read `ui.imageRenderMode` (settings accessor pattern of neighboring reads) → `ToolResult::setRenderMode(...)` beside the existing mosaic threading `:1390`.
- `sugar-crush/tests/Media/RenderModeWiringTest.php` (new) + durations row.
**Lane:** b ; **Depends on:** W1.8 ; **Shared-file risk:** src/Cli/Bootstrap.php (b owns it in W2)
**Build steps (micro):**
1. `setRenderMode` stores the string; `probeMosaic()` returns memo[mode]: `'auto'` → `Mosaic::auto()` (never throws, env-first, tmux decorator), else `Mosaic::fromModeString($mode) ?? Mosaic::auto()` (validator upstream; silent auto-fallback here, zero error_log — StderrEmitterCensus must not gain sites).
2. `protocol()`/`isInline()` flow unchanged downstream.
3. Docblock the ladder facts (mystage fact (g) image half): **mosaic image auto = kitty > iterm2 > sixel > chafa > halfblock** (candy-mosaic `Detect.php:17` precedence; crush_media §8.2) — and note reel's video ladder differs (sixel-first, §8.3) so nobody "harmonizes" them later; grids force cell renderers — pointer to W2.4/W5.6.
**Tests (ship in-step):** behaviour (forced modes return instances with expected `isInline()` — halfblock/quarterblock/ascii true, kitty/iterm2/sixel false; per-mode memo identity: two calls same instance; `auto` under piped test env deterministic via existing non-tty early-exits); golden: default-mode probe result `protocol()` unchanged vs pre-edit behavior pin. Filter: `--filter 'Media\\RenderModeWiringTest'`.
**Definition of done:** `SUGARCRUSH_MEDIA_RENDER_MODE=halfblock` (env-backed row from W1.8) flips generation painting without code.
**Review brief:** mutation: ignore mode arg → kitty-pin reds; verify Bootstrap edit is ONE setter call region (no behavior drift — diff review); StderrEmitterCensus + BootstrapLaunchFormat constants censuses untouched (no new sprintf/emitter — if the step tempts a log line, refused); static-state leak across tests is MAJOR (test restores modes in tearDown, snapshot/restore per HomeSandbox-style discipline).

## W2.3 — Minimal `/generate` command with argv-style flags
**Goal:** t2i usable from the console input line BEFORE the popup: `/generate "a cat" --steps 20 --size 1024x1024 --seed 42 --save` → executes through the SAME GenerateImage path the model takes (one implementation, zero parallel dispatch), and lays the command row Wave 3's form will reuse as its door.
**Probe first:** W0.2 NoticesCommand trio (registry row `src/Commands/NoticesCommand.php`, dispatch entry `Chat::submit()` `:10685` region, handler `handleNoticesCommand()` `:12926` region) + `CommandSpec.php:18-36` props (name, description, category via `Lang::t('cmd.category.*')` — probe which categories exist; `argumentHint`; `paletteAction`/`paletteLabel` — palette row DEFERRED to W3.10, spec must allow adding later without rename); `READ_ONLY_COMMANDS` allowlist judgment `:11486-11491`; headless mode support (does `sugarcrush run -p "/notices …"` work — mirror whatever Notices does); W0.2 step 4 correction (NO builtin-commands dir).
**Files likely touched:**
- `sugar-crush/src/Commands/GenerateCommand.php` (new) — spec + argv-style parser (flag table: `--negative --steps --cfg --size WxH --seed --sampler --scheduler --batch --n-iter --save`); positionals = prompt text (joined).
- `sugar-crush/src/Chat.php` (edit) — dispatch arm `handleGenerateCommand()` in the submit chain beside notices (Chat.php serial: lane c owns it in W2).
- `sugar-crush/tests/Commands/GenerateCommandTest.php` (new) + durations row.
- README/COMMANDS roster rows → lane e staged.
**Lane:** c ; **Depends on:** W2.1 ; **Shared-file risk:** src/Chat.php (c in W2), README (e)
**Build steps (micro):**
1. Parser Fail-Fast: unknown flag, bad `--size` shape, non-numeric numeric → inline usage refusal (row output, no throw-up); `--size 1024x1024` splits to width+height.
2. Build the tool args array in EXACTLY the `inputSchema` names and invoke via the same internal tool-dispatch the backend uses (probe: how does Chat execute a built-in directly? DirectoryPicker/model flows have precedents — reuse one; if none exists clean, invoke the tool instance with the same permission-gate path so the ASK fires identically — honest per wave note).
3. `READ_ONLY_COMMANDS`: NOT added — comment at the allowlist records the judgment (egress+spend).
4. Open question seed: `/generate` with NO args today = usage line; Wave 3.1 flips it to open the form — mark the flip-site with a `// W3.1` comment.
**Tests (ship in-step):** behaviour (dispatch yields a GenerateImage invocation whose args map equals the expected DTO-shaped array — equality on arrays, not stringly-typed; headless `run -p` shape if supported), coercion (each refusal edge). Filter: `--filter 'Commands\\GenerateCommandTest'`.
**Definition of done:** keyboard-only full t2i flow works from the prompt line.
**Review brief:** KeyBindingDriftTest stays green (no chord yet); ReadmeRosterDriftTest pair will be red until e/gate — lane records its exact staged row text in the commit message body; mutation: accept unknown flag → coercion pin reds; confirm the flag table serializes to wire names (`n_iter` not `--n-iter` internal confusion) via the args-equality pin.

## W2.4 — Live-preview painting of `/progress current_image`
**Goal:** during generation, paint the server's interim preview frames (§4.7: `skip_current_image=false` yields `current_image` raw-b64 PNG per poll; TAESD-class cheap decode server-side) into the live tool row via the sanctioned ImageLayer path. **Honors mystage fact (g):** graphics protocols emit ONE opaque blob — a single in-place preview slot is legal (grid-of-one); NEVER tile/stitch blob frames; inline (cell) modes ride the normal diff.
**Probe first:** W0.2 lines: live row pipeline (`Chat::pumpLiveToolEvents()` `:5502` + `applyBackendToolEvent()` `:5149` + `src/ToolEventPumpMsg.php` payload-free wakeups), event union sites `EngineBackend::encodeEvent():5057`/`decodeEvent():5135` (existing members: ToolStarted|ToolFinished|SpendCapBreached|SubAgentActivity|ContextLedgerChanged — crush_media §9.2), `src/Events/` class roster shape (12 classes era), renderer live-tool region `Renderer.php:4632` + preview render reuse of `renderToolImage` machinery `:4923`-`:4985`, `ImageOverlay::signature()` re-paint skip + `coveredRows` protection (§8.4), and WHERE transient frame state may live (ruling: NOT Message/history — process-memory slot keyed by toolCallId on the view-model side; probe `Message.php:75` image fields to confirm the boundary).
**Files likely touched:**
- `sugar-crush/src/Media/PreviewPaint.php` (new) — b64 → `ImageSource::fromString` → render via current-mode Mosaic at a SMALL cell budget (≤24 cells wide or pane/4, whichever less — cheap frames must not cost full-image encode budget); BYPASSES the transcript `self::$imageCache` LRU (previews churn it; docblock the ruling, crush_media §8.6-4 cache key note); returns inline-or-blob pair per `isInline()`.
- `sugar-crush/src/Media/PreviewSlots.php` (new) — process-memory `toolCallId → latest preview payload` map, cleared at settle (single-slot replace law).
- `sugar-crush/src/Events/MediaProgress.php` (new) — event class per `src/Events/` sibling shape (frame b64, progress float, eta).
- `sugar-crush/src/Backend/EngineBackend.php` (edit, UNION LINES ONLY) — encode/decode arms for `media_progress`.
- `sugar-crush/src/Tools/BuiltIn/GenerateImage.php` (edit) — ProgressLoop `onPreviewFrame` → emit MediaProgress via the child's `$onEvent` queue. NOTE: same file as lane a's — **sequenced: lands AFTER a merges** (a→d order within wave).
- `sugar-crush/src/Renderer.php` (edit) — renderToolResults: running row consults PreviewSlots; blob frames go through `$images->place()` exactly like `:4965`.
- `sugar-crush/src/Chat.php` (edit) — `pumpLiveToolEvents`/`applyBackendToolEvent` handle MediaProgress → PreviewSlots write + repaint trigger (Chat serial conflict with c! — RESOLUTION: this step's Chat edit lands in a follow-up commit AFTER lane c's arm, same wave, sequenced by the orchestrator; or move the pump handling into a trait file `src/Tui/... `— ruling: keep the two Chat.php edits as two sequential commits, c first).
- `sugar-crush/tests/Media/PreviewPaintTest.php` (new) + durations row.
**Lane:** d ; **Depends on:** W1.5, W2.2 ; **Shared-file risk:** src/Renderer.php (d owns in W2), Chat.php (second-in-queue after c), GenerateImage.php (after a), EngineBackend (d only)
**Build steps (micro):**
1. `encodeEvent` arm name `'media_progress'`; decode side kind-read-BEFORE-payload guard (lane-de idiom from campaign precedent); strict-bool/int cast on decode (child→parent trust boundary).
2. Painting: inline mode → rows replace in-place (frame-diff friendly); blob mode → marker block + out-of-band bytes on the fresh ImageLayer (per-frame layer `:1682` already exists); `signature()` skips re-paint when unchanged (same frame twice = free).
3. Degrade honestly: if the backend never returns `current_image` (Q-2 unknown until W0.1), slots stay empty and behavior == Wave-2-without-this-step (pin a test that canned-no-preview renders identically to base frame).
4. Settle/quit clears PreviewSlots (leak law — no per-generation unbounded memory; bounded ring anyway).
**Tests (ship in-step):** snapshot-byte BOTH frame shapes per §10.3 (halfblock inline rows inside the diff-able frame; forced-kitty → marker block only, ZERO blob bytes in frame text — same discipline as existing graphics tests byte-assert); behaviour (slot replace-not-append; settle clears; no-preview polarity golden); candy-vt N/A for blob (raw bytes only). Filter: `--filter 'Media\\PreviewPaintTest'`.
**Definition of done:** live thumbnail refreshes during generation in every render mode, grid-corruption-free.
**Review brief:** grep settle path — preview bytes must NOT persist into Message/session store (privacy/bloat; MAJOR if found); mutation: append-instead-of-replace → single-slot pin reds; mutation: preview into imageCache → churn pin (if the pin only counts keys, add one: assert PreviewPaint never calls the LRU); W2.1's event-union sibling check (decodeEvent for an unknown-kind frame still refuses — regression).

## W2.5 — Wave-2 docs pass (serial docs owner)
**Goal:** land every Wave-2 roster truth with one author: README tool + command rows and `/generate` quickstart stub, docs/ENVIRONMENT (already staged in W1.8 — verify), docs/ARCHITECTURE `src/Media/` row, COMMANDS row for `/generate`; leave zero attributable drift-reds for the gate beyond the sanctioned suite-figure staleness pair.
**Probe first:** `sugar-crush/tools/gen-tool-docs.php` / `gen-command-docs.php` / `gen-settings-doc.php` — RUN WITHOUT `--write` FIRST to preview canonical output (mystage §6.1: they rewrite README + docs/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS rosters + counts); ReadmeRosterDriftTest re-derives from those generators (AGENTS.md docs-pinned-by-tests section) → the generators' output IS the truth; DocFigureProseDriftTest arms (do NOT introduce prose numerals; if a sentence must cite a live constant, add its arm in the same commit — campaign law).
**Files likely touched:** `sugar-crush/README.md`, `sugar-crush/docs/ARCHITECTURE.md`, `sugar-crush/docs/COMMANDS.md`, `sugar-crush/docs/PERMISSIONS.md` (Ask-class tool row — generator-driven), `sugar-crush/docs/SETTINGS.md`/`ENVIRONMENT.md` (verify W1.8 rows), `sugar-crush/docs/AGENTS_AUTHORING.md` if generator touches it.
**Lane:** e ; **Depends on:** W2.1–W2.4 committed ; **Shared-file risk:** IS the docs lane of W2
**Build steps (micro):**
1. Collect each lane's staged row text from commit-message bodies/REPORT files; apply generator-run previews; hand-write only the `/generate` quickstart (≤8 lines, no counts) + ARCHITECTURE media-module row.
2. Land as ONE commit; re-run generators a second time → diff must be EMPTY (idempotence proof, include in commit body).
3. Filter the drift families: `--filter 'ReadmeRosterDrift|EnvRosterDrift|TrustKeyDocumentationDrift|ConfigWriteProducerDocumentationDrift|KeyBindingDriftTest'` → green (except the suite-figure staleness pair if it lives in the same file — record exact expected-red count for the gate).
**Tests (ship in-step):** the drift guards ARE the tests (filter above).
**Definition of done:** `git grep` shows no TODO/stub markers left; generators idempotent on tree.
**Review brief:** reviewer re-runs the three generators with `--check`-equivalent (or diff-after-write on a scratch copy) — any non-empty residue = finding; check prose added anywhere for numerals without DocFigure arms.

---

# Wave 3 — The popup form

Goal: the A1111-grade popup. **Shape A per crush_media §7.4 (recommended v1): full-band App-owned modal following `Tui/Settings/SettingsEditor.php` exactly** — state slot, key gate, wheel routing, zone-click routing, render branch — NOT a Veil popup (Shape B stays for later quick-settings; mystage §4.3's composite order `clipRowsToCols → liftZonesUnderOverlay → padForOverlay → Veil composite → restoreZones → mark-AFTER` applies only if Shape B is ever chosen; recorded here so the task's mandated order isn't lost). Lanes: **u** = candy-forms widgets (own lib, fully concurrent with crush lanes), **a** = MediaForm shell (owns App.php/Chat.php/Tui/Renderer.php), **b** = field sections, **c** = dynamic scripts + advanced editor, **d** = presets + ui-config, **f** = keys/lang/palette (owns lang/en.php + docs rows).

## W3.1 — `MediaForm` modal shell (state slot, key gate, render arm)
**Goal:** App-level hosting: `?MediaForm` slot, open door, keys routed to it while up (SettingsEditor-identical exceptions), wheel scroll routing, zone prefixes, full-band render branch, and the **action-object return** idiom (DirectoryPickerAction precedent — "modal returns an action enum, host executes I/O", crush_media §10.2-6 canonical at `Chat.php:17603-17628`).
**Probe first:** W0.2 §7.4-table anchors (slot `App.php:327`, key gate `:2942` with its Ctrl+C/F10 exception list — READ, don't invent; wheel `:2344` (wheel→Up/Down into modal); zone click `:2796` `Mark::zone` hit-test; render branch `Tui/Renderer.php:566` + `renderSettingsEditor :847`; zone trio `SettingsEditor.php:75-77`); `SettingsFieldFactory` (how SettingsEditor builds candy-forms fields and drives them directly — crush_media §4.3 note "Field objects driven directly, Forms\Form used nowhere in crush" → WE are the first Form-in-crush consumer; decision: drive fields directly like SettingsEditor, use `Form::groups` ONLY if group-navigation earns its keep — probe SettingsEditor's navigation first, record verdict in code comment); `PaletteState.php:28` immutability shape; `KeyBindingRegistry` `CONTEXT_*` `:62-86` + `live():118` modal-context rule (§7.6).
**Files likely touched:**
- `sugar-crush/src/Tui/MediaForm/MediaForm.php` (new) — TEA model (`init()/update(Msg):[self,?Closure|Cmd]/view()/subscriptions()` per §10.2-2); holds field list (FieldSpec registry from W3.4 will plug in — define the `Section` interface + a Params placeholder section today), draft `MediaRequest`, zone prefixes `media:tab:` / `media:row:` / `media:help:` (SettingsEditor trio analog).
- `sugar-crush/src/Tui/MediaForm/MediaFormAction.php` (new) — `enum { case Cancel; case Generate; case SavePreset; case ImportPng; }` + payload carrier object (action + final request) — I/O-free.
- `sugar-crush/src/Tui/MediaForm/MediaFormState.php` (new) — open/focus-region bookkeeping, immutable `with*` (PaletteState precedent).
- `sugar-crush/src/App/App.php` (edit) — slot `private ?MediaForm $mediaForm = null;` beside settings `:327`; key gate mirroring `:2942`; wheel `:2344`; zone-click `:2796` with `media:` prefixes.
- `sugar-crush/src/Chat.php` (edit) — open doors (`/generate` bare-args flips the W2.3 `// W3.1` marker to open the form; a palette action lands in W3.10 with the marker f consumes), `update()` routing short-circuit while form up (BEFORE chat keys, mystage §6.2), action consumption (`Generate` → same execution path W2.3 used — one dispatch).
- `sugar-crush/src/Tui/Renderer.php` (edit) — full-band render branch beside renderSettingsEditor.
- `sugar-crush/tests/Tui/MediaForm/MediaFormKeysTest.php`, `MediaFormRenderTest.php` (new) + durations rows.
**Lane:** a ; **Depends on:** W2.3 ; **Shared-file risk:** App.php + Chat.php + Tui/Renderer.php (a owns all three in W3)
**Build steps (micro):**
1. Copy the SettingsEditor gate exactly (its exception list verified at probe); name symmetry: `renderMediaForm`, `mediaForm:` zone consts on the model like `SettingsEditor.php:75-77`.
2. `update()` short-circuit in Chat: if form present, route ALL keys except the gate-exceptions into the form model; Esc → Cancel action → slot cleared (Escape door precedent `:2975` region noted for behavioral comparison only — full-band modal, not Veil chain, so the Shape-B chain-order pins (KeyHelpTest) stay UNTOUCHED — verify by reading, record in test comment).
3. `view()`: bordered full-band panel (house chrome: `Style::border(Border::rounded()->withTitle(...))` per crush_media §4.3 modal-chrome idiom); rows ≤ cols width invariant (§10.2-4); tall content inside a `Viewport` (wheel already gated to Up/Down).
4. Generate action → Chat builds+dispatches GenerateImage with the hydrated request; form closes on submit (generation continues as a normal tool row — no form-held spinner).
**Tests (ship in-step):** behaviour (scripted KeyMsg per tea-snapshot taxonomy: open→Tab traverses→Esc cancels→slot cleared; Generate carries values; chat keys DO NOT leak while up — the mutation pin), snapshot-byte/golden initial frame at 100x40 (`UPDATE_GOLDENS=1` workflow) + a narrow 60-col variant stub (fills out in W3.4 as fields land). Filter: `--filter 'Tui\\MediaForm\\MediaForm(Keys|Render)Test'`.
**Definition of done:** modal opens/closes/steals-keys correctly and reaches the real tool; the four App seams mirror SettingsEditor line-for-line.
**Review brief:** (1) full-band-vs-overlay audit: confirm nothing in the Veil chain (`Renderer.php:2004-2020` painted==routed law) was touched and KeyHelpTest untouched; (2) mutation: drop the update() short-circuit → chat-key-leak pin reds; (3) zone marks are U+E000/E001 sentinel pairs (candy-mouse) — check marks emitted AFTER any future composite (N/A now but flag in code where W-shape-B would matter); (4) `tests/NoRawAnsiInTranscript`-family derived censuses: view-side painting only, no echo/emitters in Chat arms.

## W3.2 — candy-forms: mouse-drag Slider (lane u, own lib)
**Goal:** close widget gap #1 (crush_media §7.5 mystage §4.2.1): `Field\Slider::update()` never inspects MouseMsg. Recipe (mystage verbatim): mark the track as a zone; on Press/`ZoneDragMoveMsg` map `pos(msg)[0]/zone.width() → min + ratio*(max-min) → withValue()` (normalise already clamps+snaps).
**Probe first:** candy-forms live lines (`Field/Slider.php:111` new(), `:221` view()/normalise clamp+lattice-snap — READ its exact snap semantics; drag MUST call withValue() and let normalise own snapping, no second math); `ZoneDragMoveMsg` + `Zone\DragTracker` live locations (candy-zone emits drag msgs — mystage layer map; is `candy-zone`/`candy-mouse` in candy-forms' composer require? W0.2 checked — if absent: forms→mouse interface dep OR host-side plumbing; RULING: keep Slider dependency-free — add a PUBLIC method `handleZoneMouse(MouseMsg $m, int $zoneLeft, int $zoneWidth): ?self` that the HOST (crush) calls after its own zone hit-test; crush already runs candy-mouse; avoids a new forms dep entirely — record this as the Shape-A-vs-B of widget mouse support in the docblock); sugar-bits Tabs is the alternative drag-emitter but adds 2 requires (mystage §4.1 row) — rejected for this step.
**Files likely touched:**
- `candy-forms/src/Field/Slider.php` (edit) — `handleZoneMouse()` + MouseMsg branch in `update()` (harmless if no host marks zones).
- `candy-forms/tests/Field/SliderMouseTest.php` (new).
- `candy-forms/CALIBER_LEARNINGS.md` (edit — entry per lib convention).
**Lane:** u ; **Depends on:** W0.2 ; **Shared-file risk:** none inside sugar-crush
**Build steps (micro):**
1. ratio = clamp(($x - $zoneLeft) / max(1, $zoneWidth - 1), 0, 1) — endpoint-inclusive convention documented; value = min + ratio*(max-min) → `withValue()` (normalise owns snap/clamp — reuse, never duplicate).
2. Handle Press + DragMove + Release identically (no press-threshold subtlety in v1); ignore wheel here (Viewport routes it as Up/Down today — record divergence).
3. Keyboard path byte-identical (existing Slider tests untouched).
**Tests (ship in-step):** coercion (ratio edges x<0→min, ≥width→max, midpoints snap to the lattice — reuse normalise's step math as the oracle), behaviour (DragMove sequence updates monotonically; immutability: returns new instance). `cd candy-forms && vendor/bin/phpunit --filter 'Field\\SliderMouseTest'`; consumer regression at wave gate: `cd sugar-bits && vendor/bin/phpunit` + `cd sugar-prompt && vendor/bin/phpunit` targeted runs + crush `--filter 'Form|Input|Select|Cursor'` stay green (façade law: NO copied crush/bits tests for forms behaviour — ship only lib-local tests, campaign memory #1275/#1312/#1314).
**Definition of done:** crush can drag any param slider via the host hit-test.
**Review brief:** mutation: invert ratio (left=max) → drag pin reds; mutation: duplicate snap-math inside handler instead of delegating → lattice-consistency pin (keyboard-set vs drag-set same value) reds; check no new composer dep slipped in (path-repos guard + rationale docblock).

## W3.3 — candy-forms: bounded `NumberField` (+ droppable pill radio)
**Goal:** gap #2 (mystage §4.2.2): a bounded numeric box — SEED is int64 where a Slider is structurally wrong; A1111's seed widget is a Number precision-0 with 🎲/♻ buttons (§2.1). Optional same-lane: gap #4 horizontal radio-pill row (A1111 mask-mode radios) — **droppable**: sanctioned stand-in is `Select withHeight(3)` (crush_media §7.5 G-2 ruling).
**Probe first:** `Forms\TextInput\TextInput` live path (mystage: NumberField composes TextInput + Slider's normalisation); Slider normalise reuse surface from W3.2; Confirm/Select mechanics if pills ship; AGENTS.md coercion-test convention (clamp edge cases are the spec).
**Files likely touched:** `candy-forms/src/Field/NumberField.php` (new), `candy-forms/src/Field/RadioPills.php` (new, droppable), `candy-forms/tests/Field/NumberFieldTest.php` (+`RadioPillsTest.php`) (new), `candy-forms/CALIBER_LEARNINGS.md` (edit).
**Lane:** u ; **Depends on:** W3.2 ; **Shared-file risk:** none
**Build steps (micro):**
1. Model: draft string + committed value; `↑/↓ ±step`, `PgUp/PgDn ±10·step`, `Home/End → min/max`; commit on Enter/blur; invalid draft shows error state, value unchanged (XSet sentinel on "never set"); empty ≠ 0.
2. **int64 law: never route seed values through float** — a `99999999999999999` round-trip pin (PHP float would corrupt it; A1111 seeds are int64, textbox-seed mode `--use-textbox-seed` is exactly our use-case, §2.1 Seed row).
3. Locale-safe parse: accept canonical `-?\d+`/float forms only; thousands separators REFUSED with message, not guessed.
4. Pills (if landed): horizontal single-choice, keys ←/→ + click, focus ring via existing mechanics.
**Tests (ship in-step):** coercion matrix (boundaries, step lattice, int64 exactness, garbage refusals) + behaviour key table; pill variant ordering/stability if landed. Filter: `--filter 'Field\\(NumberField|RadioPills)Test'`.
**Definition of done:** seed/subseed fields land on a truthful widget.
**Review brief:** mutation: cast to float internally → int64 pin reds; DuplicatedTestHelperDrift name-scan on new test helpers (campaign law: harmonize or uniquely name shared helper bodies); consumers green as W3.2.

## W3.4 — Sections: Prompt + Generation params (core ~15 controls)
**Goal:** the first real field set in the form: prompt/negative Textareas (+history), Styles MultiSelect, width/height Sliders, steps Slider, CFG Slider, sampler+scheduler Selects, seed NumberField (+ randomize/reuse affordances = 🎲/♻ equivalents), batch count/size — **ranges/defaults EXACTLY per crush_media §2.1/§2.2 tables** (UI defaults: steps 20, cfg 7.0 grid 0.5 in 1–30, W/H 64–2048 step 8 default 512, batch_size 1–8, seed −1). **Proven-negatives rule (e):** TCD / regional seeds / per-axis weights / vary-region / diffuse-noise / Prompt-S-R-as-standalone-script are Forge/SD.Next concepts, NOT stock A1111 (mystage §3.1 sweep; wiki-verified) — they stay OUT of the core field set; the ONLY sanctioned home is user-added rows via W3.8 `/script-info` or W3.8's override-settings editor — record that sentence in the FieldSpec docblock header.
**Probe first:** W0.2 field lines (Text `:25` rows/wrap; history support live? Input/Text history — verify before promising), Select `withEnum():233`, MultiSelect `:92`, Note `:24`; `Form::hydrate():624`/`values():554` (mystage §4.3: hydrate = load-last-used entry, values = save entry); lang-key law `tui.genform.*` via `Lang::t` (crush_media §7.6; en.php = lane f serialization → **sequencing: lane f's key-block commit lands FIRST in W3 (f has no dep on b/c), then b/c/d use keys freely**); §2.9 sampler/scheduler rosters verbatim + pairing law + legacy `"DPM++ 2M Karras"` auto-split.
**Files likely touched:**
- `sugar-crush/src/Tui/MediaForm/FieldSpec.php` (new) — the declarative registry row: key, label(Lang id), widget factory, min/max/step/default (from §2 tables), DTO bridge (param path), infotext label (feeds W1.6 table), visibility rule. **One registry → form + request + infotext + preset (mirror-item 1, §5.8).**
- `sugar-crush/src/Tui/MediaForm/Sections/PromptSection.php`, `ParamsSection.php` (new) + `Enum/{Samplers,Schedulers}.php` (new — §2.9 rosters as enums for `withEnum`).
- `sugar-crush/src/Tui/MediaForm/MediaForm.php` (edit — lane a DONE before b edits it; same-lane sequencing rule noted).
- `sugar-crush/tests/Tui/MediaForm/MediaFormFieldsTest.php` (new) + durations row.
**Lane:** b ; **Depends on:** W3.1, W3.2, W3.3, W3.10-keys-first(f) ; **Shared-file risk:** MediaForm.php (a→b sequenced)
**Build steps (micro):**
1. Build the FieldSpec table row-per-§2.2-control for the v1 set; every Slider coerces at its table range (coercion tests = the table edges, §10.3 "the §2 tables are the edge-case spec").
2. Sampler/scheduler: enum defaults (hardcoded §2.9 lists) + runtime override when a client exists: `GET /samplers`/`/schedulers` shapes (§4.4) replace choice-sets at form-open (cached; failure keeps enums — fail-open); on Generate, if chosen sampler label ends with a scheduler name and scheduler unset → split (the `fix_p_invalid_sampler_and_scheduler` analog, §2.9 pairing note).
3. Seed row extras: 🎲 = set −1; ♻ = copy last-used seed (from `all_seeds[0]` of the last settled generation — probe where the last result's info persists today; if nothing, keep last-used on MediaForm draft state, documented).
4. Hydrate/value bridges through `Form`-equivalent: since W3.1 chose direct-field driving, implement `hydrateFields(array)`/`collectValues(): array` on MediaForm over the FieldSpec table (same entry points the §7.3 note promises from `Form::hydrate/values`).
5. Prompt/negative Textareas: rows 3, placeholder text = our OWN chord truth (C-2: do NOT ship the A1111 placeholder myth "Ctrl+Enter…" unless we BIND it — W3.10 binds Ctrl+Enter-submit? decision record C-4 in Appendix: v1 binds nothing in the form beyond Enter/Esc; placeholders neutral).
**Tests (ship in-step):** coercion matrix per control (each range/default/step from the table — DATA-PROVIDER driven from the FieldSpec itself so new specs auto-join), behaviour (hydrate→values round-trip stable; enum-fixture `/samplers` override replaces choices), snapshot-byte golden of the Params section. Filter: `--filter 'Tui\\MediaForm\\MediaFormFieldsTest'`.
**Definition of done:** the form drives a real generation; every v1 control's number matches the §2 tables exactly.
**Review brief:** control-by-control audit vs §2.1/§2.2 (range/default/step mismatch = finding); (e) check: grep the section dir for tcd|regional|per-axis|vary|diffuse → 0 hits (excluding the docblock ruling sentence which must use them in prose form the denial/glob censuses tolerate — space-bearing, no wildcard shapes); mutation: flip cfg step 0.5→1.0 → provider pin reds; DocFigure: FieldSpec ranges are CODE, not prose — no arms owed, but the golden frames must stay deterministic (fixed clock/seed).

## W3.5 — Seed disclosure + Hires accordion + Refiner + narrow fit
**Goal:** conditional groups: "Extra" disclosure (Variation seed/strength 0–1/.01 def 0, seed-resize-from 0–2048/8, §2.1+§2.3 slerp semantics in tooltips/docblocks), Hires accordion (all §2.1/§2.3 rows: upscaler dropdown (6 latent modes §2.8 verbatim + runtime `/upscalers`), hires steps 0–150 def 0, denoising 0–1/.01 **UI-def 0.7 (the context-dependent default: img2img 0.75 vs hires 0.7 — §2.2 non-conflict note)**, upscale-by 1–4/.05 def 2, resize-to W/H 0–2048/8 def 0, hidden-by-default reveal rows: alt checkpoint/sampler/scheduler + hires prompt/neg), Refiner accordion (checkpoint dropdown + switch-at 0.01–1/.01 def 0.8); PLUS the narrow-viewport fit pass (60×24 golden + clipping).
**Probe first:** `isHidden(values)` conditional-field mechanics live (crush_media §7.2 lists it as the exact mechanism for "Extra disclosure, hires sub-controls, inpaint-only" — verify candy-forms Form/Field supports conditional visibility TODAY; if partial, MediaForm implements visibility in its collect/render loop and the gap goes to candy-forms as a follow-up note); `Note.php:24` for the `txtimg_hr_finalres` computed row ("from WxH to WxH" preview analog — computed live via W3.6-owned HiresMath… careful: HiresMath lands HERE in b's section work, W3.6=scripts is lane c — HiresMath is a b-file); §2.4 two-pass math steps 2–4 for emission-order sanity; upscaler six latent-mode strings verbatim from §2.8.
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/Sections/SeedSection.php`, `HiresSection.php`, `RefinerSection.php`, `sugar-crush/src/Tui/MediaForm/HiresMath.php` (all new), `MediaForm.php` (edit, registration rows), `tests/Tui/MediaForm/SectionsTest.php`, `tests/Tui/MediaForm/HiresMathTest.php`, `MediaFormNarrowTest.php` (new) + durations rows.
**Lane:** b ; **Depends on:** W3.4 ; **Shared-file risk:** MediaForm.php (b self-sequenced)
**Build steps (micro):**
1. `HiresMath::target(w,h,?scale,?rx,?ry): [int,int]` — §2.4 rule 2 EXACTLY: both zero→floor(w·scale),h·scale… (floor per Features; document); exactly one→that dim + aspect-preserve; both→at-least dims (center-crop is server-side; client just sends); the `use_old_hires_behavior` legacy branch noted, not implemented.
2. Accordions: Confirm-as-toggle + visibility table (hidden children neither render nor collect); reveal-rows behind boolean settings (`hires_fix_show_sampler`/`_prompts` analog booleans in the FORM state, defaults false per §2.2).
3. Note row recomputes on any hires-relevant change (cheap: recompute in view()).
4. Narrow pass: at 60 cols labels stack above widgets; golden pair 100×40 + 60×24; every row ≤ cols (§10.2-4).
**Tests (ship in-step):** behaviour (toggle opens/closes; hidden values absent from collect; refiner off emits nothing), coercion+property (HiresMath branch table incl. aspect oddities 512×1024@1.7), snapshot-byte goldens (each accordion state; narrow frames). Filter: `--filter 'Tui\\MediaForm\\(Sections|HiresMath|MediaFormNarrow)Test'`.
**Definition of done:** §2.1 hires-block rows ALL live with exact numbers; disclosure mechanics proven for reuse in W3.7.
**Review brief:** field-name audit vs §4.2 API names (`hr_second_pass_steps`, `hr_scale`, `hr_checkpoint_name`, `refiner_switch_at`…); mutation: both-resize branch treated as single → HiresMath table reds; C-2 honesty: no invented keyboard chords referenced in help lines.

## W3.6 — Dynamic Script-args renderer (`/script-info`)
**Goal:** render A1111 script knobs GENERICALLY from `GET /sdapi/v1/script-info` — `{name, is_alwayson, is_img2img, args:[{label,value,minimum,maximum,step,choices}]}` (crush_media §4.4: "the endpoint a form-generator client scrapes to build popups dynamically"; §2.6 "Render dynamically from GET /script-info — do not hardcode"); serialize BOTH wire encodings (§4.5): selectable → `script_name` + positional `script_args` slice; alwayson → `alwayson_scripts: {"<exact title>": {"args":[…]}}` (unknown title / selectable-in-alwayson ⇒ server 422 — we emit only fetched titles).
**Probe first:** W1.3 client has `scriptInfo()`; §5.1/§5.2 splice math (args_from..args_to flat-list — CLIENT only emits per-script ordered lists; positional flat-list concatenation is the server's job under `script_args` when ONE selectable script is chosen — its args array maps 1:1, order = declared); W3.4 FieldSpec machinery to project arg-descriptors onto (slider-descriptor→Slider with min/max/step, choices→Select, plain→NumberField or Input by value-type); `is_img2img` filter (img2img-only scripts hidden while mode=image-to-txt… mode gating completes W4; today: render all, disable none, note).
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/ScriptFields.php` (new), `Sections/ScriptsSection.php` (new), `MediaForm.php` (edit — registration), `tests/Tui/MediaForm/ScriptFieldsTest.php` (new) + `tests/fixtures/sd/script-info.json` (new — shape copied from the clone's `modules/api/api.py`+`modules/scripts.py` serialization, reviewed) + durations row.
**Lane:** c ; **Depends on:** W3.4 ; **Shared-file risk:** MediaForm.php (b finished it by now — sequence c-after-b for the registration line; single-line add, low conflict, still ordered)
**Build steps (micro):**
1. Descriptor→widget mapper as a `match` on present keys (minimum+maximum+step ⇒ Slider; choices ⇒ Select; else ⇒ Number-if-numeric-value else Input) — driven ONLY by machine keys, never by label-guessing.
2. Selectable dropdown "None" first; per-selected-script arg group re-renders from ITS descriptors (dropdown order = server order, §5.2).
3. Alwayson: each its own accordion (A1111 InputAccordion analog) titled verbatim `name`; values default = descriptor `value`.
4. Serialization step in collect: `script_args` = selected script's values in order; `alwayson_scripts` = map name→{args} for every EXPANDED alwayson (collapsed-but-default ones: include anyway with defaults? — server semantics differ: pin the choice = INCLUDE all alwayson scripts with current values, mirrors how A1111's hidden inputs always post, §5.2 "their components always feed script_args"; docblock the ruling).
5. No client/fetched-data ⇒ section = single Note "no backend connected" (fail-open, empty script area, §W1.2 doctrine).
**Tests (ship in-step):** behaviour (fixture script-info → widget count/types == args descriptors; two selectable scripts never coexist in script_args; alwayson map keys == exact titles), coercion (descriptor with both choices+minimum → Select wins per documented priority; empty args list script renders header-only), golden frame of one alwayson accordion open. Filter: `--filter 'Tui\\MediaForm\\ScriptFieldsTest'`.
**Definition of done:** a ControlNet-class panel would render today with zero crush code change (the §5.10 promise testable via fixture).
**Review brief:** grep the section dir for hardcoded script titles (`grep -ri 'ControlNet\|loopback\|deforum' src/Tui/MediaForm/`) → only docblock mentions allowed (and those dodge denial/glob census shapes); mutation: shuffle descriptor order → positional pin reds; verify 422-avoidance: titles sent come from the SAME fetch that built the widgets (no hand-typed titles anywhere).

## W3.7 — img2img/Inpaint sections present-but-GATED
**Goal:** lay the §2.6/§2.7 field groups into the form NOW (mode selector, resize-mode, denoising 0–1/.01 UI-def 0.75, mask blur 0–64 def 4, mask mode, masked content (C-1: exactly `fill|original|latent noise|latent nothing`, UI default "original"), inpaint area whole/only-masked, padding 0–256/4 UI-def 32, mask transparency NOTE as editor-only (§2.6 row: never reaches processing), image-CFG 0–3/.05 def 1.5 hidden-unless-edit-model) behind a disabled gate so W4 ships BEHAVIOR only, no layout. Tab-strip decision lands here too: **probe-first A/B**: crush-local `SettingsTabStrip` static-render clone (§7.2 row, `SettingsTabStrip.php:19`) vs sugar-bits interactive `Tabs` (requires +candy-zone per mystage §4.1 row — heavier, new deps). **Ruling for v1: crush-local static strip** (mode tabs are 2: Image / Video(W5) + section accordions inside; interactive tab machinery unnecessary) — recorded in Appendix C decision slot.
**Probe first:** §2.6 full table (elem semantics for labels/help copy); §4.3 DTO names (`resize_mode`, `mask_blur`, `inpainting_fill`, `inpaint_full_res`, `inpaint_full_res_padding`, `inpainting_mask_invert`, `mask`, `init_images` — W1.1 already owns the fields; this step only WIDGETS them); `SettingsTabStrip` render signature; visibility rules already proven in W3.5.
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/Sections/ImageSection.php`, `InpaintSection.php`, `sugar-crush/src/Tui/MediaForm/FormTabs.php` (static strip clone) (new), `MediaForm.php` (edit — tab strip swap-in + gated section registration), `tests/Tui/MediaForm/ImageSectionGateTest.php` (new) + durations row.
**Lane:** b ; **Depends on:** W3.5 ; **Shared-file risk:** MediaForm.php (b→c→b sequence; registration lines only)
**Build steps (micro):**
1. FieldSpec rows for §2.6 controls with W3.4's table (ranges/defaults EXACT; C-1 four-option list verbatim).
2. Gate: `Wave4Gate::enabled()` const/flag — when off, both sections render as ONE Note row "img2img & inpaint arrive in a later build"; when on, full rows. Default off.
3. `resize_mode` radio(4) labels verbatim §2.7 ("Just resize / Crop and resize / Resize and fill / Just resize (latent upscale)"); masked-content/mask-mode/inpaint-area rows carry the §2.7 semantics sentences as help text (Lang keys → f's block, staged).
4. FormTabs: static strip painting the section headers + Up/Down/PageUp jump-to-section (keyboard nav, no mouse-tabs); zone marks `media:tab:` land here for later mouse-tabs.
**Tests (ship in-step):** coercion (every §2.6/§2.7 range/step/default pair again data-provided from FieldSpec), gate polarity (off → zero editable widgets in those sections AND their values absent from collect — pin), snapshot-byte tab strip states. Filter: `--filter 'Tui\\MediaForm\\ImageSectionGateTest'`.
**Definition of done:** form SHAPE complete; W4.1/4.3 flip one flag + behavior code.
**Review brief:** C-1 audit: exactly four masked-content options, labels verbatim (a reintroduced 5-way split = the adjudicated transcription error = CRITICAL); mutation: gate-off collect leaks hidden values → pin reds; tab labels' Lang keys all exist in en.php (f's block — f lands before b's section commits).

## W3.8 — Advanced: `override_settings` typed key-value editor
**Goal:** the §4.2 `override_settings` dict (any core/extension opts key per-request, server auto-restores §5.5) with a typed row editor — gap #3 (mystage §4.2.3 "key-value/parameter rows… nothing exists; SettingsEditor is bespoke prior art"); crush_media §6.4 suggests an "Advanced JSON textarea" — THIS plan ships the better shape: rows {key, type, value} with JSON escape-hatch row. Every §2.10/§2.11 tunable (eta/s_churn/s_tmin/s_tmax/s_noise/sigma_min/sigma_max/rho/ENSD/uni_pc_*(C-4: order 1–50)/sd_noise_schedule/beta_dist_*/s_min_uncond/CLIP_stop_at_last_layers/face_restoration/tiling…) becomes reachable with ZERO bespoke widgets.
**Probe first:** `GET /options` shape (§4.4: whole OptionsModel — key→value map from which we infer types at suggestion time; fetched lazily, cached like W3.4's samplers); SettingsEditor's editing-field splice (`?Field $editing` focus pattern, crush_media §4.3) as the in-row editor UX; `ui-config` NOT here (that's W3.9 — distinct concept: overrides edit REQUEST-scoped opts, ui-config edits form DISPLAY).
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/OverrideRows.php` (new — model: ordered rows {key,type ∈ int|float|bool|string|json, value}, validate-per-type, toDict/fromDict), `Sections/AdvancedSection.php` (new), `MediaForm.php` (edit), `tests/Tui/MediaForm/OverrideRowsTest.php` (new) + durations row.
**Lane:** c ; **Depends on:** W3.6 ; **Shared-file risk:** MediaForm.php (sequence after W3.6 line)
**Build steps (micro):**
1. Key completion from cached `/options` labels when a client exists; free-typed keys allowed regardless (extensions' keys are exactly the point, §5.5).
2. Type inference default from the options fixture (int/float/bool/string); `json` type = raw passthrough with `json_decode` validate-on-commit (Fail Fast, loud row error, no silent drop).
3. Row add/remove/reorder; `override_settings_restore_afterwards` Confirm row (default true, §4.2).
4. Round-trip: toDict→fromDict stable; empty editor emits NO key (not `{}` — DTO unset law).
**Tests (ship in-step):** coercion (per-type parses + refusals incl. bool-vs-"0" trap, json escape hatch accepts `{"a":[1]}` rejects `{"a":`), behaviour (add/remove/commit flow; round-trip golden). Filter: `--filter 'Tui\\MediaForm\\OverrideRowsTest'`.
**Definition of done:** §5.8 mirror-item "every setting reachable without code" satisfied request-side.
**Review brief:** mutation: bool row parses `"false"` as truthy string → pin reds; C-4 row reachable: assert fixture round-trip carries `uni_pc_order: 50` untouched (no local clamp applied to override keys — a local clamp would contradict the server's own validation = finding).

## W3.9 — Presets + `ui-config.json` display-override analog + save-pattern row
**Goal:** mystage §3.4 features landed: (1) named preset save/load/apply (crush_media §5.10: "our TUI should ship preset save/load/apply in v1"; §9.7 PresetRegistry sketch) persisted via `sd.presets`; (2) **ui-config sidecar analog**: per-field display overrides `value|minimum|maximum|step|visible` keyed `"<section>/<Label>/<prop>"` (A1111 elem-id convention §5.5 verbatim example `txt2img/CFG Scale/step`) persisted via `ui.mediaDisplayOverrides` — users retune ranges/defaults with no code (§5.8 mirror-item 4); (3) Output group row exposing `sd.savePattern` (W1.8 key) with the token palette help line.
**Probe first:** `SettingsWriter` API for Map-typed values (W1.8 rows are opaque maps — confirm writer accepts nested arrays + ConfigWriteProducer drift already satisfied); `Form::hydrate/values` bridges from W3.4 as the preset payload; FieldSpec materialization point (where DisplayOverrides injects: at section BUILD, transforming a FieldSpec copy).
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/MediaPresets.php` (new), `sugar-crush/src/Tui/MediaForm/DisplayOverrides.php` (new), `Sections/PresetsSection.php`, `Sections/OutputSection.php` (new), `MediaForm.php` (edit), `tests/Tui/MediaForm/PresetsTest.php`, `DisplayOverridesTest.php` (new) + durations rows.
**Lane:** d ; **Depends on:** W3.4, W1.8 ; **Shared-file risk:** MediaForm.php (sequence last of b/c/d registration lines — orchestrator orders a→b→c→d for the shared line)
**Build steps (micro):**
1. Preset = named FieldSpec-value bundle + `presetVersion: 1` stamp; save (Input-name + current values → settings map), apply (hydrate), delete; MultiSelect-of-names list with `[x]` toggles per house style.
2. **Secret scrub law (pin): on save, drop any override row whose key matches `/api|key|token|secret|password/i`** — presets/settings must never carry inline secrets (`${VAR}` channel stays the only sanctioned one, mystage §6.3).
3. DisplayOverrides::apply(FieldSpec[], map) → transformed specs: ranges move, defaults pre-fill, `visible:false` drops the row from render AND collect (gate semantics like W3.5 hidden — reuse that machinery); unknown/stale keys silently ignored (A1111 tolerance parity, §5.5).
4. Last-used persistence: on Generate, stash current values into `sd.presets["<last>"]`? NO — separate key `ui.mediaLastUsed` would need a W1.8 row; ruling: last-used lives in the session draft only (MemoryBlock-style process state); recorded as deferred (Appendix C note) to avoid settings-write spam.
**Tests (ship in-step):** behaviour (save→apply→values equal; override map moves a Slider's clamp + hides a row from collect; stale override key no-op), coercion (scrub catches `CLIP_skip_api_key`-style fakes and `sd_apiKey`; malformed preset JSON → loud refusal, list unchanged after failed save — atomicity of settings write). Filter: `--filter 'Tui\\MediaForm\\(Presets|DisplayOverrides)Test'`.
**Definition of done:** §5.8 items 1+4 realized; zero-code knob retuning live.
**Review brief:** mutation: disable scrub → secret lands in settings pin reds; ConfigWriteProducerDocumentationDriftTest re-run green (rows pre-existed W1.8); mutation: hidden-by-override row still collects → pin reds; DuplicatedTestHelperDrift name-scan across new test files' helpers.

## W3.10 — Key binding + `tui.genform.*` lang block + palette rows
**Goal:** discoverability: one open-form chord, palette entry, full locale keys — with their drift rosters IN-STEP (crush_media §7.6 mandatory-guard list). **Lands FIRST within W3 (no dep on b/c/d)** so every section commit uses real Lang keys from day one.
**Probe first:** unused Ctrl rune census: `KeyBindingRegistry` `chatCtrlRunes()`/existing binds (mystage §6.2 "check chatCtrlRunes()"; candidate `Ctrl+G` — verify free; campaign precedent says palette owns Ctrl+P, Esc shared); `KeyBindingDriftTest` mechanism (re-derives registry→docs; registry row + KEYS doc row in same commit = green); `CommandSpec` `paletteAction`/`paletteLabel` props (crush_media §7.6 `:171-224`) + `PaletteAction` enum cases (probe live enum path `src/Palette/PaletteAction.php`); lang file `sugar-crush/lang/en.php` (`tui.<area>.<name>` convention, §7.2; also `cmd.generate.description` + a media category if absent — probe `cmd.category.*` set); Chat.php palette-dispatch markers left by W3.1 (`// W3.10`).
**Files likely touched:** `sugar-crush/lang/en.php` (edit — f owns it in W3; full `tui.genform.*` + `cmd.generate.*` + help-text keys used by W3.4–W3.9 are ALL seeded here as the block), `sugar-crush/src/Config/KeyBindingRegistry.php` (edit — chord + `CONTEXT_*` media case per §7.6 `:62-86`/`live():118`), `sugar-crush/src/Palette/PaletteAction.php` (edit — one case), `sugar-crush/src/Chat.php` (edit — the marker'd palette arm; Chat serial: sequenced AFTER lane a's W3.1 commit), `sugar-crush/docs/KEYS…` (live filename probed; row), README key table (staged → gate/e).
**Lane:** f ; **Depends on:** W3.1 (markers) ; **Shared-file risk:** lang/en.php, Chat.php (queue position: after a), docs (f rows staged to e/gate)
**Build steps (micro):**
1. Enumerate every string W3.4–W3.9 will need (walk the FieldSpec/§2 tables' labels + help sentences + gate notes) → one en.php block; keys stable/canonical (later steps MUST NOT add en-only stragglers — if one slips, that step edits en.php = serialization violation; orchestrator re-sequences).
2. Registry: one binding `media.open_form` (Ctrl+G or probe-chosen), context gating so it no-ops while OTHER modals up (gate-precedence audit vs settings/palette bindings — read their contexts, don't guess).
3. Palette: `OpenMediaForm` action + label + `runPaletteAction` arm → same open path as `/generate` bare.
4. KEYS doc + registry row same commit; `--filter 'KeyBindingDriftTest'` green in-step.
**Tests (ship in-step):** behaviour (chord opens form via full App→Chat→view loop — SettingsEditor chord-test idiom; palette action opens; chord dead while settings modal up), roster trio (KeyBindingDrift + Help/KEYS docs + golden frame showing the new help line — if the help line is chrome, its golden flips here, pin it). Filter: `--filter 'KeyBindingDriftTest|Tui\\MediaForm\\MediaFormKeysTest'`.
**Definition of done:** keyboard-first users reach the form; no literal user-facing strings anywhere in W3 code (grep `Lang::t` coverage: every new section file references zero raw label strings — spot-audit test with a source scan; if a scan is cheap ship it, else reviewer greps).
**Review brief:** the drift trio (KeyBinding/Help/Readme) green at this commit is the proof; mutation: delete one lang key → rendered golden shows the raw `tui.genform.…` key (pin asserts no `tui.` substring in golden frames — cheap and vicious, ship it); check chord didn't shadow an existing bind (registry conflict test).

---

# Wave 4 — img2img / inpaint + import

Goal: source-image ingress, mask upload, PNG-info round-trips, batch+grid+save completeness. Lanes: **a** = image ingress (owns Chat.php + FilePicker plumbing this wave, `Wave4Gate` flip), **b** = mask ingest (edits ImageSection AFTER a), **c** = PNG round-trips (`/sendto`, ImportPng; Chat edit sequenced after a), **d** = batch/grid/save (owns GenerateImage.php edits). Files per step; `s` = deferred sketch lane (W4.6).

## W4.1 — FilePicker → `init_images` b64 + ungate img2img
**Goal:** pick a local file in the img2img section → validate/decode → base64 into `init_images` (multi = batch, §4.3 "multi-item = batch"); flip `Wave4Gate` for the img2img half. Decode/validate via `ImageSource::fromFile` (`candy-mosaic/src/ImageSource.php:138`, ceilings `MAX_PIXELS=50M`/`MAX_BYTES=64MiB` `:29,:38`); a crush-local encode-of-loaded for normalization is NOT needed v1 (send source bytes as-is; server resizes per resize_mode).
**Probe first:** `Field/FilePicker.php:37` + crush's `Tui/DirectoryPicker/DirectoryPicker.php:43` (`open():70` consumes-as-action precedent — action-object idiom §10.2-6); §4.3 note: server-side URL fetch is gated by `api_enable_requests`/`api_forbid_local_requests` — RULING: we NEVER send URLs, only local-file b64 (no server-side fetch dependency; docblock why); `init_images` 404-if-null behavior (client-side required-ness: img2img mode with zero images → inline refusal, not a server 404).
**Files likely touched:** `sugar-crush/src/Media/ImageIngress.php` (new — path→{b64,w,h,bytes,sha1}, ceiling guards, decode-fail loud), `Sections/ImageSection.php` (edit — picker row live; a owns it THIS step, b edits after), `sugar-crush/src/Tui/MediaForm/MediaForm.php` (edit — PickImage action round-trip), `sugar-crush/src/Chat.php` (edit — DirectoryPicker host execution for the form door; a owns Chat in W4), `sugar-crush/tests/Media/ImageIngressTest.php` (new) + `tests/fixtures/img/` tiny png/jpg/gif (new) + durations row.
**Lane:** a ; **Depends on:** W3.7 ; **Shared-file risk:** Chat.php, ImageSection.php (a first)
**Build steps (micro):**
1. Model emits `MediaFormAction::PickImage` → host opens DirectoryPicker → chosen path returns into form state (no fs in model).
2. ImageIngress validation: signature sniff (PNG/JPEG/GIF via existing `ImageSource` format detection — do NOT re-sniff), `getimagesizefromstring`-class dims, ceilings.
3. Collect serializes b64 (raw, no data-URI — A1111 accepts both; raw is the convention, §1.3 mystage table row).
4. Ungate: `Wave4Gate::img2img = true`.
**Tests (ship in-step):** coercion (each fixture format OK; oversize→refusal message; corrupt→refusal), behaviour (path→request init_images[0] === base64_encode(file bytes) EXACT; multi-pick order preserved). Filter: `--filter 'Media\\ImageIngressTest'`.
**Definition of done:** pure-form img2img generation works.
**Review brief:** mutation: reorder multi-init → order pin reds; mutation: send URL passthrough → the never-URLs test reds; confirm gate flip didn't enable INPAINT rows prematurely (b owns that half — polarity split in gate).

## W4.2 — Clipboard image ingress
**Goal:** paste a screenshot straight into the init-image slot — image INGRESS already exists (§9.4 row: `src/ClipboardImagePastedMsg.php`, `src/AttachmentType.php:12-13` File|Image). While the form is up with img2img section focused, paste feeds W4.1's ImageIngress.
**Probe first:** who currently produces `ClipboardImagePastedMsg` (grep live: paste path in InputReader/candy-core marked frames — find the crush consumer today, likely draft-attachment) and the routing point in Chat's paste handler; the temp-lifetime law (mystage §6.5: ToolOutputSpill/ClipboardImage temp dirs carry a 7-day sweep) → **copy bytes into MediaStore IMMEDIATELY on receipt**; never reference the volatile temp path in state.
**Files likely touched:** `sugar-crush/src/Chat.php` (edit — route paste msg into form when up), `sugar-crush/src/Tui/MediaForm/MediaForm.php` (edit — `withClipboardImage(bytes)` handling → ImageIngress → slot), `sugar-crush/tests/Tui/MediaForm/MediaFormClipboardTest.php` (new) + durations row.
**Lane:** a ; **Depends on:** W4.1 ; **Shared-file risk:** Chat.php (a continues)
**Build steps (micro):**
1. On paste while form up: store→MediaStore put(ext 'png', token source=clipboard) → slot path; non-image clipboard text → falls through to chat behavior (form ignores) — pin both polarities.
2. Copy-before-use ordering documented (the volatile-temp hazard is why, cite mystage §6.5).
**Tests (ship in-step):** behaviour (scripted ClipboardImagePastedMsg → slot filled + store file exists + bytes equal; form-down paste unchanged regression pin). Filter: `--filter 'Tui\\MediaForm\\MediaFormClipboardTest'`.
**Definition of done:** "print-screen → paste → generate" works.
**Review brief:** verify no path-but-no-copy shortcut (grep the handler for direct temp-path assignment = finding); mutation: paste while form down → chat pin keeps existing behavior green proves routing gate.

## W4.3 — Mask upload v1 (B&W white=mask + transparent-PNG alpha rule)
**Goal:** mask = file upload with the §2.7 conventions: plain B/W PNG where **white = inpaint** (`processing.py:344`: PIL convert("L")→/255, bright=mask) OR transparent PNG where **any even slightly transparent area becomes mask** (mystage §2.4 Inpaint-upload row). Serialize the four §4.3 mask fields from W3.7 rows; ungate inpaint half.
**Probe first:** §2.7 semantics: `mask_blur` (UI single slider; API has `mask_blur_x/y` — send `mask_blur`, x/y live in override escape hatch), `mask_round` default True param trap (§2.7: blur survives only when round off — we don't expose round; docblock the trap so nobody "fixes" blur as ineffective), `inpaint_full_res` bool from the radio (not int — §4.3 types), `inpainting_mask_invert` int 0/1 from mask-mode radio; fixture creation tools (ext-gd available — generate fixtures in-test then freeze tiny committed copies).
**Files likely touched:** `sugar-crush/src/Media/MaskIngest.php` (new — file→{b64, mode ∈ alpha|luminance, dims}, classification per above, loud refuse neither), `Sections/ImageSection.php` (edit — mask row live + inpaint rows ungate), `tests/Media/MaskIngestTest.php` (new) + `tests/fixtures/img/mask-{alpha,luma,neither}.png` tiny (new) + durations row.
**Lane:** b ; **Depends on:** W4.1 (sequence: a merged first) ; **Shared-file risk:** ImageSection.php (a→b ordered)
**Build steps (micro):**
1. Classification: decodes with alpha channel having <255 anywhere → alpha-mode; else luminance-mode (warn if FULLY uniform — "mask covers nothing/everything" advisory row, refuse only on zero-variance-everything-255… actually both extremes are legal server-side (empty mask = no-op) — advisory NOT refusal; ruling documented).
2. b64 raw; field map exact §4.3 names/types (bool vs int care on `inpaint_full_res`).
3. Gate flip: inpaint half on.
**Tests (ship in-step):** coercion (3 fixture polarities + garbage refusal; alpha=1-vs-255 edge — a 1-step transparent corner MUST classify alpha), behaviour (form state → exact request field map incl. C-1 0–3 passthrough untouched). Filter: `--filter 'Media\\MaskIngestTest'`.
**Definition of done:** inpaint works with externally-authored masks (Q-5 v1 answer: upload suffices; editor deferred W4.6).
**Review brief:** the "slightly transparent" edge: fixture with a single alpha=254 pixel classifies alpha-mode (mutation: threshold ≥128 → red); §2.6 mask-transparency row never serialized (editor-only — MAJOR if found in payload).

## W4.4 — PNG-info import + paste-params + send-to round-trips
**Goal:** the §3.6/§2.14 contract: (1) **Import PNG** — local `Png::readText` first, server `POST /png-info` fallback when a client exists (§4.4 shape `{info, items, parameters}`), parse (W1.6) → `applyTo(onlyUnset)` hydrate; (2) **Paste params** — infotext string → same local channel (the API's `infotext` field is the server-side twin §4.2 — v1 resolves locally so hydration works offline; docblock the divergence, expose the API field in Advanced if users want server-side apply); (3) **Send-to** — from a result, push artifact + parsed params INTO the form pre-hydrated (`/sendto <artifact-or-path> [--to img2img|inpaint]`; txt2img→img2img sets init_images=[artifact]; img2img→txt2img drops images, keeps params — §2.18 button-set semantics minus the web gallery).
**Probe first:** W1.6/1.7 APIs' live signatures; where generated artifacts' infotext is recallable post-generation (MediaStore sidecar? — v1: re-read the PNG chunk (W1.7) — self-describing files are the whole point §3.5 "API consumers and PNG files carry identical metadata by construction", no sidecar needed — pin that claim by reading our OWN stamped file back); `CommandRegistry` row pattern (W2.3) for `/sendto`; zone/menu affordance on image rows TODAY likely none — command form v1, menu later (note).
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/ImportPng.php` (new), `sugar-crush/src/Commands/SendToCommand.php` (new), `sugar-crush/src/Chat.php` (edit — sendto arm + form import action consumption; sequenced LAST in W4 on Chat: a→c), `MediaForm.php` (edit — import hydration entry), `tests/Media/ImportPngTest.php`, `tests/Commands/SendToCommandTest.php` (new) + durations rows.
**Lane:** c ; **Depends on:** W1.6, W1.7, W4.1 (artifacts on disk to send) ; **Shared-file risk:** Chat.php (queue after a)
**Build steps (micro):**
1. Import: path → isPng → readText('parameters') → parse → applyTo(form draft, onlyUnset=true) → open form hydrated; absent chunk → server pngInfo fallback → same; neither → refusal row (PNG Info tab is read-only upstream — we refuse loudly too, §2.14).
2. `/sendto` modes: img2img/inpaint set init_images(+mask when --mask given); params from the file's own chunk.
3. **Round-trip law test:** generation → stamped file → import → emit == original infotext byte-identity (the §3.6 verbatim-reload directive; golden).
4. Legacy key spellings ride W1.6's parse-tolerant table (styles included, §2.14 PasteField fills styles — parse keeps `Style`/styles keys into DTO).
**Tests (ship in-step):** behaviour (fixture-stamped file → field map == golden array; sendto lands draft; server-fallback path with fake transport), snapshot-byte round-trip. Filter: `--filter '(Media\\ImportPng|Commands\\SendToCommand)Test'`.
**Definition of done:** generated PNGs and foreign A1111 PNGs both re-load into the form verbatim.
**Review brief:** reviewer takes the mystage §1.4 sample (or a clone-produced real string) through parse+applyTo personally; mutation: onlyUnset law broken (import clobbers a user-edited field) → pin reds; mutation: style key dropped in mapping → styles pin reds.

## W4.5 — Batch count/size + grid prepend + save-to-disk completion
**Goal:** `n_iter × batch_size` end-to-end: per-image seed tokens from `all_seeds` (§2.3 incremental-seed law: server hands the list; i-th artifact ↔ i-th seed), grid-at-index-0 handling (W1.4 parsed; here: surfaced + stored as first-class file named `…-grid.png`… token pattern keeps `[number]` uniqueness), save-to-disk completion (`save_to_disk:true` → ALSO send `save_images:true` so the server archives per its own `outdir_*`/pattern (§5.7 saving row) — dual-save client+server documented, results list both), txt sidecar analog when `sd.savePattern`/settings opt `sd.saveTxt`? — v1: NO txt sidecar (deferred, Appendix C-3 autosave territory).
**Probe first:** W1.4 Response (all_seeds/all_subseeds keys present in info per §1.3 mystage list), W1.9 put() token fill + collision increment; §4.2 `do_not_save_*` overwrite quirk (never send them); generation info persistence today for `all_seeds` (settled result content carries our printed paths — ensure the RAW info JSON survives into the result text (it must, web phase §6.4 DTO law) or into MediaStore-adjacent JSON? ruling: ToolResult content text includes the compact per-artifact infotext already; all_seeds ride `MediaArtifact->tokenValues()` at put-time, nothing extra persisted — verified in test).
**Files likely touched:** `sugar-crush/src/Media/BatchResults.php` (new — Response → artifacts+grid split with token maps), `sugar-crush/src/Tools/BuiltIn/GenerateImage.php` (edit — route through BatchResults; d owns this file in W4), `tests/Media/BatchResultsTest.php` (new) + durations row.
**Lane:** d ; **Depends on:** W1.4, W1.9, W2.1 ; **Shared-file risk:** GenerateImage.php (d only in W4)
**Build steps (micro):**
1. Token fill per artifact: seed=all_seeds[i], steps/cfg/sampler/model from request echo; grid artifact gets `[generation_number]`-flavored suffix `-grid`.
2. Display: imageBytes = first non-grid sample (grids can exceed ceilings cheaply — imageRows clamp already protects, §8.6-4); content lists every path incl. grid.
3. Dual-save honest reporting: server paths are NOT enumerated back (§4.2 save_images returns nothing new) — result text says "saved to server store (outdir) + crush media store".
**Tests (ship in-step):** behaviour (fixture 2×3 response → 6 samples + grid-at-0 consumed right; seed↔artifact pairing exact table; missing all_seeds → base-seed fallback documented), coercion (pattern overflow 128 cap still unique via [number]). Filter: `--filter 'Media\\BatchResultsTest'`.
**Definition of done:** batch generations land complete, correctly named, both stores.
**Review brief:** mutation: off-by-one seed pairing → pairing pin reds; mutation: grid treated as sample (7 artifacts) → count pin reds; verify byte-equality of client-stored vs bytes-received (no re-encode that would drop the server's own `parameters` chunk BEFORE our stamp — ordering: read server chunk, ours REPLACES per W1.7 replace-policy; §3.5 "existing chunks preserved" applies to OTHER keys).

## W4.6 (SKETCH — deferred stretch lane) — TUI brush mask editor
**Goal:** explicit open-question step (Q-5): in-terminal brush over the init image — canvas overlay, brush size, invert, save-as-mask feeding W4.3. **Scheduled ONLY on operator ruling** (Appendix C-2); otherwise lands marked DESCOPED with the ruling.
**Probe first:** (execute ONLY if scheduled — see Goal) painting primitives: painting primitives: `ImageOverlay` marker arena (`MAX_IMAGES=6398`, U+E002+ §8.4) is for IMAGES not per-cell editing — v1 canvas = **half-block/truecolor cell canvas** (inline mode forced while editing, §8.2; graphics protocols structurally unsuitable: single blob, no per-cell identity — cite mystage fact (g) MosaicFactory law); drag machinery from W3.2's zone-drag recipe; `Viewport` pan/zoom (`pageUp/Down :375-408`, wheel); cell→pixel downscale math must live in `src/Media/MaskRaster.php` (new, pure) for testability.
**Files likely touched:** (create ONLY if scheduled — see Goal) `sugar-crush/src/Tui/MediaForm/MaskCanvas.php` (new TEA model), `sugar-crush/src/Media/MaskRaster.php` (new pure raster), `Sections/ImageSection.php` (edit — "Draw mask" row), `tests/Tui/MediaForm/MaskCanvasTest.php` + `tests/Media/MaskRasterTest.php` (new) + durations rows.
**Lane:** s ; **Depends on:** W4.3 ; **Shared-file risk:** ImageSection.php (schedule alone in a sub-wave after b merges)
**Build steps (micro):**
1. MaskRaster: bitmap (w×h bool) ↔ downscale/upscale to canvas cells (majority-rule down, nearest up — documented, lossy by design at cell level; final save upscales brush strokes back to image res with a feather option = pixel-level blur server-side via mask_blur instead, honest note).
2. Canvas: paint rect/erase toggle, brush size 1–8 cells, `i` invert, Enter → raster→luma PNG (white=brushed) via W1.7 `encodeGd` → MaskIngest path; Esc cancel.
3. Un-gate the "Draw" affordance behind same `Wave4Gate::sketch`.
**Tests (ship in-step):** coercion (cell→pixel map tables both directions, majority-rule edge 50/50), behaviour (stroke → save bytes decode back → expected mask polarity + coverage; round-trip into request mask field exact), golden canvas frame. Filter: `--filter 'Tui\\MediaForm\\MaskCanvasTest|Media\\MaskRasterTest'`.
**Definition of done:** mask authored without leaving crush, OR step carries the DESCOPED stamp + operator ruling quote.
**Review brief:** if descoped: verify docs never promised an editor (W4.3's doc sentence = "external editor round-trip" stays true); if shipped: child-canvas leaks none (no children), width invariants at every tested size.

---

# Wave 5 — Video

Goal: LTX/Wan-class video: async job client, GenerateVideo tool, in-terminal playback with zero child leaks. Lanes: **a** = require-bump + job client + tool (owns composer.json, Chat.php, README/docs rows for the tool), **b** = VideoPlayerScreen (+ child-lifetime roster + App/Tui/Renderer seams), **c** = video form tab, **d** = audio probe/decision. **Fact (f) governs the whole wave: base64 MP4 has NO in-tree ingestion path — write to a temp file (MediaStore) FIRST, then hand the PATH to sugar-reel** (mystage §5.4 row 4 verbatim: "no lib accepts it — write to temp file (or loopback-serve) first, then Reel::open").

## W5.1 — sugar-reel require-bump (path-repo-closure law)
**Goal:** declare the sibling dependency the correct way: `require` line ONLY.
**Probe first:** `sugar-crush/composer.json` current require block (candy-mosaic `:55` era lines per W0.2); sugar-reel's own composer requires (candy-core/buffer/palette/mosaic/flip — crush_media §8.3 — all already crush deps except candy-flip: verify `candy-flip` resolution: mosaic already requires it → transitive OK, no direct require unless we use flip classes directly — we don't (reel does)); path-repo-closure skill + `php tools/check-path-repos.php --no-lib-path-repos` (AGENTS.md: never commit a per-lib `repositories[]` block — CI injects).
**Files likely touched:** `sugar-crush/composer.json` (edit — `"sugarcraft/sugar-reel": "@dev"` line only), root `composer.json` (probe: does the root monorepo manifest need the dep line too — AGENTS.md says root keeps its own require+repositories — verify live), `sugar-crush/README.md` deps mention? (staged → gate docs).
**Lane:** a ; **Depends on:** W0.4 baseline ; **Shared-file risk:** composer.json (a owns; W1.8 didn't touch it — true first dep edit of the project), README (a→docs queue)
**Build steps (micro):**
1. Add require line; `php tools/check-path-repos.php --no-lib-path-repos` → 0.
2. `php scripts/refresh-deps.php --mode=linked` (linked wiring for local build) then confirm autoload resolves `SugarCraft\Reel\Player` (one smoke assertion inside a new trivial test to make CI prove it — see Tests).
3. Verify ffmpeg-family availability messaging only at runtime use (Source/Probe `which()` pattern §8.3 — not at boot).
**Tests (ship in-step):** `sugar-crush/tests/Media/ReelDependencyTest.php` (new): class_exists assertions for `Player`, `Render\Mode`, `RendererFactory` + durations row. Filter: `--filter 'Media\\ReelDependencyTest'`.
**Definition of done:** crush builds with reel linked; no repositories[] anywhere in a lib manifest.
**Review brief:** the check-path-repos gate is the proof; mutation: add a repositories[] block → tool rc≠0 (reviewer MAY try on scratch copy only, never the tree).

## W5.2 — Video job client (submit/status/fetch polling)
**Goal:** the async job transport for minutes-long renders: generalize A1111 `force_task_id` + `/progress` into **submit → status poll → fetch**, contract per W0.1/W0.3 (video is NOT A1111-standard; the client speaks whatever the live server proved, else the config-declared `EndpointFamily` default with fixture-tested shapes). **Base64-mp4 rule (f) enforced HERE: the fetch path writes bytes to MediaStore temp-file-first; nothing downstream ever sees a b64 string.**
**Probe first:** W1.2 MediaJob, W1.3 Client/transport, W1.9 MediaStore; crush_media §7 job-semantics paragraph; mystage §7 bullets (frame count/fps fields live in REQUEST — client passes params opaquely; response = mp4 path/URL/base64 — handle all three ingestion shapes per §5.4 map rows 1–4, with row 4 = decode→MediaStore.put); heartbeat law applies to the POLL loop (fact (a): beat per status poll via ProgressLoop-shaped `JobPollLoop` — reuse or subclass W1.5's loop with the status-endpoint swapped; probe whether extracting a shared loop is cleaner than a sibling class — ruling: sibling `JobPollLoop` with a `ProgressSource` interface refactor IF the two share ≥80% body — do it in THIS step, keep ProgressLoop tests green).
**Files likely touched:** `sugar-crush/src/Media/Video/JobClient.php` (new — submit(params): taskId; status(taskId): JobSnapshot{state,progress,eta,resultRef}; fetch(resultRef): artifact-path via MediaStore-first rule), `sugar-crush/src/Media/Sd/ProgressSource.php` (new interface — only IF the 80% test passes; then ProgressLoop.php (edit) implements it), `tests/Media/Video/JobClientTest.php` (+ `tests/fixtures/video/jobs-*.json` canned shapes) (new) + durations row.
**Lane:** a ; **Depends on:** W1.2–W1.5, W1.9, W0.1 result (shapes) ; **Shared-file risk:** none beyond lane-a chain
**Build steps (micro):**
1. Result shapes: `{videoPath}` server-local path → we still FETCH via configured download route (no path trust) or treat as remote-unfetchable with honest message; `{url}` → guarded same-origin fetch (UrlGuard); `{b64}` → `base64_decode` strict → MediaStore.put('mp4') → path back.
2. State mapping into MediaJob enum transitions; failed carries server message.
3. Cancel: job-level cancel ENDPOINT if the dialect has one (W0.1 records), else A1111 `/interrupt`-family fallback + honest "server-global interrupt" docblock warning (co-tenant hazard §4.6 shared state).
4. Poll cadence 1 s default (video frames cost seconds; 0.5 s image cadence wrong here), still heartbeat-per-poll, still no wall-clock bound (E646), cancel-aware.
**Tests (ship in-step):** behaviour (canned sequences: queued→running→done-b64 → artifact file exists + is valid-signature mp4 stub bytes; url; path-unfetchable honest error; cancel between polls), coercion (b64 strict: whitespace/newline-laden payload refused not lenient-decoded — PHP `base64_decode($s, true)`), heartbeat count == polls pin (shared-loop refactor keeps W1.5 tests green). Filter: `--filter 'Media\\Video\\JobClientTest|Media\\Sd\\ProgressLoopTest'`.
**Definition of done:** a minutes-long job survives the idle watchdog and lands a file on disk.
**Review brief:** the refactor-if-80% rule — reviewer measures duplication honestly (two divergent loops with drift bugs = finding); mutation: feed b64 straight to Player path without MediaStore (hold bytes in memory + tmpfs-free) → pin reds per (f); cancel endpoint absence documented not silently ignored.

## W5.3 — `GenerateVideo` tool (position 34)
**Goal:** model-callable + `/generate --video` tool: `#[BuiltInTool(name:'GenerateVideo', permission: Ask, position: 34)]` (pair with W2.1's 33; **re-verify the taken set from the attributes tree at build time** — same W0.2 micro-step command — before assigning; bump BOTH if collided), `AcceptsHeartbeat`, result = content text + artifact path + `🎞` notice row; **inline playback NOT here — W5.4's screen owns viewing** (a tool result cannot host a Player; §5.2 hazard).
**Probe first:** W2.1 tool anatomy (live), mystage fact (b) `new \stdClass()` again for schema empties; §6.2/§6.5/§2.6-video field set for v1: `prompt`, `negative_prompt`?, `num_frames` (snap-to-8k+1 ladder coercion: Wan 81/121, LTX 121/193/257 — client coalesces to nearest VALID ≥ requested with an informational line; the "snap slider to the valid ladder" law §6.2), `fps` (Wan 16/24, LTX 24–30 typical), `width/height` (480p/720p class dropdown values), `steps`, `cfg`/`cfg2` (dual-CFG when dialect has it), `seed`, `motion` (0–1 mapped per model mechanism, honest docblock §6.2), `first_frame_image` path (i2v v1 scope = first-frame only — §6.2 role selector deferred: none/first/first+last/reference/video-init recorded backlog), `duration_seconds` sugar (→ frames via fps); media capability check `mediaKinds∋video`; `ToolResult` for video: NO imageBytes (video is not an image — transcript shows a path row + 🎞; opening = W5.4 command/key).
**Files likely touched:** `sugar-crush/src/Tools/BuiltIn/GenerateVideo.php` (new), `sugar-crush/src/Media/Video/FrameLadder.php` (new — ladder snap pure fn), `tests/Tools/GenerateVideoTest.php` (new) + durations row; roster rows staged → a's docs edit.
**Lane:** a ; **Depends on:** W5.1, W5.2, W1.8 ; **Shared-file risk:** Chat.php? (no — tool dispatch is automatic via catalog; ONLY if a `/generate --video` flag route needed: extend W2.3's GenerateCommand — that's Chat.php + GenerateCommand.php: sequencing: a owns GenerateCommand edit this step, fine — Chat.php untouched this step)
**Build steps (micro):**
1. FrameLadder::snap(int $requested, EndpointFamily $fam): int — per-family stride law (8k+1; Wan classes 81/121; LTX 121/193/257) with the informational-diff return.
2. Tool body mirrors W2.1 structure: capability (video), JobClient submit + JobPollLoop(heartbeat, cancel) + fetch; per-artifact sidecar: infotext-style `emit` for video = JSON sidecar written next to file via MediaStore (`<name>.json` — Q-11 lean: JSON sidecar + mkv-comment stretch; sidecar v1, decision C-6 in Appendix).
3. `🎞` row wording pinned distinct from refusal wording (campaign denial-vocabulary census law); content lists path + duration + fps + ladder-snap note.
4. Schema (b): every empty object position `new \stdClass()`; test json_encode contains `{}`.
**Tests (ship in-step):** behaviour (canned job: happy path lands file + sidecar JSON readable + result text contains path; cancel mid-poll → interrupted result; ladder: 100→121 for LTX with info line), coercion (schema `{}` pin; fps 0 refused). Filter: `--filter 'Tools\\GenerateVideoTest|Media\\Video\\FrameLadderTest'` (FrameLadderTest new file too + durations row).
**Definition of done:** model + user can produce an mp4 artifact; nothing tries to paint it yet.
**Review brief:** position 33/34 adjacency vs W0.2 live set (collision = CRITICAL); sidecar contains NO secret fields (grep for key-like tokens = finding); mutation: skip sidecar → pin reds; mutation: heartbeat-per-status-poll removed → idle-bound pin reds (same fixture pattern as W2.1).

## W5.4 — `VideoPlayerScreen` in crush
**Goal:** playback surface. **Hazard law (§5.2 / mystage Player note): `Player::view()` paints graphics-mode blobs INLINE in its view string — that corrupts crush's line-diffed chat frame. Choose (A) a pushed FULL-SCREEN screen using phlix's absolute-cursor `graphicsChrome` pattern, or (B) frames routed through `ImageLayer::placeTracked()` + Veil.** **Ruling: A** (phlix `PlayerScreen.php` blueprint — crush_media §8.5 "adopt wholesale as crush video-pane blueprint"; B re-implements Player internals). Mechanics: opened by `/play <artifact>` command or a transcript affordance later; Player as INNER model wrapped by a crush TEA screen (from phlix: `productionFactory(mode, cellSize)` → `Player::open(...)`; `Cmd::promise` probe+spawn off the sync path behind a "Preparing…" frame → `PlayerReadyMsg`; **Teardownable: `$player->stop()` on Esc AND global Ctrl-C** — ffmpeg/ffplay children MUST die (repo gate `tools/check-child-lifetimes.php`)); key override-then-forward table; mode + cellSize from W2.2 machinery (reel's OWN auto-ladder — video-first sixel>kitty>iterm2>truecolor-halfblock>ansi256>ascii, `RendererFactory::autoMode()`, fact (g) — and crush setting applies via `Mode::tryFrom ?? autoMode` per phlix :294 pattern).
**Probe first:** phlix-console-client files live (crush_media §8.5 table — path `src/Screen/PlayerScreen.php` at that repo; verify it exists on disk: `/home/sites/phlix-console-client` UNKNOWN — probe; if absent locally, the blueprint is crush_media §8.5's detailed prose + sugar-reel's own examples/ player Program (sugar-reel README/demo scripts — probe `sugar-reel/examples/`)); sugar-reel Player API live lines (`Player.php:170` open, `:402` update, `:871` view, `:1631` subscriptions, `frameAt:1385`, seek `:1244/:1337`); `Teardownable` interface location in candy-core (probe); how crush pushes full-screen views today (Settings full-band is nearest kin; or docked pane? ruling: full-band App view like settings, keys gated identically to W3.1 pattern; `App.php` slot `?VideoPlayer`); `subscriptions()` tick ownership (§10.2/loop discipline — Player drives its own ReelTickMsg — crush subscriptions machinery must forward Tick: probe `subscriptions():17919` Chat vs App-level screens).
**Files likely touched:** `sugar-crush/src/Tui/VideoPlayer/VideoPlayerScreen.php` (new — TEA wrapper), `…/VideoPlayerKeys.php` (new — override-then-forward table), `sugar-crush/src/App/App.php` (edit — slot + gate, pattern-copy from settings/mediaForm gates — App serial: lane a owns App.php this wave? NO — W3.1 lane a was wave 3; W5 lanes a/b: App.php = **b owns** this wave; Chat.php = a owns (command arms)), `sugar-crush/src/Commands/PlayCommand.php` (new) + Chat.php dispatch arm (a's file — sequence b-after-a for Chat? Chat arm is a's; App render branch is b's — disjoint files, only App.php is b-sole), `sugar-crush/tests/Tui/VideoPlayer/VideoPlayerScreenTest.php` (+ `tests/Commands/PlayCommandTest.php` a's) (new) + durations rows.
**Lane:** b (player) + a (command, sequenced inside lane a chain) ; **Depends on:** W5.3 (artifacts exist to play), W5.1 ; **Shared-file risk:** App.php + Tui/Renderer.php (b), Chat.php (a)
**Build steps (micro):**
1. Open flow: `/play <path-or-id>` → validate path under MediaStore root OR explicit user-typed file (PathJail: refuse traversal-y guesses with a clear message; arbitrary absolute paths allowed only because the USER typed the command — Ask-class not applicable to a slash command by house convention — record the permission reasoning in docblock; server-mode parity later W6).
2. Build player off-sync: `Cmd::promise`-style probe+factory (`VideoSource::probe` may hang on dead ffmpeg → promise/fork channel; without pcntl, degraded sync build behind a "Preparing…" frame is acceptable ONE-time cost — document vs §10.1-1).
3. Graphics chrome: absolute-cursor bottom-pinned control row (`Ansi::cursorTo+eraseLine` — because the blob is ONE logical multi-row line; `\n` chrome lands on row 3 — phlix named bug, crush_media §8.5 row 3 verbatim; the Tui/Renderer arm must render the screen as full-band like renderSettingsEditor, chrome INSIDE the screen's own view, and for graphics modes the screen view = blob + appended chrome via cursor ops, tested byte-level).
4. Keys: Space pause, ←/→ seek, m cycle mode (Player's own), q/Esc stop+pop, Ctrl+C global stop (Teardownable wiring: App-level quit path calls screen `stop()` BEFORE loop exit — the child-leak gate is watching).
5. Scrubber line lift (phlix `src/Ui/Scrubber.php` 94-line glyph timeline — glyph-only, NO_COLOR-safe; port to `src/Tui/VideoPlayer/Scrubber.php`, its tests ported too, uniquely-renamed helpers vs phlix… phlix tests are NOT in-tree consumers — copy + adapt, DuplicatedTestHelperDrift name-scan against crush suite).
**Tests (ship in-step):** behaviour (open→keys→close emits correct Player msgs via a stub Player factory seam — Player ctor seam: `fromDecoder:267`/`openForTest:352` EXIST (§8.3) — use `openForTest` for a scripted-frame player, no real ffmpeg); coercion (`/play` unknown-id refusal, traversal refusal); snapshot-byte: halfblock-mode 4-row scripted clip renders deterministic frame (inline mode → cell-grid OK); graphics-mode chrome pinned as BYTES (candy-vt can't — raw assert, existing Iterm2RendererTest pattern, §5.1 note); **child-accounting**: scripted test must leave zero proc_open children (candy-pty SharedLoopResidue-style check if applicable, else rely on W5.5 roster + gate tool). Filter: `--filter 'Tui\\VideoPlayer\\VideoPlayerScreenTest'`.
**Definition of done:** `/play` shows real motion in-terminal in ≥1 mode with zero leaked children; Esc/Ctrl-C both clean.
**Review brief:** the two exit paths (Esc AND SIGINT-ish quit) EACH must reach `stop()` — reviewer traces App quit funnel vs key gate (missing teardown = CRITICAL, it's the named §8.3 hazard); graphics byte-test actually asserts cursor ops (mutation: chrome via `\n` → byte pin reds proving the blob-row law pinned); PathJail prose honest (`/play /etc/passwd` → what happens? ruling: user-typed absolute paths allowed for PLAYBACK (read-only av process), the Ask gate never runs — reviewer confirms no write/egress on this path, read-only decode OK); Scrubber port doesn't collide with existing helper names.

## W5.5 — Child-lifetime roster + CI gate wiring
**Goal:** register the new child producers with the repo-wide fail-closed auditor: `tools/check-child-lifetimes.php` walks `proc_open` sites — sugar-reel's `FfmpegDecoder`/`AudioPlayer` spawn children; when crush BECOMES a consumer, the auditor's findings set may change (it counts findings across autoload roots — crush deps' src included? W0.4 baseline recorded its exact finding-count at HEAD; this step re-runs and reconciles: new UNACCOUNTED sites (if the tool scans vendor-linked siblings) require ACCOUNTED roster rows mirroring the dash/reel precedent (memory: reel `Support\BoundedReaper` per-package + roster rows "3 mirror-sugar-crush + 3 E366-mitigated").
**Probe first:** W0.4 baseline finding count + roster file live shape (`tools/check-child-lifetimes.php` ACCOUNTED list); whether crush's new code itself calls proc_open (it should NOT — reel owns children; if the VideoPlayer degraded sync-build spawns via reel in-process, accounting lands on reel's side per its existing rows — confirm reel rows already cover `FfmpegDecoder` (they do per memory: sugar-reel E366 lane) ; confirm nothing in crush src adds a raw `proc_open`/`pcntl_fork`).
**Files likely touched:** `tools/check-child-lifetimes.php` (edit — roster rows IF needed), possibly `sugar-crush/tests/...` child-accounting pin (new, only if roster changed), `CALIBER_LEARNINGS.md` root or crush note.
**Lane:** b ; **Depends on:** W5.4 ; **Shared-file risk:** tools/ (b sole)
**Build steps (micro):**
1. Run `php tools/check-child-lifetimes.php`; diff findings vs baseline; zero new findings ⇒ NO-OP stamp step (honest closure); else add rows (file + reason + mitigation cite) and a pin test per the tool's own test companion (`tools/tests` — ChildLifetimesToolTest era; note: tools/tests runs via `candy-core/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration tools/tests` per campaign precedent — brief the fixer the exact command).
**Tests (ship in-step):** the tool gate itself (rc=0) + any roster-pin test update.
**Definition of done:** auditor green at Wave-5 tip; ffmpeg/ffplay child ownership documented to the exact file.
**Review brief:** reviewer re-runs the tool fresh; a crush-src `proc_open` appearing with no roster row = CRITICAL (the gate's whole purpose); grep for stray `exec(`/`shell_exec(` in new crush media code too (the tool walks proc_open specifically — the family audit is on reviewer).

## W5.6 — i2v / t2v form tab (Wan2.2 / LTX params)
**Goal:** the video tab lands in the popup: §6.2/§6.5 rows as fields with the **ladder snap surfaced live** (Note row: "121 frames @24fps ≈ 5.0s"), frames (ladder NumberField), fps (Select), resolution class dropdown, steps, CFG (+CFG2 when dialect declares dual), motion slider (0–1 → per-model mapping docblock), first-frame image slot (reuse W4.1 ingress, single-image mode), seed row reused VERBATIM (§6.2 law: A1111 seed semantics unchanged), prompt/negative shared top row; t2v vs i2v = first-frame presence (mapping table §6.5 row 1: "single tab, optional first-frame slot").
**Probe first:** FieldSpec reuse; `EndpointFamily`-conditional visibility (cfg2/stg/etc. only when capability declares support — W1.2 descriptor carries bounds/support flags; W3.5 disclosure machinery); frame-ladder per-family tables from §6.2 (W5.3 FrameLadder is the SSOT — form consumes it, never re-encodes numbers); proven-negatives rule (e) applies here too — no per-axis-weights/regional fields invented for video either; STG/teacache/distilled-variant knobs = Advanced-only via overrides or a later declared-capability surface (v1: NOT first-class rows; descoped honestly, Appendix B marks them).
**Files likely touched:** `sugar-crush/src/Tui/MediaForm/Sections/VideoSection.php` (new), `MediaForm.php` (edit — tab registration via W3.7 FormTabs), `GenerateVideo.php` (edit — accept the form-shaped params bundle, a→c? sequence: c-edits a's tool file AFTER a merges W5.3), `tests/Tui/MediaForm/VideoSectionTest.php` (new) + durations row.
**Lane:** c ; **Depends on:** W5.3, W3.7 (tabs), W4.1 (image slot) ; **Shared-file risk:** MediaForm.php (sequence c last), GenerateVideo.php (a→c ordered)
**Build steps (micro):**
1. FieldSpec rows with §6.2 numbers; frames FieldSpec's NumberField gains a `snap:` hint hook (ladder-aware display note, raw value editable — coercion vs snapping split: collect sends snapped value + info line, draft keeps user's raw with warning row; document choice: SEND SNAPPED).
2. Conditional rows via capability flags (W1.2): cfg2, motion supported?, first+last (v1 hidden — declare-false).
3. Generate action: mode=video routes to W5.3 execution path (job client), NOT the image path — dispatch on a `MediaKind` the form carries (Image|Video tab).
**Tests (ship in-step):** coercion (§6.2 table ranges/ladders incl. 8k+1 snap-send behavior both directions of advice), behaviour (capability-hidden rows; Generate-video action → JobClient fake receives right shape), golden video-tab frame. Filter: `--filter 'Tui\\MediaForm\\VideoSectionTest'`.
**Definition of done:** the popup fully drives image AND video.
**Review brief:** numbers audit vs §6.2/§6.5 tables; mutation: send RAW frames=100 for LTX → snap-into-request pin reds; mutation: cfg2 shown without capability → visibility pin reds.

## W5.7 — Render-mode ladder asymmetry UX + force-mode honesty
**Goal:** make the two ladders visible/honest in one place: crush's `ui.imageRenderMode` governs IMAGE painting; the Player uses **reel's** video ladder by default with `m`-cycling + optional explicit start-mode (phlix `productionFactory` `Mode::tryFrom ?? autoMode` pattern); `/rendermode <mode>` quick-set command (crush_media §11.2 phase-4 line adopted: setting write via existing SettingsWriter path + ApplyMode note — image modes apply NEXT generation/repaint (W2.2 static memo is boot-keyed; a live repaint flip needs a reset call — add `ToolResult::resetRenderMode()` seam used by the command; honest docblock: graphics terminals may need `/clear`-style cleanup of stale blobs — Player's `Renderer::delete(imageId)`-class cleanup noted, mosaic `Renderer::delete` §8.2 row).
**Probe first:** W2.2 memo design (reset seam cost one static unset), SettingsWriter command precedent (`/settings` existing command path — find live command that writes settings — likely `SettingsCommand`/App seam: grep `SettingsWriter` consumers), reel `Mode` enum exact labels (`Mode.php:15-36` — `ascii|ansi256|truecolor|halfblock|quarterblock|sixel|kitty|iterm2` + `rowsPerCell/colsPerCell/isGraphics/label`).
**Files likely touched:** `sugar-crush/src/Commands/RenderModeCommand.php` (new), `sugar-crush/src/ToolResult.php` (edit — reset seam, 1 method), `sugar-crush/src/Chat.php` (edit — arm; queue after W5.6's Chat edits? W5 Chat owner = a (PlayCommand)… **rule this wave: Chat.php edits ALL queue in one lane (a), b/c/d stage arm text** — or simpler: RenderModeCommand belongs to lane **a** too. Updated: lane a owns /generate-video-flags? no — lane a chain = GenerateCommand extension + PlayCommand + RenderModeCommand + GenerateVideo; that's heavy — acceptable, steps sequence a-chain internally; the concurrency doc says so explicitly.), README/COMMANDS rows staged, `tests/Commands/RenderModeCommandTest.php` (new) + durations row.
**Lane:** a ; **Depends on:** W2.2, W5.4 (m-key exists to compare) ; **Shared-file risk:** Chat.php (a), README (a-staged→gate)
**Build steps (micro):**
1. `/rendermode` (no arg → prints current + allowed set + ladder explanation line for images; arg → validate (fromModeString/auto), write setting, reset memo, repaint-hint message).
2. `/rendermode video` sub-arg? NO — video start-mode is the player's own `m` cycling + a `video.renderStartMode` setting? Keep minimal: setting applies to images; player mode via `m` only; documented asymmetry (grids/tiled views force cell renderers where added later — MosaicFactory law pointer comment, nothing tiling exists yet).
**Tests (ship in-step):** behaviour (set→get round-trip through settings; invalid refused with allowed set; reset seam actually re-probes — pin via mode-flip returning different protocol instance), coercion table. Filter: `--filter 'Commands\\RenderModeCommandTest'`.
**Definition of done:** user-switchable render mode is a one-command experience, asymmetry documented where it lives.
**Review brief:** verify the setting-write path is the SAME validated writer (no side-channel config.json write — ConfigWriteProducer drift + the writer-refusal reuse); doc claims about `m` cycling match W5.4 table.

## W5.8 — Audio probe decision (ffplay child vs silent-mvp)
**Goal:** operator/product decision MADE AND RECORDED: does crush video playback produce SOUND? Options: (A) silent mvp (Player already silent-capable: AudioPlayer is opt-in, "silent no-op when neither installed" §8.3), (B) wire `AudioPlayer` (ffplay/mpv subprocess; stdio→/dev/null FILES not pipes §5.3 note; child-lifetime accounting lands in W5.5 roster). Recommendation: **(A) v1 silent, (B) gated behind a `sd.videoAudio` setting default-off, landed only if the wave schedule allows** — the decision record (Appendix C) closes Q-11-adjacent audio scope.
**Probe first:** sugar-reel AudioPlayer API live (`AudioPlayer.php:15-104`: ffplay `-nodisp -autoexit`/mpv `--no-video`, injectable clock, credential redaction row mystage §5.2), Player's audio hook (probe `Player.php` for an attach point or none → integration cost drives decision).
**Files likely touched:** NONE if (A) chosen — step = decision writeup + a doc note line (staged to gate) + Appendix C. If (B): `VideoPlayerScreen.php` (edit), a setting row, roster rows, new teardown wiring, new tests.
**Lane:** d ; **Depends on:** W5.4 ; **Shared-file risk:** none under (A)
**Build steps (micro):**
1. Measure (B)'s cost honestly (child teardown paths ×2, roster, tests) — document ≤10 lines either way in `/tmp/opencode/plan-crush-media/w5/audio/REPORT.md`.
2. Record ruling in Appendix C; (A) → open a Wave-7 backlog line.
**Tests (ship in-step):** none under (A); under (B): Player-audio lifecycle pins incl. stop-on-both-exit-paths (same double-teardown audit as W5.4).
**Definition of done:** the question is closed on paper with a cost figure, no silent-scope drift.
**Review brief:** (A) accepted ⇒ reviewer checks NO audio spawn exists anywhere in crush video path (grep ffplay in sugar-crush/src → 0).

---

# Wave 6 — Server / web parity

Goal: images/video cross the WebSocket. **All web paths verified in-tree 2026-10-08** (Appendix E has the discovery record). Stack reality: `sugar-crush-web` is Vite + Vue 3 + TypeScript + Pinia + vue-router; unit tests = vitest 5 + `@vue/test-utils` + happy-dom colocated in `src-web/**/__tests__/*.spec.ts`; e2e = Playwright 1.63 in `e2e/*.spec.ts` (login.spec.ts, session.spec.ts, reconnect.spec.ts, settings.spec.ts, panels.spec.ts, approvals via approvals component specs colocated, multi-client/-session, dir-picker, highlight, fixtures/ dir); the web repo ALSO has a PHP side (`src/Assets.php`, `phpunit.xml`, `tests/`) serving built `dist/`. Transport contract: `sugarcrush serve` → WS `GET /ws` subprotocol `sugarcrush.v1` → JSON-RPC 2.0 `Protocol/Dispatcher` (crush_media §9.5) — `src/Protocol/Methods/` holds 15 domain files (AgentsMethods…WorkspaceMethods, verified); events flow `EventType::CATALOGUE` (`src/Protocol/EventType.php:47`, tuples `[durable, scope, description]`, accessors `:91-114`) → `EventEnvelope` (`:30-36` fields, durable journaling) → `SessionFeed` (`:58,203,660-665`) → `src-web/protocol/generated.ts` (1,489 ln, generated) typed via `Events`/`EVENT_KINDS` maps.

**Decision the wave implements (record Appendix C-7):** `tool.finished` gains a `media: list<MediaRef>` field (NOT a separate `media.ready` event) — MediaRef = `{artifactId, kind, path, bytes, mime, infotextDigest?, indexLabel?}`; artifact BYTES never ride the event journal (durable replay would bloat it) — the browser fetches bytes via a new **RPC `media.fetch` with offset/limit slicing, mirroring `tool.output`** (`src/Protocol/Methods/ToolMethods.php:30-57` — `$registry->add(MethodSpec::new('tool.output', Scope::Read, 'A finished tool call's full output, from an offset.', self::output(...)))`, slice at `:52`); the guarded HTTP binary route stays a stretch. Rationale anchors: crush_media §10.1-4 (single job/event source of truth; event data carries ARTIFACT REFS "path/URL + infotext dict, never terminal-encoded bytes"), §6.4/§11.2 web phase; mystage §6.4 both options listed — we pick tool.finished-field because projector already assembles the filtered dict (`TranscriptProjector.php:219-246` verified: `array_filter` list carries toolCallId/name/isError/durationMs/content/truncated/diff/denial/identity/replaces — **media joins that list, and its ABSENCE today is the confirmed drop**).

## W6.1 — PHP: MediaRef in `tool.finished` + event schema + schema regen
**Goal:** server-side event carries artifact refs; schema docs regenerate; TS client can be regenerated by W6.4.
**Probe first:** live anchors verified: `src/Host/TranscriptProjector.php:219` `toolFinishedEvent(ToolFinished $event, Message $row, ?array $identity, ?array $replaced, ?string $sessionId, ?string $turnId)` — where does the ToolResult's `imagePath/imageBytes/imageProtocol` (`src/ToolResult.php:113-115`) live at projection time (`$row->toolResults[0]`, `ToolResult::fromEngineResult` path :227 region — confirm `imagePath` survives into the projected row IN serve mode: the forked child sends results over the socket-pair event codec (`EngineBackend encodeEvent:5057`) — **verify ToolStarted/ToolFinished payloads carry imagePath/imageProtocol across the codec TODAY (the fields exist on ToolResult; the codec `:5057/:5135` must not strip them — probe decode-side reconstruction; if stripped, THIS step adds the keys to the codec — serialization target: EngineBackend event-union lines, sequenced single-owner)**); `src/Protocol/EventType.php` CATALOGUE tuple for `tool.finished` (durable flag? — probe: TOOL_FINISHED durable ⇒ journaling rules apply to the new field's size), `src/Protocol/Schema/EventSchemas.php:27-38` (`all()/data()/build()` — schema for tool.finished data: ADD `media` array-of-MediaRef def), `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` generator `sugar-crush/scripts/gen-protocol-schema.php` (VERIFIED exists; the web's gen-protocol.mjs consumes its OUTPUT — so BOTH regens chain: php → json → ts), serve-mode test files: probe `tests/Server|Protocol|Host` for existing transcript-projection tests to extend.
**Files likely touched:** `sugar-crush/src/Host/TranscriptProjector.php` (edit — `'media' => self::mediaData($result)` arm + private helper), `sugar-crush/src/Protocol/Schema/EventSchemas.php` (edit — media array def in tool.finished data), `sugar-crush/src/Protocol/Schema/Definitions.php` (edit — MediaRef $def if the schema lib wants a named def), `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` (REGEN via `php scripts/gen-protocol-schema.php`), possibly `sugar-crush/src/Backend/EngineBackend.php` (codec keys if stripped — minimal union lines), `sugar-crush/tests/Host/TranscriptProjectorMediaTest.php` (new; extend existing projector spec if one is found) + durations row.
**Lane:** P1 ; **Depends on:** W1.1–W1.9, W2.1 ; **Shared-file risk:** EventType/EventSchemas/docs-protocol JSON (P1 owns in W6), EngineBackend codec lines (P1)
**Build steps (micro):**
1. `mediaData(ToolResult $r): ?array` — build refs ONLY from `imagePath` (never base64 bytes onto the wire); kind inference (mp4 vs png by ext/mime const map); multiple artifacts (batch/grid) ⇒ ordered list with `indexLabel` (`grid`, `sample-3`…); cap the list (e.g. 32 refs, honest truncation flag — mirrors content-truncation ethic `MAX_EVENT_CONTENT_BYTES`).
2. Schema def: MediaRef = object{artifactId str, kind enum(image|video), path str, bytes int, mime str, infotextDigest str?, indexLabel str?} required core subset — match `Definitions.php` house schema-builder style exactly (read two sibling defs first).
3. Run `php scripts/gen-protocol-schema.php`; commit the JSON delta (must be the media field ONLY — a wider delta = pre-existing drift, file it, don't fold).
4. Codec audit (probe point above): fix-or-record.
**Tests (ship in-step):** behaviour (projector with image-bearing result → data.media exact shape incl. multi + cap; no-image result → key ABSENT not empty-array (array_filter law keeps byte-stability for old clients — pin)); schema-consistency (existing protocol schema test patterns — probe `tests/Protocol/` harnesses and clone their assertion style); serve-mode integration if a serve test-harness exists (probe — `Server/` tests present? extend, else unit-level suffices and note). Filter: `--filter 'Host\\TranscriptProjectorMediaTest|Protocol'`.
**Definition of done:** a running `sugarcrush serve` emits media refs in tool.finished; generated JSON current; old clients unaffected (absence pin).
**Review brief:** verify NO byte payloads anywhere near the journal (grep the new data path for imageBytes — MAJOR if it rides); durable-flag interaction: journal size growth test (a 32-ref frame's encoded length sane, <64KB); regen diff purity (reviewer re-runs the php generator on a scratch copy → byte-equal committed JSON).

## W6.2 — PHP: `MediaMethods` (`generate.submit`, `generate.status`, `media.fetch`)
**Goal:** the RPC domain: web (and any client) can submit a generation job, poll it, and pull bytes in slices — mirroring `tool.output`'s guarded read pattern exactly (`ToolMethods.php:30-57` verified: `Scope::Read`, `Params::int('offset',0,0)`, `mb_strcut` slicing, `{offset, content…}` return shape).
**Probe first:** `src/Protocol/Methods/ToolMethods.php` full file (the closest sibling — copy its Param-parsing, refusal, and return-array idioms verbatim); `MethodRegistry.php:11` + `MethodSpec::new(name, Scope, description, handler)` signature; `IdempotencyCache` + which methods take `idempotencyKey` (generate.submit SHOULD be idempotent-keyed — probe the mechanism's registration shape in an existing write method, e.g. TurnMethods); `Scope` enum (`read|write|approve|admin` — submit=Write? media-fetch=Read; check what permission a MONEY-SPENDING rpc should carry — Write + the Ask-relay? DESIGN NOTE: serve-mode generations bypass the TUI's Ask gate — the web client's permission-ask relay (approvals store exists: `src-web/stores/approvals.ts` verified present) should gate: submit runs the SAME PermissionGate; probe how TurnMethods' turns pass permission asks and mirror: `PermissionMethods.php` + `Tools/RelaysPermissionAsks.php` (`src/PermissionRequestMsg.php` cited crush_media §9.2) for the wire dance); execution channel = the forked tool-child machinery (NOT the loop thread — same §10.1-1 law; probe whether Methods handlers can dispatch through existing turn machinery with tool-name GenerateImage — cleanest = synthesize a turn/prompt invoking the tool? or a direct engine call — investigator picks the minimal honest path, documents); `SessionFeed` durable event for job state (`media.progress`? — v1: REUSE tool.started/finished + a non-durable `media.progress`? Decision: video jobs are long; add durable=false progress event OR piggyback on existing turn events — probe `EventType::CATALOGUE` non-durable precedent (SERVER_TICK family scope-server :38-40 shows the scope field) and choose minimal: **v1: no new event; status via generate.status polling method** — honest, less schema churn).
**Files likely touched:** `sugar-crush/src/Protocol/Methods/MediaMethods.php` (new), registration site (probe where Methods classes wire — `Dispatcher`/`Server` boot: grep `ToolMethods::` consumers; likely `src/Server/Server.php` or a Methods/provider wiring file — edit that one line), `sugar-crush/src/Protocol/Schema/MethodSchemas.php` (edit — params/results defs for the 3 methods), `sugar-crush/docs/protocol/sugarcrush.v1.schema.json` (REGEN again — P2 owns protocol-schema writes AFTER P1 merges), `sugar-crush/tests/Protocol/MediaMethodsTest.php` (new) + durations row.
**Lane:** P2 ; **Depends on:** W6.1 (schema base + refs shape) ; **Shared-file risk:** MethodSchemas.php + docs-protocol JSON (P2 after P1 merges — strict order), registry-wiring file (P2 sole)
**Build steps (micro):**
1. `generate.submit` params = the §2.2/§6.2 v1 field subset (mirror MediaRequest's settable surface 1:1 — generate a shared `paramSchema()` static on MediaRequest reused by both inputSchema (W2.1) and MethodSchemas — kills a drift axis, small refactor in-step with a pin); returns `{jobId}`; idempotency key honored if the mechanism is a header-param (copy sibling exactly).
2. `generate.status` → `{state, progress01, etaSeconds?, artifacts?: MediaRef[]}` from MediaJob state + W5.2 JobClient snapshots; unknown job → RpcError ErrorCode pattern (copy from a sibling method's refusal).
3. `media.fetch` — params `{artifactId | path, offset, limit}`; **path jail: artifactId resolves via MediaStore listing ONLY; raw `path` accepted iff under a session's media root (containment check, refuse `..` + symlinks via realpath-contained compare)**; returns sliced base64 chunk + `totalBytes` + `eof` (base64 because the JSON-RPC body is text — b64-of-slice ≈ +33%, bounded by limit default 512KB cap 4MB — cite the `tool.output` limit conventions and copy them, adjust units note); mime in first slice header-row.
4. Permission mirror: submit goes through the same PermissionGate Ask class as the tool (web client answers via existing approvals flow — verify the ask reaches the relay automatically BECAUSE it's the same gate, not because of new code; test asserts an ask IS raised in serve mode).
5. Regen schema; commit diff-pure.
**Tests (ship in-step):** behaviour (fake transport backend: submit→status→fetch happy slice loop; off-by-one slice math table copied from ToolMethodsTest's conventions — probe that file's test names and clone; unknown job error shape; traversal path refused; idempotent double-submit yields one job if cache mechanism applies), permission (Ask raised in serve mode — assert the relay event fires, mirror existing PermissionMethods test style). Filter: `--filter 'Protocol\\MediaMethodsTest'`.
**Definition of done:** a stateless client can drive a generation + retrieve bytes purely over RPC.
**Review brief:** containment code audited line-by-line (path jail bugs = CRITICAL security); b64 slicing never loads whole file (read with limit from fd — `fseek/stream_get_contents($fh, $limit, $offset)` not file_get_contents-whole — §10.3 stream-delta idiom inverted for reads); schema regen diff purity re-run; loop-thread audit (submit's execution MUST leave the loop — trace and pin by structural test if the codebase has such patterns, else reviewer-traced).

## W6.3 — Web: protocol regen + reducer media mapping
**Goal:** the browser's typed layer learns `media`. **Regeneration law (verified):** `sugar-crush-web/src-web/protocol/generated.ts` header: "GENERATED by scripts/gen-protocol.mjs from sugar-crush/docs/protocol/sugarcrush.v1.schema.json — do not edit" — never hand-edit; `npm run gen:protocol` (script at `sugar-crush-web/scripts/gen-protocol.mjs`, reads monorepo sibling, `--check` staleness mode, deterministic output, `SCHEMA_PATH` env override).
**Probe first:** reducer media-attach point VERIFIED: `src-web/stores/reducer.ts` `case 'tool.finished':` :407 (fields mapped at :410-425 — media joins the `item` literal); `ToolItem` type location + its consumers (`SubAgent`/`ToolItem` imported by ToolCard.vue :5 from reducer — single source); `generated.spec.ts` (exists at `src-web/protocol/__tests__/generated.spec.ts` — probe WHAT it pins: likely schema↔TS consistency — the regen may satisfy it automatically); client.ts typed call surface (`MethodResult<'tool.output'>` idioms for fetch later); `src-web/protocol/cursor.ts` + reconnect.ts replay paths (durable replay of tool.finished with media must re-render MediaCards — reducer is pure so this rides free — pin anyway).
**Files likely touched:** `sugar-crush-web/src-web/protocol/generated.ts` (REGEN only), `sugar-crush-web/src-web/stores/reducer.ts` (edit — `media?: MediaRef[]` mapping in the tool.finished case + ToolItem type if declared here — verified `ToolItem` lives in reducer.ts), `sugar-crush-web/src-web/stores/__tests__/reducer.spec.ts` (extend — existing file VERIFIED present), maybe `generated.spec.ts` (its pins auto-follow regen — verify not hand-pinned).
**Lane:** W1 ; **Depends on:** W6.1 (+W6.2 if methods wanted in the same regen — COORDINATION: ONE regen commit covering both PHP deltas' TS output; sequence: W6.1→W6.2 merge, then this step regens once) ; **Shared-file risk:** reducer.ts + generated.ts (W1 sole)
**Build steps (micro):**
1. `npm run gen:protocol` from `sugar-crush-web/`; review delta = methods (3) + events field + $defs (MediaRef) only.
2. Reducer: map `data.media` through; absent stays absent (old-server compat); `npm run typecheck` clean.
3. Add media to the reducer spec fixtures (clone existing tool.finished fixture shape).
**Tests (ship in-step):** vitest: reducer spec new cases (media lands; absent-media byte-equal old item; reconnect-replay produces same item — follow the file's existing harness idioms). Commands: `cd sugar-crush-web && npm test -- reducer && npm run gen:protocol -- --check && npm run typecheck`.
**Definition of done:** typed web state knows about media, generator happy.
**Review brief:** `--check` green is the proof regen is committed; mutation: hand-edit one generated line → `--check` rc=1 (reviewer demonstrates); reducer mapping never invents defaults for absent media (compat law).

## W6.4 — Web: `MediaCard.vue` + ToolCard arm + blob fetch
**Goal:** render artifacts. New `src-web/components/MediaCard.vue`: image → thumbnail via fetched blob (`URL.createObjectURL`) with click-to-lightbox (native `<dialog>` or existing modal idiom — probe SessionView.vue :126 region for modal patterns… SessionView.vue exists (views list verified); probe its structure for where ToolCard mounts + any dialog lib), video → `<video controls>` blob (formats the browser can play; mp4 yes) + fallback row "unsupported codec in browser"; loading state, per-card error state (404/refused → honest line, never blank), infotext digest expander. Fetch via a small lib: `src-web/lib/media.ts` (new) calling `media.fetch` sliced (client.ts typed `call`), assembling `Blob` from chunks (revoke object URLs on unmount — leak law).
**Probe first:** ToolCard.vue arms verified (:57-68 body region — media arm joins after DiffView/output; `data-testid="tool-card"` conventions; `loadFull` emit pattern :66 = the fetch-affordance idiom to imitate for big media); component spec file `src-web/components/__tests__/components.spec.ts` (21 tests era — extend, or new `MediaCard.spec.ts` colocated following `settings/__tests__`/`approvals/__tests__` directory convention VERIFIED); `client.ts` (376 ln) method-call + error shape; **DOMPurify/markdown note (mystage §6.4): `<img>` already survives markdown rendering — but artifact refs are PATHS local to the server; markdown-inline images will NOT render server-local files; MediaCard via structured field is the ONLY sanctioned media render path; do NOT relax `html:true` anywhere** (markdown.ts verified `html:false`, sanitize profile line :35-39 — cite as the security anchor); CSP: probe `index.html`/`vite.config.ts`/`src/Assets.php` headers for `img-src`/`media-src`/`connect-src` (if a CSP exists, blob: must be added deliberately — probe!).
**Files likely touched:** `sugar-crush-web/src-web/components/MediaCard.vue` (new), `sugar-crush-web/src-web/lib/media.ts` (new), `sugar-crush-web/src-web/components/ToolCard.vue` (edit — arm), `sugar-crush-web/src-web/components/__tests__/MediaCard.spec.ts` (new) (+ components.spec.ts touch if registry-style), CSP bits in `index.html`/`vite.config.ts`/`src/Assets.php` ONLY if a CSP exists.
**Lane:** W2 ; **Depends on:** W6.3 ; **Shared-file risk:** ToolCard.vue (W2 sole)
**Build steps (micro):**
1. `lib/media.ts`: `fetchArtifact(ref): Promise<Blob>` loop (offset+=chunk; eof; cancel on unmount signal), mime from first chunk.
2. MediaCard: props `{ref: MediaRef}`; object URL lifecycle in onMounted/onUnmounted; `<img>`/`<video>` by kind/mime; lazy — fetch only when visible-ish (simplest v1: fetch on expand/collapse state like ToolCard's `open` — default COLLAPSED thumbnail-free row for >N? keep: images fetch eagerly (they're the payoff), videos wait for a ▶ tap — pin both in tests).
3. ToolCard arm: `v-if="item.media?.length"` renders a row of MediaCards after output region.
4. a11y: alt/aria per house (existing `data-` attribute conventions); NO v-html anywhere (DOMPurify anchor untouched).
**Tests (ship in-step):** vitest (@vue/test-utils + happy-dom): MediaCard fetch loop (fake client stub), blob URL revoke (happy-dom object URL shim — probe its availability; if absent, inject an abstraction in lib/media.ts so tests can fake it — do it), ToolCard arm polarity (media present/absent), eager-vs-▶ behavior; `npm run lint && npm run typecheck` green.
**Definition of done:** a generation from the TUI appears clickable in the browser, bytes on demand only.
**Review brief:** leak audit (every createObjectURL has a revoke path incl. error arms); lazy-video law (auto-fetching megabyte mp4s on render = finding); CSP decision documented either way; grep for `dangerously`/`v-html` additions = instant CRITICAL.

## W6.5 — Web: fetch route/auth alignment + serve-mode e2e
**Goal:** prove the whole chain under the real server: WS auth (tickets/tokens `src/Server/Auth/`), media.fetch through `Http/ApiController`-adjacent auth middleware (verified: ApiController "auth+health only today" per crush_media §6.4 — our fetch rides RPC over the authenticated WS, so NO new HTTP surface needed UNLESS CSP/proxy demands it; record), and a Playwright e2e.
**Probe first:** existing e2e harness: `e2e/fixtures/` dir (verified present) — how fixtures spin a server + login (login.spec.ts + `src-web/protocol/auth.ts` verified present: auth tickets/tokens); `multi-client.spec.ts` (broadcast assertions pattern to copy for media event fan-out); `phpunit.xml` + `tests/` in sugar-crush-web (PHP asset serving tests — probe if CSP headers asserted there); serve-test harness in sugar-crush (W6.2 probed — reuse its boot helper if present in `tests/Server/`).
**Files likely touched:** `sugar-crush-web/e2e/media.spec.ts` (new), `sugar-crush-web/e2e/fixtures/*` (edit if server-boot fixture needs a stub sd backend — the e2e runs against a FAKED sd server: simplest honest path = e2e drives a FAKE media by writing an artifact into a temp media store via a small helper script or stubbed provider (investigator picks; a stub `EndpointFamily::Custom`-style fake is cheapest — probe feasibility), sugar-crush serve-test glue if P-side needed.
**Lane:** W3 ; **Depends on:** W6.2, W6.4 ; **Shared-file risk:** e2e fixtures (W3 sole)
**Build steps (micro):**
1. e2e: login → trigger stubbed generation (via `generate.submit` RPC through the page's client or a direct fixture call) → expect MediaCard appears in ToolCard → image element loads (blob: URL asserted) → second client sees same (multi-client pattern clone).
2. If Playwright can't run a real browser in gate env: mark spec `test.skip(condition, reason)` following existing specs' skip conventions (probe how session.spec.ts handles CI absence).
3. Auth negative: fetch other-session artifact refused end-to-end (token scope) — RPC-level test may suffice here; e2e keeps happy-path.
**Tests (ship in-step):** the spec itself; plus vitest auth-surface tests only if lib/media needs them (no — covered). Commands: `cd sugar-crush-web && npm run e2e -- media.spec.ts` (gated env permitting), `npm test`.
**Definition of done:** CI (or the gate's documented local run) proves browser-visible generation.
**Review brief:** skip-conditions honest (a permanently-skipped e2e = MINOR with follow-up); no secrets in fixture dirs; multi-client replay asserts durable semantics match EventEnvelope journal.

## W6.6 — Web: form-over-ask-relay reuse (structured media form via AskUser channel) — SHAPE STEP
**Goal:** mystage §6.2 promise: "Mid-turn model→user interaction already crosses both UIs via `AskUserTool` + `DelegatesToEngine/RelaysPermissionAsks` and the permission-ask relay — reuse that channel for the web form rather than inventing one." v1 scope = the AGENT asks the user for generation params via a structured ask whose ANSWER is a MediaRequest-subset JSON the tool consumes; the WEB renders it as a basic form (fields from the ask's JSON-schema payload — the `inputSchema` machinery W2.1 built feeds it). Heavy custom form later.
**Probe first:** `src/Tools/BuiltIn/AskUser*` (live name/grep `AskUserTool` — probe exact class + its ask payload crossing `Protocol/Methods/PermissionMethods.php` / approvals flow) + `src-web/stores/approvals.ts` + `components/approvals/ApprovalsDrawer.vue` (verified present) — how answers post back; TUI-side rendering of the same ask (degrades to text — acceptable).
**Files likely touched:** `sugar-crush/src/Tools/BuiltIn/AskUser…` (edit ONLY if a structured-schema mode is missing — the honest cost check happens here: if missing and big, this step DESCOPES to a recorded design note + defers, keeping W6.2 RPC the web's param path), `sugar-crush-web/src-web/components/approvals/…` (generic schema-form arm if landed), specs/tests both sides if landed, else evidence-only.
**Lane:** W4 ; **Depends on:** W6.3 (types), existing ask relay ; **Shared-file risk:** approvals components (W4 sole)
**Build steps (micro):**
1. Investigate + decide ≤1 page: reuse-cheap vs defer; land the Appendix C record either way (DESCOPED-with-reasons is a passing outcome for this step BY DESIGN).
2. If cheap: ask payload carries `{mediaForm: true, fields: FieldSpec subset}`; ApprovalsDrawer renders inputs per field type (Select/Number/text only — 3 widgets max); posts structured answer; tool re-hydrates MediaRequest.
**Tests (ship in-step):** if landed: reducer/approvals vitest for form render+answer round-trip; PHP ask-serialization unit pin. If descoped: none (decision doc).
**Definition of done:** the relay channel's fitness for media is EMPIRICALLY known, not assumed.
**Review brief:** descoping honestly beats half-landed forms (reviewer checks the record cites the probe measurements).

## W6.7 — `DiscoveryFile` media advertisement + serve docs
**Goal:** the server self-describes media readiness to clients (format precedent crush_media §9.5 `src/Server/DiscoveryFile.php:8-18` pid/protocol/url record): add `media: {image: bool, video: bool, baseUrlHostOnly: hash}` derived from W1.8 config ∩ W1.2 probe (never secrets/hostnames in the clear — hash or flag only); web client reads it at connect to hide/show generation affordances.
**Probe first:** DiscoveryFile live writer/fields; where clients consume it (`src-web/protocol/client.ts`/`connection.ts` hello flow — probe `server.hello` method shape (Scope; version min/max already there per PROTOCOL const comment verified :4); docs/PROTOCOL doc if present (probe sugar-crush/docs/protocol/ contents beyond the json).
**Files likely touched:** `sugar-crush/src/Server/DiscoveryFile.php` (edit), `sugar-crush/src/Protocol/Methods/ServerMethods.php` (edit — hello payload media flags), `sugar-crush-web/src-web/stores/connection.ts` + `src-web/protocol/client.ts` (edits — feature-flag store), schema regen follow (P-owned? — this step owns its own tiny regen AFTER W6.2 merged), tests both sides (extend existing discovery/hello specs — probe names first).
**Lane:** P3 (late-PHP) + W-coord ; **Depends on:** W6.2 (schema stability) ; **Shared-file risk:** docs-protocol JSON third writer — STRICT order after P2 merge
**Build steps (micro):**
1. Flags-only advertisement (no URLs); capability refresh = boot-time config read (no probe in serve boot path — fail-open law).
2. Web: `capabilities.media` in app store; MediaCard generation affordances + any future web generate UI consult it; hide affordance when false (server without sd).
**Tests (ship in-step):** behaviour (discovery JSON gains keys; hello includes them; web store maps flags) — clone existing spec idioms both sides. Filter/specs: `--filter 'Server\\Discovery'…` + `npm test -- connection`.
**Definition of done:** a web client KNOWS whether to offer media without dialing sd.
**Review brief:** no hostname leak (mutation: put baseUrl in the record → pin reds); regen diff purity third time (a stacked-regen test the orchestrator runs once).

---

# Wave 7 — Hardening & finish

Goal: knob-parity truth, docs completeness, demo, security/perf hygiene, final figures, live smoke. Mostly serial (one docs owner; the gate owns figures).

## W7.1 — 90-control knob-parity sweep vs Appendix B
**Goal:** close-out audit: EVERY row of Appendix B (the §2 catalog rendered plan-side) carries either a `covered-by step id` (verified in live code by the auditor) or an explicit `DESCOPED — reason` line. Discrepancies become either quick-fix commits (lane a) or backlog entries (Appendix C updates).
**Probe first:** Appendix B itself; live `FieldSpec` tables (W3.4/3.5/3.7/5.6), `MediaRequest` field list (W1.1), override-editor reachability claims (W3.8), `NOT_SUPPORTED_V1` const (W1.1 DoD); proven-negatives rule (e) verification sweep: `grep -rin 'TCD\|regional.seed\|per.axis.weight\|vary.region\|diffuse.noise' sugar-crush/src/` → hits ONLY allowed in a ruling-comment (the Forge/SD.Next exclusion note) — anything shipping as a core field = finding; an OPTIONAL "extension-shaped advanced namespace" exists exactly once: W3.8 override rows + W3.6 script-info (document that as the sanctioned home, crush_media §5.10 ecosystem aside).
**Files likely touched:** this plan (Appendix B status column — orchestrator), small fix files per finding (lane a).
**Lane:** audit ; **Depends on:** all of W1–W6 merged ; **Shared-file risk:** none
**Build steps (micro):**
1. Auditor walks Appendix B top-to-bottom, executes the covering interaction in a test or REPL-ish harness where cheap (a data-provider-driven test `FieldSpecParityTest` that asserts registry rows contain the ranges/defaults copied from the appendix — turning the sweep into a PERMANENT drift guard is the recommended shape: build it, ≤1 test file, worth it).
2. Every miss: fix-in-place if ≤20 lines, else backlog row with reason.
3. Re-record C-numbers (C-1…C-7) with final rulings incl. audio (W5.8), autosave (W1.9/Q-4), sketch (W4.6), HTTP-binary-route (W6 intro), ask-relay (W6.6).
**Tests (ship in-step):** `sugar-crush/tests/Media/FieldSpecParityTest.php` (new) — the appendix as data; durations row. Filter: `--filter 'Media\\FieldSpecParityTest'`.
**Definition of done:** Appendix B shows 100% dispositioned; parity guard green.
**Review brief:** reviewer spot-checks 15 appendix rows against live FieldSpec source, not the test (the test could itself be wrong — compare to crush_media §2 tables as the meta-oracle).

## W7.2 — Documentation pass (README section, ARCHITECTURE rows, DocFigure arms)
**Goal:** the human docs catch up with the feature set: README `/generate` full section (flags + form keys + render modes + storage location + security note about `${VAR}` keys), docs/ARCHITECTURE media-module dataflow row(s), docs/SETTINGS+ENVIRONMENT verify-complete (generators re-run), a short `docs/MEDIA.md` if the README section overflows (house style check first), and **DocFigure arms for EVERY prose numeral introduced anywhere in W1–W7 docs** (campaign law: prose+arm same commit — retro-fit sweep here).
**Probe first:** existing DocFigureProseDriftTest arm patterns (`tests/Config/DocFigureProseDriftTest.php` — arms derive values LIVE from code constants; read two recent arms and copy the shape; word-number pins for counts like "seven display modes" — the vocabulary set is candy-mosaic `supportedProtocols()` — pin count to its derivation, never to a typed integer); README current structure (grep `^## ` heads); `gen-*-docs` idempotence re-run.
**Files likely touched:** `sugar-crush/README.md`, `sugar-crush/docs/ARCHITECTURE.md`, `sugar-crush/docs/MEDIA.md` (new, conditional), `sugar-crush/tests/Config/DocFigureProseDriftTest.php` (edit — arms), docs/SETTINGS+ENVIRONMENT via generators only.
**Lane:** docs ; **Depends on:** W7.1 ; **Shared-file risk:** IS the docs lane of W7
**Build steps (micro):**
1. Draft → generators-preview → hand sections → DocFigure arms for each count/range cited → run the doc family filter green.
2. Sweep: `grep -RinE '[0-9]+ (modes|controls|fields|sliders|endpoints)' README.md docs/` → each hit either arm-pinned or de-digitalized (prefer de-digitalizing prose — campaign lesson: numerals in prose are time-bombs).
**Tests (ship in-step):** `--filter 'DocFigureProseDriftTest|ReadmeRosterDriftTest|ReadmeSuiteFigureDrift'` (last stays red for figures — gate owns).
**Definition of done:** a fresh user learns the whole feature from docs; zero un-armed numerals.
**Review brief:** mutation: change mosaic `supportedProtocols()` count mentally — does the arm actually derive (it must not hardcode 7); doc claims vs code spot-audit 8 claims.

## W7.3 — VHS demo tape for the media form
**Goal:** a `.tape` under `sugar-crush/.vhs/media-form.tape` driving an examples script per the record-vhs-demo conventions (`Set Theme "TokyoNight"`, quoted values, `Type "php examples/<demo>.php"` style) — the feature is visual, exemption N/A (AGENTS.md VHS section).
**Probe first:** `.vhs/` existing tapes in sugar-crush (hand list + dims + how a NON-interactive demo script fakes a server — the demo must run WITHOUT skynet2: examples script spins the canned fake transport (W2.1/W5.2 test doubles have the fixture shapes — lift a tiny `examples/media-demo.php` that drives MediaForm render states + a fake generation painting a committed sample PNG through mosaic at a chosen inline mode (kitty/sixel would need passthrough in VHS's terminal — use **halfblock/truecolor** for GIF fidelity, honest caption says so); `vhs.yml` `all=(...)` hand-array addition (AGENTS.md gotcha: it's hand-maintained).
**Files likely touched:** `sugar-crush/.vhs/media-form.tape` (new), `sugar-crush/examples/media-demo.php` (new), `.github/workflows/vhs.yml` (edit — array entry).
**Lane:** demo ; **Depends on:** W7.2 ; **Shared-file risk:** vhs.yml (sole)
**Build steps (micro):**
1. Examples script: deterministic seed, scripted key-injection via the same ProgramSimulator/ScriptedInput path IF crush dev-deps allow (G-7 decision from crush_media §7.5/§10.3: candy-testing dev-dep — if the wave schedule never adopted it, the examples script drives the FORM model headless and echoes frames through a plain ANSI out — keep simple: the demo shows RENDER STATES, not live keys; tape drives sleep/echo).
2. Tape: open form → Params section → hires open → fake generation → painted image → /rendermode halfblock flip → close. 800×480-ish house dims per record-vhs-demo standard.
3. Don't commit GIFs (CI renders — AGENTS.md).
**Tests (ship in-step):** none PHPUnit; `vhs` renders locally IF installed (probe `which vhs` — CI does it regardless); examples script runs headless green (one bash assertion in review).
**Definition of done:** vhs.yml entry + tape + example land together.
**Review brief:** tape values all quoted; theme line exact; non-visual exemption not claimed (this IS visual); example leaves zero temp litter (uses temp MediaStore HOME).

## W7.4 — Privacy/security audit
**Goal:** explicit pass over the attack surface the feature added: (1) secrets: `${VAR}` placeholder channel ONLY — grep sweep for any file/setting/log/infotext/PNG-chunk path that could persist a literal key (server `parameters` chunk content comes from OUR emit + server `extra_generation_params` — a rogue/misconfigured server could echo secrets INTO the infotext we stamp into saved PNGs: mitigation decision — `Infotext::parse` keys matching /api|key|token|secret/i are DROPPED before stamping/hydration + this audit pins it); (2) SSRF posture: UrlGuard origin-lock (W1.3) re-audited incl. redirect/DNS-rebind caveat doc (rebinding: we dial by configured hostname at request time — accept + document, or pin resolved IP per session — decision recorded); (3) path jail on `media.fetch` (W6.2) re-audited incl. symlink escape via MediaStore-adjacent moves (realpath containment — verify it's realpath-based not string-prefix); (4) child processes: ffmpeg/ffplay accounting (W5.5 gate + manual `ps` diff during a played video + quit); (5) egress consent: Ask gate coverage matrix (tool Ask, command consent model, serve-mode relay) written into docs/SECURITY-adjacent section; (6) clipboard paste: image bytes copied into store, nothing auto-uploaded (verify no path sends CLIPBOARD contents anywhere).
**Probe first:** all cited step implementations at live tip; the repo's existing SECURITY.md conventions.
**Files likely touched:** fixes per finding (any lane file — serial: THIS lane runs alone, no concurrency), `sugar-crush/tests/Media/SecuritySweepTest.php` (new — the greps-as-tests: no literal-key persistence in fixtures, infotext secret-drop pin, urlguard redirect pin re-cites), `SECURITY.md` note if a disclosure-relevant behavior deserves it.
**Lane:** sec ; **Depends on:** W7.3 ; **Shared-file risk:** solo lane
**Build steps (micro):**
1. Run the checklist as an adversarial read; write findings-first report `/tmp/opencode/plan-crush-media/w7/security/REPORT.md`.
2. Land fixes with pins (each finding ⇒ test ⇒ fix ⇒ re-green order).
3. Update FieldSpec/ImportPng docs where secret-drop changes behavior (a pasted A1111 infotext carrying an override `sd_api_key`-shaped key gets that row DROPPED — user-visible, doc'd).
**Tests (ship in-step):** SecuritySweepTest + per-finding pins. Filter: `--filter 'Media\\SecuritySweepTest'`.
**Definition of done:** six-point checklist each CLOSED with a citation; MAJORs zero.
**Review brief:** independent second reviewer re-walks the checklist cold (fresh eyes; the classic failure is self-certifying audits).

## W7.5 — Perf probes (form render cost, poll cadence, encode budgets)
**Goal:** measure, don't guess: (~90-field form) open/render cost, `/progress` poll loop CPU per minute, batch-16 encode+stamp cost, preview-frame encode frequency vs transcript repaint cost (W2.4 blob re-paint), video screen frame-budget under `frameBudgetMs` pacing; record numbers; only pathologically bad ones become fixes (thresholds pre-declared: form build <15 ms median, poll loop <2% core idle, 16×1024² stamp+put <4 s on this box, else finding).
**Probe first:** timing harness precedent (campaign memory: E29/E115 measurement lane style — targeted micro-bench scripts in the artifacts dir, NOT committed tests that assert wall-clock (flakes); a COUNT-based pin is fine (e.g. encode called exactly once per artifact)).
**Files likely touched:** evidence dir only + fix commits if thresholds breach (files per fix).
**Lane:** perf ; **Depends on:** W7.4 ; **Shared-file risk:** none absent findings
**Build steps (micro):**
1. Five micro-benches (`/tmp/opencode/plan-crush-media/w7/perf/*.php`), each ≥7 samples, median reported, fixed seed, fake transport with canned payloads.
2. Preview-repaint COUNT pin inside W2.4's test file if cheap (signature-skip working: two identical preview frames → one placement — add the case there, tiny).
3. REPORT.md table + decisions.
**Tests (ship in-step):** the count-pin only. Filter: existing filters touched.
**Definition of done:** numbers on disk; every breach dispositioned.
**Review brief:** methodology check (warm-up excluded? GC noise noted?); no wall-clock assertions committed to CI.

## W7.6 — Final full suite + figure re-pin (project closeout gate)
**Goal:** the definitive green: full serial run, `suite-figure.json` + README headline + durations.tsv refresh, K=8 shard conservation check, drift family sweep, `tools/check-path-repos.php --no-lib-path-repos` + `tools/check-child-lifetimes.php` rc=0, sibling suites re-run (candy-forms/mosaic/reel/core), `php tools/gen-*-doc --check`-style idempotence, and the plan's own completion ledger updated (every step ✅/DESCOPED).
**Probe first:** W0.4 baseline file (the delta ledger's base); refresh procedure precedent: `scripts/refresh-suite-figure.php` refuses non-green logs — cadence: run green → refresh → bump README assertion figure → confirm-run (campaign memory ka-lane note); `--filter Config` window green (lane-cf base-red history noted — if it reappears, it's NEW, triage it).
**Files likely touched:** `sugar-crush/tests/Config/Support/suite-figure.json`, `sugar-crush/README.md` (headline), `scripts/parallel-tests-durations.tsv` (+ all new rows if any lane forgot), this plan (status ledger).
**Lane:** gate ; **Depends on:** W7.5 ; **Shared-file risk:** figure files (gate only, final)
**Build steps (micro):**
1. Serial plain-pipe green (log+judit retained) → refresh figure json → README pair → re-confirm → shard check `scripts/parallel-tests.sh` (durations tsv as input per its usage) K=min(nproc,8) conservation PASS.
2. Sibling full runs (four libs) green.
3. Tool gates rc=0 pair.
4. Commit "media: project closeout — figures" with every lane delta reconciled (document any unattributable wobble honestly, campaign ±assertion-wobble law).
**Tests (ship in-step):** THE suite.
**Definition of done:** all-green ledger + pinned figures + zero foreign-file collateral (per-file `git show --stat` audit of every project commit — the lc-ledger-law discipline: numstat sanity per commit).
**Review brief:** final whole-project reviewer pass (per Review law this is the last loop; it reviews the CLOSEOUT commit + spot-replays three historical wave gates from logs kept in the artifacts dir).

## W7.7 — End-to-end smoke against skynet2 (conditional)
**Goal:** IF W0.1 (or a re-probe at this moment) shows the server up: real t2i (Flux 2 dev) via the tool, real painted result, `/play` on a real video if the dialect answered, infotext round-trip against a server-saved PNG (`POST /png-info` vs our local read — byte-compare OUR parser against THEIR producer = the ultimate interop proof). IF dark: re-run W0.1 probe, record, close step as BLOCKED-EXTERNAL (never a fake pass).
**Probe first:** current W0.1 status; operator availability window (their box, their call).
**Files likely touched:** evidence dir; at most a fixture freeze (commit a REAL server PNG into `tests/fixtures/sd/real-skynet2-txt2img.png` behind review — privacy: prompts innocuous, strip nothing, it's OUR own generation).
**Lane:** smoke ; **Depends on:** W7.6 ; **Shared-file risk:** none
**Build steps (micro):**
1. Manual-driven script `scripts/`-free: a small php one-off in the artifacts dir using the real Client against the live baseUrl (creds via env `${VAR}` only).
2. Interop proof pair (their info string vs our parse/emit).
3. REPORT.md with verbatim (prompt→params→artifact-hash) receipts.
**Tests (ship in-step):** none new (a frozen REAL fixture MAY power one existing-shape parse test, opt-in).
**Definition of done:** PASS with receipts, or BLOCKED-EXTERNAL recorded for the operator's next window.
**Review brief:** no secrets in receipts; no mutating calls beyond generations; the real-fixture (if committed) contains no personal data.

---

# Appendix A — File-collision matrix (shared serialization targets → owner)

| Shared file | W1 | W2 | W3 | W4 | W5 | W6 | W7 |
|---|---|---|---|---|---|---|---|
| sugar-crush/README.md | e | e (docs owner) | f-staged→gate | gate | a-staged→gate | — | docs+gate |
| docs/SETTINGS.md / ENVIRONMENT.md | e | e | f-staged→gate | — | a-staged→gate | — | docs |
| docs/ARCHITECTURE.md | — | e | gate | — | gate | — | docs |
| docs/COMMANDS.md / KEYS doc | — | e | f | gate | a | — | docs |
| docs/protocol/*.json | — | — | — | — | — | P1→P2→P3 (strict order) | — |
| src/Cli/Bootstrap.php | — | b | — | — | — | — | — |
| src/Chat.php | — | c | a | a→c (queue) | a | — | — |
| src/App/App.php | — | — | a | — | b | — | — |
| src/Renderer.php (transcript) | — | d | — | — | — | — | — |
| src/Tui/Renderer.php (frame) | — | — | a | — | b | — | — |
| src/ToolResult.php | — | b | — | — | — | — | — |
| src/Backend/EngineBackend.php (unions/codec) | — | d (2 arms) | — | — | — | P1 (keys) | — |
| src/Tools/BuiltIn/GenerateImage.php | — | a | — | d | — | — | — |
| src/Tui/MediaForm/MediaForm.php | — | — | a→b→c→d (queue) | a | c | — | — |
| Sections/ImageSection.php | — | — | b (create) | a→b (queue) | — | — | — |
| lang/en.php | — | — | f (sole) | f-adjacent rows via f | — | — | — |
| composer.json (sugar-crush) | — | — | — | — | a | — | — |
| tools/check-child-lifetimes.php | — | — | — | — | b | — | — |
| sugar-crush-web reducer.ts / generated.ts | — | — | — | — | — | W1 (sole) | — |
| sugar-crush-web ToolCard.vue | — | — | — | — | — | W2 | — |
| tests/Config/Support/suite-figure.json | gate only, every wave | | | | | | |
| scripts/parallel-tests-durations.tsv | each step adds OWN row (append-only, low-collision; git-level conflicts resolved by gate) | | | | | | |

Regeneration discipline: `gen-tool-docs / gen-command-docs / gen-settings-doc` run by GATE only (wave law); `gen-protocol-schema.php` by the CURRENT schema-owner (P1, then P2, then P3 — never concurrent); `npm run gen:protocol` by W1 once after P2 merged (+ P3 follow-up regen noted in W6.7 — if P3 changes schema, W6.7 regens again; single-writer order preserved).

# Appendix B — Knob-parity coverage checklist (from crush_media §2 catalog)

Legend: **B=behavior/client field, F=form step, C=covered-by step, D=deferred/descoped (reason)**. Ranges/defaults cited from §2 tables; conflict rulings C-1/C-3/C-4 applied. W7.1 flips every row to covered-or-descoped; this table starts the ledger.

| # | Control (A1111 label) | Field (API) | Range/default | Form step | Client field | Status |
|---|---|---|---|---|---|---|
| 1 | Prompt | prompt | text | W3.4 | MediaRequest::prompt | planned |
| 2 | Negative prompt | negative_prompt | text | W3.4 | negative_prompt | planned |
| 3 | Styles (multi) | styles | DB choices | W3.4 | styles | planned |
| 4 | Token counter | — | live | — | — | D v1 (CLIP tokenizer server-side; revisit via `/internal/token-count` §4.4, backlog) |
| 5 | Width / Height | width/height | 64–2048/8 · 512 | W3.4 | width/height | planned |
| 6 | ⇅ swap | — | ui | W3.4 | — | planned (button-equivalent key) |
| 7 | Sampling steps | steps | 1–150/1 · 20 | W3.4 | steps | planned |
| 8 | CFG Scale | cfg_scale | 1–30/.5 · 7 | W3.4 | cfg_scale | planned |
| 9 | Sampling method | sampler_name | §2.9 roster | W3.4 | sampler_name | planned (+ legacy `"X Karras"` split) |
| 10 | Schedule type | scheduler | 12 roster | W3.4 | scheduler | planned |
| 11 | Seed | seed | int64 · −1 | W3.4 | seed | planned |
| 12 | 🎲 / ♻ | — | ui | W3.4 | — | planned |
| 13 | Batch count | n_iter | 1–inf/1 · 1 | W3.4 | n_iter | planned |
| 14 | Batch size | batch_size | 1–8/1 · 1 | W3.4 | batch_size | planned |
| 15 | Extra disclosure gate | — | — | W3.5 | — | planned |
| 16 | Variation seed | subseed | int64 · −1 | W3.5 | subseed | planned |
| 17 | Variation strength | subseed_strength | 0–1/.01 · 0 | W3.5 | subseed_strength | planned |
| 18 | Resize seed from W/H | seed_resize_from_w/h | 0–2048/8 · 0 | W3.5 | … | planned |
| 19 | Hires. fix toggle | enable_hr | bool | W3.5 | enable_hr | planned |
| 20 | ↳ Upscaler | hr_upscaler | latent-modes ∪ /upscalers | W3.5 | hr_upscaler | planned |
| 21 | ↳ Upscale by | hr_scale | 1–4/.05 · 2 | W3.5 | hr_scale | planned |
| 22 | ↳ Resize width/height to | hr_resize_x/y | 0–2048/8 · 0 | W3.5 | … | planned (HiresMath precedence §2.4) |
| 23 | ↳ Hires steps | hr_second_pass_steps | 0–150 · 0 | W3.5 | … | planned |
| 24 | ↳ Hires denoising | denoising_strength (hr) | 0–1/.01 · 0.7 | W3.5/4.x | denoising_strength | planned |
| 25 | ↳ Hires checkpoint/sampler/scheduler | hr_checkpoint_name/hr_sampler_name/hr_scheduler | hidden-default | W3.5 reveal-rows | … | planned |
| 26 | ↳ Hires prompt/negative | hr_prompt/hr_negative_prompt | hidden-default | W3.5 reveal-rows | … | planned |
| 27 | Hires-final-res preview row | — | computed | W3.5 (Note) | — | planned |
| 28 | Refiner toggle+ckpt+switch-at | refiner_checkpoint/switch_at | .01–1/.01 · .8 | W3.5 | … | planned |
| 29 | Override settings editor | override_settings (+restore_afterwards) | any opts key | W3.8 | … | planned |
| 30 | Scripts dropdown + args | script_name/script_args | /scripts | W3.6 | … | planned |
| 31 | Alwayson scripts | alwayson_scripts{} | /script-info | W3.6 | … | planned |
| 32 | img2img init image(s) | init_images | b64 list | W4.1 | … | planned |
| 33 | Resize mode | resize_mode | radio 4 · Just resize | W3.7/W4 | … | planned |
| 34 | Scale-by (img2img) | (UI) scale_by→W/H | .05–4/.05 · 1 | W3.7 | via W/H compute | planned |
| 35 | 📐 detect size | — | ui | W4.1 | — | planned (dims known on pick) |
| 36 | Mask upload (B&W / alpha) | mask | white=mask law | W4.3 | … | planned |
| 37 | Mask blur | mask_blur | 0–64/1 · 4 | W3.7 | … | planned (x/y via overrides) |
| 38 | Mask transparency (editor-only) | — | never serialized §2.6 | — | — | D permanent (display-only upstream too) |
| 39 | Mask mode | inpainting_mask_invert | 0/1 | W3.7 | … | planned |
| 40 | Masked content | inpainting_fill | **4 values 0–3 (C-1)** · UI "original" | W3.7 | … | planned |
| 41 | Inpaint area | inpaint_full_res | bool radio | W3.7 | … | planned |
| 42 | Only-masked padding | inpaint_full_res_padding | 0–256/4 · UI32/API0 | W3.7 | … | planned |
| 43 | Image CFG Scale | image_cfg_scale | 0–3/.05 · 1.5 edit-models | W3.7 hidden | … | planned (capability-visibility) |
| 44 | Initial noise multiplier | initial_noise_multiplier | 0–1.5/.001 · 1 | — | DTO has it | D form-row (override-only, §2.5) |
| 45 | Interrogate CLIP/DeepBooru | /interrogate | buttons | — | W1.3 method exists | D v1 UI (client ready; backlog quick-action) |
| 46 | Restore faces | restore_faces / override face_restoration | bool + model + codeformer_weight | — | override channel W3.8 | D core-row (moved to Settings upstream, §2 note) — override-reachable |
| 47 | Tiling | tiling / override | bool | — | override W3.8 | D core-row (same upstream move) — reachable |
| 48 | Clip skip | override CLIP_stop_at_last_layers | 1–12 · 1 | — | override W3.8 | covered-by-override |
| 49 | ETA/skip/progress | /progress, skip, interrupt | live | W1.5 + preview W2.4 | — | planned |
| 50 | Save pattern | (client) samples_filename_pattern analog | tokens | W1.9/W3.9 | — | planned (subset palette; prompt-tokens D for injection) |
| 51 | PNG Info import / paste params / send-to | /png-info, infotext | — | W4.4 | infotext field D-reachable | planned |
| 52 | Upscale-✨ (txt2img re-hires) | — | button | — | — | D v1 (send-to + enable_hr approximates; backlog) |
| 53 | X/Y/Z plot | script | axes tables | via /script-info W3.6 | script_args | covered-by-generic (values as text rows; grid-stitch display D) |
| 54 | Prompt matrix / loops / outpaint scripts | scripts | — | W3.6 generic | … | covered-by-generic |
| 55 | Extra networks (LoRA/TI cards) | `<lora:>` etc. in prompt | card browser | — | prompt passthrough (W1.6 untouched-syntax) | D card-UI (prompt syntax works day-1; browser backlog Q-4/§2.16) |
| 56 | VAE / quicksettings | sd_vae etc. | dropdowns | — | override W3.8 | covered-by-override |
| 57 | Sampler-params (eta, s_churn/tmin/tmax/noise, sigma_min/max, rho, ENSD(C-3=0), discard-next-to-last, sgm_noise_multiplier, skip_early_cond, uni_pc_*(C-4 order 1–50), sd_noise_schedule, beta_dist_*, s_min_uncond(_all)) | override keys §2.10 | per §2.10 | W3.8 rows | override | covered-by-override (21 keys enumerated in W7.1 sweep list) |
| 58 | token_merging_ratio(_hr) | fields §4.2 | 0–1 | — | DTO | D form-row, DTO-ready (Flux-era relevance low; W7.1 re-rates) |
| 59 | denoise (img2img strength) | denoising_strength | 0–1/.01 · .75 | W3.7 | … | planned |
| 60 | CFG end-at/restart-at/window (Forge-era) | — | — | — | — | D (proven-negatives family (e)) |
| 61 | TCD / regional seeds / per-axis weights / vary-region / diffuse-noise / Prompt-S-R standalone | — | — | — | — | **D PERMANENT core (e)** — extension-shaped home = W3.6/W3.8 only |
| 62 | Guidance (Flux true-CFG vs classic CFG) | dialect field Q-10 | W0.1 decides | W5.6-adjacent (video has its own cfg) | DTO extra via overrides today | D native-row until W0.1/live dialect proof |
| 63 | LoRA stack repeatable {model,strength,start,end} | — | Q-4 open | — | — | D (MultiSelect approximates via `<lora:>` prompt syntax; repeatable sub-form = new widget, backlog) |
| 64 | Video: num_frames (8k+1 ladder) | video dialect | 25–257 | W5.6 | JobClient params | planned (dialect per W0.1) |
| 65 | Video: fps | video | 16/24/25–30 | W5.6 | … | planned |
| 66 | Video: resolution classes | video | 480p–1080p | W5.6 (w/h presets row) | … | planned |
| 67 | Video: motion strength/bucket | video | 0–1 mapping | W5.6 | … | planned (mapping honesty docblock) |
| 68 | Video: i2v first-frame | video | slot | W5.6 | … | planned |
| 69 | Video: first+last / reference / v2v-init roles | video | — | — | — | D v1 (role enum recorded §6.2; dialect proof pending W0.1) |
| 70 | Video: two-expert boundary + dual CFG (Wan) | video | pair+slider | W5.6 cfg2-if-capability | … | planned-conditional |
| 71 | Video: flow shift / sample_shift | video | 1.7–12 family | W5.6 | … | planned |
| 72 | Video: STG scale/blocks | video | 0–2 | — | override-shaped | D v1 (capability-gated later) |
| 73 | Video: distilled/caching knobs (Lightning/teacache/TBCT) | video | advanced | — | overrides | D rows (advanced namespace per (e) doctrine) |
| 74 | Video: frame interpolation / video upscale post | scripts | RIFE etc. | generic scripts W3.6-if-exposed | … | covered-by-generic |
| 75 | Extras tab (single/batch upscale/GFPGAN/CodeFormer §2.13) | /extra-single-image | field set §4.4 | — | W1.3 methods ready | D v1 form (client-ready; `/generate extras` follow-on feature, backlog row) |
| 76 | Interrogate model picker (clip/deepbooru/blip) | /interrogate | enum | with #45 | … | D (see #45) |
| 77 | RNG source (randn GPU/CPU/NV) | override | radio | override W3.8 | infotext RNG rides W1.6 | covered-by-override |
| 78 | Batch PNG-info inheritance (img2img Batch tab) | — | checkbox group §2.6 | — | — | D (batch dir workflows out of console scope; note §2.13) |
| 79 | Gallery arrows/modal keys (§2.18) | — | s/←/→/Esc | transcript rows have their own | — | D (crush gallery pane = phase-2 Shape C note §7.4) |
| 80 | Generate-forever / right-click menus | — | contextMenus | — | — | D (no mouse-context menus infra in TUI v1; n/A §5.8 item 6) |
| 81 | Notification sound / title progress | — | js | — | — | D n/a console |
| 82 | ui-config.json display overrides | — | `<tab>/<Label>/<prop>` | W3.9 | — | planned |
| 83 | Presets save/load/apply | — | — | W3.9 | — | planned |
| 84 | render mode switch | — | setting | W2.2/W5.7 | — | planned |
| 85 | Infotext Version:/User:/backend stamps | info | §3.5 tail | W1.6 (+Sugar-crush stamp) | — | planned |
| 86 | save_txt sidecar | opts | — | — | — | D (Appendix C-3 autosave territory) |
| 87 | Grid file | — | prepend law | W4.5 | index_of_first_image | planned |
| 88 | force_task_id pin | force_task_id | string | — | W1.2 MediaJob | planned (client-side always) |
| 89 | include_init_images echo | include_init_images | bool | — | DTO D-list or override | D cosmetic |
| 90 | disable_extra_networks / comments / send_images flags | §4.2 misc | bools | — | client-internal where needed (send_images always true) | covered-by-client (no UI rows — record) |

(90 numbered rows ≈ the ~90-control catalog plus the §2.10 override family compressed at #57 and video supplements #64–#74; W7.1's job is exactly to re-verify row-by-row against code.)

# Appendix C — Decision records (fill at the named step; orchestrator lands edits here)

| # | Question | Ruled at | Decision |
|---|---|---|---|
| C-1 | Dialect strategy (sdapi-first vs OpenAI-first vs capability-detected) | W0.3 | **RULED 2026-10-09: capability-detected, A1111-sdapi-implemented-first; config-authority + fail-open probe — pre-committed recommendation STANDS.** Detail + derived `sglang-diffusion` requirement below. |
| C-2 | Mask editor scope (Q-5): upload-only v1 vs TUI brush | W4.6 / operator | **RULED: DESCOPED for v1** — upload-only path (default ruling); sketch brush editor revisited ONLY on explicit operator YES before Wave 4; W4.6 remains the YES-slot lane. |
| C-3 | Auto-save location (Q-4): `~/.sugar-crush/media/` vs workspace `.sugar-crush/media/` | W1.9 / operator | **RULED: HOME store `~/.sugar-crush/media/<session>/`** (plan default kept; 0700/0600 + `.partial`→rename per W1.9). Workspace opt-in revisit + save_txt sidecar stay Wave-7 territory. |
| C-4 | Audio (video playback): silent v1 vs ffplay child | W5.8 | **RULED: silent v1** (option A, plan recommendation); decision formally closes at W5.8; if (B) later — Wave-7 backlog line. |
| C-5 | Form chord policy / the Ctrl+Enter placeholder myth (⚖️C-2 source-side) | W3.4/W3.10 | **RULED: bind only chords verified free/working; neutral placeholder rows otherwise.** Ctrl+G verification at W3.10. (W3.4 step 5's "C-4" reference pre-dates renumber; this table is authoritative.) |
| C-6 | Video metadata carrier (Q-11): JSON sidecar vs mkv comment | W5.3 | **RULED: JSON sidecar next to saved media** (plan default); mkv-comment remains stretch only. |
| C-7 | Web media transport: tool.finished-ref field + RPC sliced fetch vs new event vs HTTP route | W6 intro | **RULED: `tool.finished` gains `media: list<MediaRef>` field — NOT a new event kind** (plan Wave-6 header stands: refs ride the event, bytes via RPC `media.fetch` offset/limit slicing). |

### C-1 evidence & derived requirements (W0.1 probe, `/tmp/opencode/plan-crush-media/w0/skynet/REPORT.md`)
- **Server positively identified:** skynet2:30001 = **SGLang-Diffusion serving Wan2.2-T2V — video-only** (FastAPI, `owned_by:"sglang"`, `is_image_gen:false`, task [T2V], output [VIDEO]). NO `/sdapi/v1/*` (live-404 proofs), NO ComfyUI routes, no auth challenge. Host truth: `skynet2.interserver.net` = 173.225.108.102; the prior brief's my-web-2 mention conflated hostnames with this devbox.
- **OpenAI-shape `/v1/images/*` PRESENT in schema but runtime-untested** (POST banned in probe; T2V model → route likely 4xx until an image model is swapped in).
- **Video async contract read off the live spec** (validates W5.2's submit/status/fetch shape): `POST /v1/videos` (multipart) → `VideoResponse {id,status,progress,url,file_path,seconds,fps,num_frames,error}`; `GET /v1/videos[/{id}][/{id}/content]`; `DELETE`.
- **EndpointFamily v1 requirement (binding on W1.2):** add an `sglang-diffusion` case — **DETECTABLE but UNIMPLEMENTED in v1**: probing it yields no implemented image/video capabilities, so tools fail with a clear unsupported-dialect message NAMING the detected family. Implementing sglang-diffusion video is the natural second dialect if the operator wants live video smoke (schema-feasible per W0.1; one sanctioned POST at W7.7).
- **Q-10 RESOLVED:** sdapi `cfg_scale` vs sglang `guidance_scale`/`true_cfg_scale` (+video `guidance_scale_2`) are **distinct per-dialect names**; steps = `num_inference_steps` on sglang; `negative_prompt` shared. MediaRequest keeps sdapi wire names; `Endpoints.php` glossary records the mapping.
- **Image live-smoke: BLOCKED-EXTERNAL** — operator must load an image-task model on 30001 (or point config at an sdapi server).

## W0 EXECUTION FINDINGS (2026-10-09) — binding amendments for Wave-1+ briefs

From `/tmp/opencode/plan-crush-media/w0/drift/REPORT.md` (146 anchors: 140 OK, 2 DRIFT, 1 MISSING-path, 1 CONTRADICTED):

- **⚠1 Command pattern superseded:** slash commands now ship via `sugar-crush/builtin-commands/NNNN-name.php` spec → `withHandler('handleXCommand')` → thin `Chat::handleX` (pattern :12965-12968) → `Host/Commands/XHostCommand` body. `/generate` takes slot **4000** (max live 3600-handoff). Chat dispatch is generic (`forSpelling`+match :12481-12492) — W0.2 step 4's / the plan's "copy the NoticesCommand trio" path is SUPERSEDED; `src/Commands/NoticesCommand.php` survives as a pure report class.
- **⚠2 Test bootstrap shape:** `sugar-crush/tests/bootstrap.php` pins `Loop::set(new StreamSelectLoop())` at :80 — **no LoopPin call exists to copy**; stdin repair `!stream_isatty` guard :613-619 (fd-0→/dev/null); `SuiteSkipRoster::install()` :44 runs first. Media tests inherit this shape; serial plain-pipe law unchanged.
- **⚠3 SSRF law location:** the LAN/loopback blocklist lives at `src/Tools/BuiltIn/WebFetch.php:21-49` + `BLOCKED_HOSTNAMES` :98 — `Permissions/FetchTarget.php` is only the permission-rule parser. SD-server clients must NEVER reuse the WebFetch dialer (mystage (c) holds, at the corrected file).
- **⚠4 Settings/positions:** definitions live in `src/Config/Settings/Definitions/` (13 siblings; W1.8 extends these — `src/Providers/Definitions/` does NOT exist); `SettingCategory` enum `src/Config/Settings/SettingCategory.php:22`; provider wire schemas at `ProviderFactory::TYPE_SCHEMAS` :89; `READ_ONLY_COMMANDS` const at `Chat.php:11525` (not CommandRegistry); LayeredSettings generated block live at **:474-587** (not :474-504). Tool positions re-verified: occupied 1-22,30,31,32 → **33/34 FREE** for GenerateImage/GenerateVideo.
- **Baseline floor (W0.4, `/tmp/opencode/plan-crush-media/w0/baseline/baseline.md`):** sugar-crush serial **21,198T/406,469A/0F/0E/1S exit0** @ HEAD `fdd4b40cc` (clean tree; linked 22/22; pipe-shaped keystone green); `scripts/parallel-tests-durations.tsv` 1,184 rows; siblings candy-forms 2,272T / candy-mosaic 661T / sugar-reel 552T / candy-core 1,246T all exit0; `check-path-repos` + `check-child-lifetimes` rc=0; `sugar-crush-web` is a **monorepo subdir** (same git repo, no nested `.git`, node_modules absent).

# Appendix D — Source-report paths (backing detail; cite, never copy wholesale)

| Unit | Path | Line count |
|---|---|---|
| A1111 source deep-dive | `.crush-media-reports/a1111-repo/REPORT.md` | 575 |
| A1111 wiki crawl | `.crush-media-reports/a1111-wiki/REPORT.md` | 434 |
| SugarCraft forms inventory | `.crush-media-reports/sc-forms/REPORT.md` | 302 |
| SugarCraft media/rendering stack | `.crush-media-reports/sc-media/REPORT.md` | 221 |
| crush architecture/seams + server probe | `.crush-media-reports/crush-arch/REPORT.md` | 197 |
| Consolidated synthesis | embedded verbatim in **Appendix F** of this plan | 1,327 |
| Sibling brief report | embedded verbatim in **Appendix G** of this plan | 415 |
| A1111 verification clone | `/home/sites/sdg-assets/stable-diffusion-webui` @ `82a973c` (read-only) | — |

Note: the five backing reports are preserved repo-relative under `.crush-media-reports/` (copied from ephemeral `/tmp` inputs 2026-10-08); the §2/§4 tables this plan cites are also reproduced verbatim in Appendix F.

# Appendix E — sugar-crush-web discovery findings (verified in-tree 2026-10-08)

- **Layout:** repo root `sugar-crush-web/` — Vue SPA in `src-web/`, PHP asset-server sidecar (`src/Assets.php`, `phpunit.xml`, `tests/`), built into `dist/`, Vite config `vite.config.ts`, `package.json` scripts: `dev build preview typecheck lint test e2e gen:protocol` (all verified). Node ≥20.19/22.12.
- **Generated protocol:** `src-web/protocol/generated.ts` (1,489 ln) header: `// GENERATED by scripts/gen-protocol.mjs from sugar-crush/docs/protocol/sugarcrush.v1.schema.json — do not edit.` Generator `sugar-crush-web/scripts/gen-protocol.mjs` (232 ln; deterministic; `--check` staleness exit 1; `SCHEMA_PATH` override; split-repo clones need not run it — file is committed). Upstream of the mjs: `sugar-crush/scripts/gen-protocol-schema.php` generates the schema JSON. **Chain for W6: PHP edits → php generator → npm generator → commit both outputs.**
- **Event consumption:** `src-web/stores/reducer.ts` (547 ln) — pure reducer over EventEnvelope; `case 'tool.started'` at :390, `case 'tool.finished'` at :407 mapping `toolCallId,name,isError,durationMs,content,truncated,diff,denial` (+identity/replaces) into `ToolItem` — **no media fields today (confirms the §6.4 "tool.finished drops imageBytes" finding).** Transport `src-web/protocol/client.ts` (376 ln), auth.ts, jsonrpc.ts, cursor.ts, reconnect.ts.
- **Tool rendering:** `src-web/components/ToolCard.vue` (182 ln) — arms: denial row :59, args `<details>` :60-63, `DiffView` :64, output `<pre data-testid="tool-output">` :65, **"Load the full output" button for truncated payloads :66 (the fetch-on-demand idiom MediaCard copies)**, SubAgentTree :68; props `{item: ToolItem; subagents?}`; scoped CSS vars theme. Mounted from Transcript.vue (119 ln)/SessionView.vue (probe exact mount point in W6.4).
- **Sanitization:** `src-web/lib/markdown.ts` (40 ln) — markdown-it `html:false` + DOMPurify `USE_PROFILES:{html:true}` `FORBID_TAGS:[style,form,input,button]` `ADD_ATTR:[target]` — img-from-markdown-syntax survives; raw-HTML injection blocked; **Wave 6 must not relax this** (media rides structured fields, not v-html).
- **Test setup:** unit = **vitest 5 + @vue/test-utils + happy-dom**, colocated `__tests__` dirs (`src-web/stores/__tests__/reducer.spec.ts`, `session.spec.ts`, `layout.spec.ts`, `approvals.spec.ts`, `narration.spec.ts`, `src-web/protocol/__tests__/{protocol,generated,client}.spec.ts`, `src-web/components/__tests__/components.spec.ts` (21 tests), `settings/__tests__/settings.spec.ts`, `approvals/__tests__/approvals.spec.ts`, agents store specs with `__tests__/harness.ts`). e2e = **Playwright 1.63**, `playwright.config.ts`, `e2e/` — specs: login, session, reconnect, settings, panels, multi-client, multi-session, dir-picker, highlight, `fixtures/`. TS strict via vue-tsc 3.3/TS 6.
- **Approvals (ask-relay reuse target for W6.6):** `src-web/stores/approvals.ts`, `src-web/components/approvals/{ApprovalsButton.vue, ApprovalsDrawer.vue, attention.ts}` — the permission-ask UI already crossing the wire exists; the structured-media-form experiment plugs here.
- **Settings form precedent (web-side widget patterns):** `src-web/components/settings/{SettingsField.vue, SettingsView.vue, SettingsPreview.vue}` + `stores/settings/{fields.ts, serverSettings.ts}` — how the web already renders schema-driven fields (relevant to W6.6 and W6.3's MethodSchemas consumption).
- **UNKNOWN — probe during W6:** exact SessionView mount lines for ToolCard, CSP header presence (index.html/vite/Assets.php), whether `generated.spec.ts` auto-follows regen or hand-pins counts, serve-boot e2e fixture mechanics (e2e/fixtures/ contents).

---

*End of plan. 61 build steps across 8 waves; every step carries probe-first anchors so a fresh-session coder agent needs no other context beyond this file + the cited source docs.*


---

# Appendix F — Consolidated research record (crush_media.md, embedded verbatim 2026-10-08; original file deleted). All "crush_media §N" citations in the plan resolve to the section numbers below.

# crush_media — Consolidated research: image & video generation for sugar-crush

**Created:** 2026-10-07 · **Type:** synthesis of five research reports (read-only inputs; nothing else in the repo touched) · **Provenance tags:** `[repo §…]` = `/tmp/opencode/crush-media/a1111-repo/REPORT.md`, `[wiki §…]` = `/tmp/opencode/crush-media/a1111-wiki/REPORT.md`, `[forms §…]` = `/tmp/opencode/crush-media/sc-forms/REPORT.md`, `[media §…]` = `/tmp/opencode/crush-media/sc-media/REPORT.md`, `[arch §…]` = `/tmp/opencode/crush-media/crush-arch/REPORT.md`.

## Table of contents

- [0. Executive summary, scope brief, key verdicts](#0-executive-summary-scope-brief-key-verdicts)
- [1. Server & capability discovery (skynet2 probe)](#1-server--capability-discovery-skynet2-probe)
- [2. A1111 tunables catalog — full per-tab control tables](#2-a1111-tunables-catalog--full-per-tab-control-tables)
- [3. Prompting syntax, PNG info metadata, infotext round-trip](#3-prompting-syntax-png-info-metadata-infotext-round-trip)
- [4. A1111 REST API contract](#4-a1111-rest-api-contract)
- [5. Extension/script pluggability model (pattern to mirror)](#5-extensionscript-pluggability-model-pattern-to-mirror)
- [6. Beyond-A1111: Flux / Qwen-Image / SD3.5 / LTX / Wan 2.2 tunable supplement](#6-beyond-a1111-flux--qwen-image--sd35--ltx--wan-22-tunable-supplement)
- [7. SugarCraft widget/component inventory for the form](#7-sugarcraft-widgetcomponent-inventory-for-the-form)
- [8. Image/video rendering stack (terminal display)](#8-imagevideo-rendering-stack-terminal-display)
- [9. sugar-crush integration seams](#9-sugar-crush-integration-seams)
- [10. Risks, constraints, drift-guard obligations, test strategy](#10-risks-constraints-drift-guard-obligations-test-strategy)
- [11. Open questions, phase plan, source-report appendix](#11-open-questions-phase-plan-source-report-appendix)

---

## 0. Executive summary, scope brief, key verdicts

### 0.1 Scope brief (the project this synthesis serves)

Add **image & video generation** support to sugar-crush (console TUI, PHP monorepo at `/home/sites/sugarcraft`) when the configured backend supports it. The user's server `http://skynet2.interserver.net:30001` currently runs **Flux 2 dev**; the box also hosts **Flux 1 dev, Qwen-Image, LTX 2.5, SD3.5-large** (images) and **LTX-Video, Wan 2.2** (video). Requirements:

1. A **popup form UI** exposing all tunables (sliders / dropdowns / radios / checkboxes / text inputs / textareas), modeled on the AUTOMATIC1111 wiki Features surface.
2. Modes: **text-to-image / image-to-image / inpaint / image-to-video / text-to-video**.
3. **Image display** best-available (iTerm2 / kitty / sixel / truecolor blocks / quarter-block / ascii) with **user-switchable render modes**.
4. **Video playback in-terminal**.
5. **Console first**, but server mode shaped for **sugar-crush-web phase-2 reuse**.

### 0.2 Key verdicts

- **A1111 surface is fully cataloged** (~90 generation-tab controls + ~25 sampler-params/settings tunables + ~30 REST endpoints), cloned at `/home/sites/sdg-assets/stable-diffusion-webui` @ `82a973c04` (2024-07-27, v1.10 line) [repo §header]. The wiki's named pages (Parameters, Settings, REST-API, …) no longer exist — content lives in **Features**, **Command-Line-Arguments-and-Settings**, **Developing-***, **Optimizations**, **Troubleshooting**, plus in-code tooltips, which the wiki crawl mined as the documentation of record [wiki §header, §1b].
- **The server is dark at probe time:** port 30001 refuses TCP connections; the host is up with 30000 (SGLang chat server), 22, 80, 443 open. Nothing ComfyUI/A1111-shaped was reachable on the swept ports. Integration must therefore treat **config as the capability authority and live probe as an opportunistic override (fail-open)** — the exact `SglangServerInfo` design already in crush [arch §1a–§1c].
- **There is no widget gap** for the requested form except a **tab bar** (G-1) and a **visible pill radio** (G-2): candy-forms already ships `Slider`, `Select` (incl. `withEnum()`), `MultiSelect`, `Confirm`, `Input`, `Text`, `FilePicker`, `Viewport`, and the whole `Form`/`Group`/`KeyMap` state machine — and candy-forms is already a production dependency of sugar-crush (`sugar-crush/composer.json:47`, re-verified 2026-10-07). Recommended hosting is **Shape A — a full-band App-owned modal view following `Tui/Settings/SettingsEditor.php` exactly** [forms §0, §2a].
- **Rendering is essentially solved in-tree:** candy-mosaic ports all seven requested display protocols (iTerm2, Kitty, Sixel, half-block, quarter-block, ASCII+256/truecolor, chafa) with probe-based detection, dithering, scaling, APNG/GIF animation, disk cache, and the `ImageOverlay` out-of-band paint channel that **sugar-crush already wires end-to-end for image-bearing tool results** (`ToolResult::$imageBytes/$imagePath/$imageProtocol` → `Renderer::renderView` → `ImageLayer::place` → `Program` paint). sugar-reel is a complete terminal video player but is **not yet a crush dependency**. Missing: a user-switchable `media.render_mode` setting (vocabulary already exists: `Mosaic::fromModeString`), a crush pane hosting the reel Player, image-upload request plumbing in Providers, and a PNG-encode helper [media §verdict, gaps 1–4].
- **Backend plumbing has a clean precedent lane:** the `embeddings()` capability is the existing second-capability pattern (dedicated DTO pair + capability flag + opt-in lazy consumer); the provider factory schema table is where `mediaKinds: [image|video]` slots in; heavy HTTP must run in the **forked tool-child** channel (`EngineBackend` pcntl + socket-pair + `ToolEventPumpMsg` progress pipeline), never on the ReactPHP loop thread; and there is **no image/video endpoint anywhere in crush today** (tree grep `images/generations|txt2img|image_gen` = 0 hits) [arch §2.1, §2.2, Risk 1].
- **Mirror A1111's architecture, not just its fields:** one declarative option registry projecting into form + config + CLI + API + PNG-info + paste (A1111's `OptionInfo` + `PydanticModelGenerator` + `infotext_to_setting_name_mapping`), section-placement + AlwaysVisible scripts for pluggable panel blocks, and a byte-compatible `parameters` PNG tEXt chunk for interop and re-generation round-trip [wiki §7.4, §12; repo §C.1, §E.6].

### 0.3 Conflict ledger (details inline where they arise)

Four cross-report (or self-report) contradictions were found and judged against the actual A1111 clone @ `82a973c` (read-only verification performed 2026-10-07):

| # | Topic | Positions | Verdict |
|---|---|---|---|
| ⚖️ C-1 | `inpainting_fill` enumeration | [repo §A.2] 4 values `0–3`; [repo §C.3] `0 fill,1 original,2 latent noise,3 latent,4 nothing` (5 values); [wiki §4.2] 4 values | **4 values (0=fill, 1=original, 2=latent noise, 3=latent nothing).** Verified: `modules/ui.py:702` radio `['fill','original','latent noise','latent nothing']`; `modules/processing.py:1567` default `0`; branch legs `:1696/:1699/:1749/:1753` reach only 0–3. The §C.3 row is a transcription error. |
| ⚖️ C-2 | Ctrl+Enter / Alt+Enter / Esc keyboard bindings | [repo §A.1, §E.4] claims bindings exist "toprow.js"; [wiki §6.4] "No Ctrl+Enter-to-generate binding exists in this codebase's JS" | **Wiki is right for the webui's own JS.** Verified: no `javascript/toprow.js` file exists at `82a973c`; `grep ctrlKey javascript/` matches only `edit-attention.js`. The textarea **placeholder string** in `ui_toprow.py` does advertise the shortcuts (both reports quote it), but the webui ships no handler — behavior, if any, would be delegated to the forked Gradio frontend and is not verifiable from this repo. Treat the placeholders as aspiration, the wiki's JS-verified list as the real keymap. |
| ⚖️ C-3 | ENSD default | [wiki §2.2] "setting default 31337"; [repo §A.5] default 0 | **0.** Verified: `modules/shared_options.py:399` `OptionInfo(0, "Eta noise seed delta", …)`. 31337 is the community-famous value some models/users set, not the code default. |
| ⚖️ C-4 | `uni_pc_order` slider range | [repo §A.5] Slider 1–50, default 3; [wiki §2.5] "1–7" | **1–50 step 1, default 3.** Verified: `modules/shared_options.py:404` `{"minimum": 1, "maximum": 50, "step": 1}`, `.info("must be < sampling steps")`. |

Non-conflicts worth pinning (both reports agree once read carefully): the **denoising-strength default is context-dependent** — img2img tab and API `denoising_strength` default **0.75**, while the **hires-fix UI slider** default is **0.7** [repo §A.1/§A.2/§C.2–C.3; wiki §2.1]. Steps default is **20 in UI vs 50 in the API class** [repo §C.2; wiki §2.1]. `inpaint_full_res_padding` is **32 in UI vs 0 in API class** [repo §A.2/§C.3; wiki §4.2].

---

## 1. Server & capability discovery (skynet2 probe)

Source: [arch Part 1] — live probe 2026-10-07 from probe host `my-web-2.interserver.net` (69.10.33.243) against target `skynet2.interserver.net` (173.225.108.102); read-only session, raw probe bodies under `raw/`.

### 1a. Port 30001 (the Flux/image server) — DOWN at probe time

Every path returned `HTTP 000`; the TCP handshake itself is refused:

```
* Trying 173.225.108.102:30001...
* connect ... port 30001 ... failed: Connection refused
```

DNS resolves (`173.225.108.102 skynet2.interserver.net`) and the host is up — a port sweep found **`30000 OPEN, 22 OPEN, 80 OPEN, 443 OPEN`**; `30001, 8188, 7860, 8000, 3000, 11434, 1234, 8189, 9000, 5001, 2888, 7861, 3002, 3003` closed/filtered. So **nothing is listening on 30001 right now** (service stopped, or port changed). No ComfyUI (8188) or A1111 (7860) surface elsewhere on the box was reachable either. [arch §1a]

Paths attempted on 30001 (all connection-refused, status `000`):

`/  /v1  /v1/models  /models  /health  /healthz  /ready  /docs  /redoc  /openapi.json  /sdapi/v1/options  /sdapi/v1/sd-models  /sdapi/v1/txt2img  /sdapi/v1/img2img  /v1/images/generations  /v1/images/edits  /v1/chat/completions  /v1/completions  /v1/embeddings  /v1/media  /media  /capabilities  /model_list`

Curl-call budget: ~40 low-level requests total (23 to a dead port before the refusal was diagnosed); GET/HEAD/TCP-connect only, **zero mutating requests**. [arch §1c]

### 1b. Port 30000 — what IS running there (SGLang chat inference server)

`GET /` → body `"SGLang is running"` (19 bytes, `raw/root.txt`).

`GET /model_info` → HTTP 200, 1392 bytes, VERBATIM (`raw/model_info.json`):

```json
{"model_path":"Qwen/Qwen3.8-Flash-Next-FP8","served_model_name":"Qwen/Qwen3.8-Flash-Next-FP8","tokenizer_path":"Qwen/Qwen3.8-Flash-Next-FP8","is_generation":true,"preferred_sampling_params":null,"weight_version":"default","load_format":"auto","reasoning_parser":"qwen3","tool_call_parser":"qwen3_coder","disaggregation_mode":"null","has_image_understanding":false,"has_audio_understanding":false,"model_type":"qwen4_exp","architectures":["Qwen4ExpForConditionalGeneration"],"embedding":{"family":"none","task":"none","execution":"none","attention":"none","pooling":"model_defined","normalize":false,"postprocessor":"none","tokenizer_special_tokens":"model_default","supports_dimensions":false,"supports_token_embeddings":false,"supports_multimodal":false,"requires_embedding_flag":false,"auto_enable_embedding":false,"bcg_eligibility":"disabled","safe_disable_kv_cache":false,"safe_disable_radix_cache":false,"safe_disable_chunked_prefill":false,"enabled":false,"matryoshka_dimensions":[],"bcg":{"prefill_backend":"disabled","enabled":false,"capture_token_budget":4096,"capture_batch_sizes":[4,8,12,16,20,24,28,32,48,64,80,96,112,128,144,160,176,192,208,224,240,256,288,320,352,384,416,448,480,512,576,640,704,768,832,896,960,1024,1280,1536,1792,2048,2304,2560,2816,3072,3328,3584,3840,4096]},"cache":{"kv_cache_disabled":false,"radix_cache_disabled":false,"chunked_prefill_disabled":false}}}
```

`GET /v1/models` → HTTP 200, 196 bytes, VERBATIM (`raw/v1_models.json`):

```json
{"object":"list","data":[{"id":"Qwen/Qwen3.8-Flash-Next-FP8","object":"model","created":1791411778,"owned_by":"sglang","root":"Qwen/Qwen3.8-Flash-Next-FP8","parent":null,"max_model_len":1000000}]}
```

Notable keys for capability discovery: `is_generation`, `has_image_understanding`, `tool_call_parser`/`reasoning_parser`, and the whole `embedding` block (SGLang's own capability-negotiation surface). These endpoints live at the **server root**, not under `/v1` — and sugar-crush already knows this: `src/Providers/SglangServerInfo.php` docblock records measurements against **skynet2 on 2026-10-02** with fixtures `tests/fixtures/sglang-{model-info,server-info}-qwen3.8.json`. [arch §1b]

### 1c. What the Flux/image server most likely exposes (re-probe checklist for when 30001 returns)

Flux/sd3.5/wan/qwen-image behind an SGLang-family stack ⇒ expected shape mirrors 30000: root-level `GET /model_info` for discovery, and SGLang-Diffusion's **OpenAI-compatible `POST /v1/images/generations`** (+ `/v1/images/edits`) as the generation surface; a ComfyUI frontend would instead show `/prompt`, `/history`, `/view`, `/object_info` on 8188-style ports. **Integration must treat discovery as override, not dependency**: declare capability in config (§9.6), probe opportunistically (like `SglangServerInfo`'s 3.0 s total-timeout discovery GETs), and **fail-open to the config truth when the server is offline** — exactly today's 30001 situation. [arch §1c]

### 1d. Expected model roster (from the project brief — must be re-probed / declared, not assumed)

| Model | Kind | Notes from brief + §6 supplement |
|---|---|---|
| Flux 2 dev | image (currently served on :30001) | guidance-distilled true-CFG class model; steps sweet-spot ~20–28 [repo §D.1] |
| Flux 1 dev | image | same family; FLUX.1-Fill/Canny/Depth/Redux/Kontext variants [repo §D.3] |
| Qwen-Image | image | true-CFG ≈4.0 + shift ≈3.1; Qwen-Image-Edit 2509 multi-image instruction edit [repo §D.1, §D.3] |
| SD3.5-large | image | guidance ≈4.5; multi-text-encoder, T5-XXL drop option [repo §D.1] |
| LTX 2.5 / LTX-Video | video | frame ladder 8k+1 (121/193/257); shift ≈1.7–3; STG option [repo §D.2] |
| Wan 2.2 | video | 81/121-frame classes; two-expert (high/low-noise) split + boundary; dual CFG; `sample_shift` 3–12 [repo §D.2] |

The SGLang surface at 30000 exposes **chat only** for `Qwen/Qwen3.8-Flash-Next-FP8` (`has_image_understanding: false`) — nothing chat-side implies media availability; media capability is a **separate endpoint family** to be declared per-provider. [arch §1b, §2.1]

---

## 2. A1111 tunables catalog — full per-tab control tables

**Reading notes (version deltas that matter for our port)** [repo §header]:

- In this (final) A1111 architecture, "Restore faces", "Tiling" and "Initial noise multiplier" are **Settings (opts)** with per-request override via `override_settings` — no longer tab checkboxes (they were checkboxes ≤v1.8; the API keeps explicit `restore_faces`/`tiling` fields). The "checkboxes" UI category still exists but renders empty.
- Sampling steps / sampler / scheduler / seed (+extras) are rendered by built-in *UI scripts* in `modules/processing_scripts/` (sampler.py, seed.py, refiner.py, comments.py) — the same Script mechanism extensions use. This is the pattern to mirror for sugar-crush's pluggable generation params.
- API request bodies are **generated dynamically** from `StableDiffusionProcessing*.__init__` signatures (pydantic v1 `create_model`), minus `API_NOT_ALLOWED`.

Controls inventoried: ~90 generation-tab controls (txt2img ~35, img2img ~45 incl. mask editor/modes, Extras/Postprocessor ~15, PNG Info read-only) + ~25 sampler-params/settings-level tunables + full REST API surface (~30 endpoints) [repo §header].

### 2.1 txt2img "Generation" tab [repo §A.1]

Layout: Toprow (prompts) → left settings column by categories (ordered via `ui_reorder_list` opt): `prompt, dimensions, cfg, checkboxes, accordions, batch, override_settings, scripts`. Right: output panel (gallery, info, buttons).

| Control | Label (elem_id) | Widget | Min/Max/Step | Default | Effect |
|---|---|---|---|---|---|
| Prompt | "Prompt" (`txt2img_prompt`) | Textarea (lines=3, no label, placeholder "Prompt (Press Ctrl+Enter to generate, Alt+Enter to skip, Esc to interrupt)") | — | "" | Positive prompt; supports prompt-weights `(a:1.1)`, `[a]`, `<lora:…>` via prompt_parser; `#` comments (opts.enable_prompt_comments); `<random:…>`, `<option:…>`, embeddings `{token}` etc. |
| (hidden) prompt image | `txt2img_prompt_image` | File (binary, hidden) | — | — | paste-drag image → base64 into prompt (`image_data`) |
| Negative prompt | "Negative prompt" (`txt2img_neg_prompt`) | Textarea lines=3 | — | "" | Unconditional branch of CFG |
| Styles | Dropdown (`txt2img_styles`) multiselect | Dropdown(multi) | choices = prompt styles DB | `["Default"]` | Wrap/append prompt+neg per style (`ui_prompt_styles`) |
| Apply style button | 📋 (`txt2img_style_apply`) | ToolButton | — | — | apply selected styles |
| Save style button | 💾 (`style_save`) | ToolButton | — | — | save current prompts as named style |
| Refresh styles | 🔄 (`style_refresh`) | ToolButton | — | — | reload styles json |
| Token counter | `txt2img_token_counter` / `txt2img_neg_token_counter` | HTML (hidden until clicked) | — | "0/75" | counts CLIP tokens incl. schedules/styles/extra-networks |
| Paste button | ↙ (`paste`) | ToolButton | — | — | pull params from prompt text or last gen |
| Clear prompt | 🗑️ (`txt2img_clear_prompt`) | ToolButton | — | — | JS-confirm clear |
| Generate | "Generate" (`txt2img_generate`) | Button primary | — | — | submit (`_js submit`; right-click = generate-forever menu) |
| Interrupt | `txt2img_interrupt` / `txt2img_interrupting` | Buttons | — | — | `shared.state.interrupt()`; 2-click → `stop_generating()` after current image when `interrupt_after_current` |
| Skip | `txt2img_skip` | Button | — | — | `shared.state.skip()` ends current batch item |
| Width | "Width" (`txt2img_width`) | Slider | 64–2048 / 8 | 512 | latent W×8 |
| Height | "Height" (`txt2img_height`) | Slider | 64–2048 / 8 | 512 | latent H×8 |
| ⇅ | Switch width/height (`txt2img_res_switch_btn`) | ToolButton | — | — | JS swap |
| Batch count | "Batch count" (`txt2img_batch_count`) | Slider | 1–(no max in UI; inf) / 1 | 1 | `n_iter`: sequential batches (re-rolls seed each) |
| Batch size | "Batch size" (`txt2img_batch_size`) | Slider | 1–8 / 1 | 1 | `batch_size`: parallel images per pass |
| CFG Scale | "CFG Scale" (`txt2img_cfg_scale`) | Slider | 1.0–30.0 / 0.5 | 7.0 | classifier-free guidance scale |
| Hires. fix (accordion) | InputAccordion "Hires. fix" (`txt2img_hr`) | Checkbox-as-accordion | — | off | `enable_hr`: 2-pass gen |
| ↳ Upscaler | "Upscaler" (`txt2img_hr_upscaler`) | Dropdown | choices = latent modes (Latent, Latent (antialiased), Latent (bicubic), Latent (bicubic antialiased), Latent (nearest), Latent (nearest-exact)) + all `sd_upscalers` names | "Latent" | `hr_upscaler` between passes |
| ↳ Hires steps | "Hires steps" (`txt2img_hires_steps`) | Slider | 0–150 / 1 | 0 (0 = same as steps) | `hr_second_pass_steps` |
| ↳ Denoising strength | "Denoising strength" (`txt2img_denoising_strength`) | Slider | 0.0–1.0 / 0.01 | 0.7 | `denoising_strength` of 2nd pass |
| ↳ Upscale by | "Upscale by" (`txt2img_hr_scale`) | Slider | 1.0–4.0 / 0.05 | 2.0 | `hr_scale` multiplier |
| ↳ Resize width to | "Resize width to" (`txt2img_hr_resize_x`) | Slider | 0–2048 / 8 | 0 (0=use scale) | `hr_resize_x` absolute target |
| ↳ Resize height to | "Resize height to" (`txt2img_hr_resize_y`) | Slider | 0–2048 / 8 | 0 | `hr_resize_y` |
| ↳ Hires checkpoint | "Checkpoint" (`hr_checkpoint`) | Dropdown+refresh | "Use same checkpoint" + ckpt tiles | same | 2nd-pass model |
| ↳ Hires sampling method | "Hires sampling method" (`hr_sampler`) | Dropdown | "Use same sampler" + visible samplers | same | `hr_sampler_name` |
| ↳ Hires schedule type | "Hires schedule type" (`hr_scheduler`) | Dropdown | "Use same scheduler" + schedulers | same | `hr_scheduler` |
| ↳ Hires prompt | `hires_prompt` | Textarea lines=3 | — | "" | 2nd-pass positive |
| ↳ Hires negative | `hires_neg_prompt` | Textarea lines=3 | — | "" | 2nd-pass negative |
| (row html) | `txtimg_hr_finalres` | HTML | — | computed | "from WxH to WxH" preview (`calculate_target_resolution`) |
| Sampling method | "Sampling method" (`txt2img_sampling`) | Dropdown (or Radio when `!samplers_in_dropdown`) | all visible samplers (§2.9) | "Euler" (first) | `sampler_name` |
| Schedule type | "Schedule type" (`txt2img_scheduler`) | Dropdown | Automatic, Uniform, Karras, Exponential, Polyexponential, SGM Uniform, KL Optimal, Align Your Steps, Simple, Normal, DDIM, Beta | "Automatic" | `scheduler` |
| Sampling steps | "Sampling steps" (`txt2img_steps`) | Slider | 1–150 / 1 | 20 | `steps` |
| Seed | "Seed" (`txt2img_seed`) | Number (precision 0) — or Textbox with `--use-textbox-seed` | int64 | **-1** | -1 = fresh random each run; fixed = reproducible |
| 🎲️ / ♻️ | randomize / reuse (`random_seed`,`reuse_seed`) | ToolButtons | — | — | set -1 / copy last-used seed from generation info |
| Extra (checkbox) | "Extra" (`txt2img_subseed_show`) | Checkbox | — | off | reveals seed extras group |
| ↳ Variation seed | "Variation seed" (`txt2img_subseed`) | Number | int64 | -1 | `subseed` |
| ↳ Variation strength | "Variation strength" (`txt2img_subseed_strength`) | Slider | 0–1 / 0.01 | 0.0 | blends noise → subseed noise |
| ↳ Resize seed from width | `txt2img_seed_resize_from_w` | Slider | 0–2048 / 8 | 0 | `seed_resize_from_w` (both must be >0) |
| ↳ Resize seed from height | `txt2img_seed_resize_from_h` | Slider | 0–2048 / 8 | 0 | `seed_resize_from_h` |
| Override settings | "Override settings" (`txt2img_override_settings`) | Dropdown multiselect | all opts keys (hidden until chosen) | — | per-request settings overrides |
| Scripts dropdown | (`txt2img_script_dropdown`) | Dropdown | built-in + extension scripts | "None" | see §5 |
| Refiner (accordion) | InputAccordion "Refiner" (`txt2img_enable`) | Checkbox-accordion | — | off | SDXL base→refiner mid-generation swap |
| ↳ Refiner checkpoint | "Checkpoint" (`refiner_checkpoint`) | Dropdown+refresh | ckpt tiles | "" | refiner model |
| ↳ Switch at | "Switch at" (`refiner_switch_at`) | Slider | 0.01–1.0 / 0.01 | 0.8 | fraction of steps when to swap; 1=never |
| Upscale (output-panel button) | "Upscale" (`txt2img_upscale`) | Button (per `additional_repeatable_buttons`/opts) | — | — | txt2img_upscale: re-runs batch on gallery image as hires |
| Extra networks tabs | 🎴 + tabs (`txt2img_extra_tabs`) | Tabs overlay | — | — | Lora/TextualInversion/Hypernetwork/etc. insertion into prompt |
| Send to img2img/sketch/inpaint/etc. | output panel buttons | Buttons | — | — | `parameters_copypaste` "send to" plumbing |

Checkbox-row in current version: empty (`category == "checkboxes": pass`). Face restoration + tiling now live in Settings→Stable-Diffusion (`face_restoration`, `tiling`) with per-request override (API keeps explicit fields). See §2.7/§2.8. [repo §A.1]

### 2.2 Core generation parameters — semantics of record [wiki §2.1]

| Parameter | Control (code of record) | Semantics |
|---|---|---|
| Prompt | Textarea per tab | §3.1 grammar. Attention → schedule → composable-AND parsing, then CLIP 75-token chunking. |
| Negative prompt | Second textarea in collapsible accordion | Conditioning that *replaces* the unconditional CFG branch. `Negative-prompt.md` = usage guidance; mechanics = CFG formula below. |
| Steps | Slider 1–150 step 1, default **20** in UI (API class default 50, `processing.py`) | Denoising iterations = sigma-schedule length; also the timeline for prompt-scheduling integers (§3.3). |
| CFG Scale | Slider **1–30 step 0.5 default 7** (`ui.py:303`) | Classifier-free guidance: `denoised = uncond + cfg·(cond − uncond)`. 1 = guidance off. Higher = stronger prompt pull, contrast/saturation artifacts past ~12–15 (wiki guidance). |
| Image CFG Scale | Slider **0–3 step 0.05 default 1.5**, hidden unless InstructPix2Pix model (`ui.py:668`) | Third conditioning leg (input-image guidance) for pix2pix edits. |
| Denoising strength | Slider 0–1 step 0.01 default 0.75 (img2img, hires 2nd pass) | Fraction of the sigma schedule traversed: 0 = unchanged input, 1 = from pure noise; sets start timestep. Hires: applies to 2nd pass only. |
| Sampler | Dropdown "Sampling method" (radio alternative per setting) | Families §2.9. Tooltips mark **ancestral** (stochastic re-noising, seed-breaking across versions) vs deterministic. |
| Scheduler | Dropdown "Schedule type", default Automatic | Sigma schedule decoupled from solver: Automatic (per-sampler default), Uniform, Karras (internal ρ=7), Exponential, Polyexponential, SGMUniform, Beta (`beta_dist_alpha/beta` settings), DDIM, KL Optimal, Simple, Normal. **No user rho slider in core** — ρ baked per schedule (`sd_schedulers.py default_rho`); per-sampler default is chosen automatically. |
| Width/Height | Sliders 64–2048 step 8 default 512 | Step-8 = VAE latent alignment; SDXL native 1024; >~2× native trains-off composition artifacts; Tiling for seams (§2.10). |
| Batch count / size | count slider (loop iterations); size 1–8 (API default batch_size=1, n_iter=1) | size = images per forward pass sharing one conditioning run; count = sequential generations. Seed advances per image within the batch RNG. |
| Restore faces | Setting + CodeFormer weight visibility slider (was per-image checkbox ≤1.8) | §2.7. |
| Tiling | Setting (`tiling`, infotext "Tiling"; was per-image checkbox) | Circular conv padding in attention/upscale paths ⇒ seamless horizontal/vertical wrap. Enables "latent upscale" resize mode and texture work. |
| Seed | Number slider (or textbox `--use-textbox-seed`), default −1 | −1 ⇒ `int(random.randrange(4294967294))` per generation (`processing.py:685` — ~32-bit, not 64). Pinned seed = reproducible **only** within identical version/attention/RNG-device regime (§3.7). |
| Extra → Variation seed (subseed) + Variation strength | strength slider 0–1 step 0.01 default 0 | §2.3 slerp blending. |
| Extra → Resize seed from width/height | two sliders 0–2048 step 8 | §2.3 crop/pad noise field. |
| Hires. fix | checkbox + accordion: Upscaler dropdown (latent modes §2.8 + pixel models), Hires steps (0–150, 0 = same), Denoising 0–1 step .01 def .7, Upscale by 1–4 step .05 def 2, Resize width/height to 0–2048 step 8; reveal toggles for alt checkpoint/sampler/scheduler (`hires_fix_show_sampler`) and hires prompt/negative (`hires_fix_show_prompts`) | §2.4 two-pass math. |
| Refiner (SDXL) | Always-visible script block (`ui_refiner`): checkpoint dropdown + **Switch at** slider 0–1 | Base model runs until the fraction `switch_at` of the step schedule, then the refiner checkpoint continues at the low-sigma tail. |
| Override settings | multiselect dropdown (`ui.py`) + API `override_settings` | Any core/extension setting, per-request, auto-restored (§5.5). |
| Token merging ratio / (hires) | Sliders 0–1 | ToMe-style attention token merging for speed. |
| VAE / CLIP skip / etc. | quicksettings row | §5.5 (default quicksettings = `["sd_model_checkpoint"]`, DropdownMulti of **all** option keys — the user picks what shows at top). |

### 2.3 Seed behaviors: incremental, reuse, cache, extra [wiki §2.2, `rng.py` verbatim]

- `ImageRNG(shape, seeds, subseeds, subseed_strength, seed_resize_from_h/w)` — one generator per image: `seed + i` for i-th image in the batch ⇒ **incremental seeds across a batch**. Random seed = ~32-bit `randrange(4294967294)`.
- **Reuse:** ♻ ToolButton, tooltip verbatim: *"Reuse seed from last generation, mostly useful if it was randomized"* (`processing_scripts/seed.py`); 🎲 = *"Set seed to -1, which will cause a new random number to be used every time"*.
- **Variation/subseed:** `noise = slerp(subseed_strength, randn(seed), randn(subseed))` — spherical interpolation between two Gaussian fields (falls back to lerp when cosine-similarity > 0.9995). 0 = pure main-seed noise, 1 = pure subseed noise; in-between keeps *variance structure* ⇒ "same image, one variation around it".
- **Seed resize:** initial noise generated at `seed_resize_from_h/w` latent size, then **center-cropped or center-pasted** into the target latent (crop if larger, zero-pad if smaller) ⇒ same seed, different resolution ≈ same composition.
- **ENSD** (`eta_noise_seed_delta`, setting **default 0** — ⚖️ C-3 resolved against the wiki's 31337 claim; `shared_options.py:399` verified; infotext `ENSD`): for ancestral samplers, per-iteration RNG is re-seeded from `seed + N·Δ` so repeated images in one run are **decorrelated but seed-derived** (Features/Seed-breaking doc: mitigates ancestral limb-duplication artifacts). Only emitted to PNG info for samplers with `uses_ensd` (Euler a, DPM2 a, DPM++ 2S a, DPM fast/adaptive). Tooltip verbatim: *"ENSD; does not improve anything, just produces different results for ancestral samplers - only useful for reproducing images"*.
- **Cache/determinism:** RNG source is a setting, not flag — `randn_source`: Radio GPU(default)/CPU/NV, infotext `RNG`, tooltip verbatim: *"changes seeds drastically; use CPU to produce the same picture across different videocard vendors; use NV to produce same picture as on NVidia videocards"*. There is **no noise cache**; noise is deterministically regenerated from the seed.

### 2.4 Hires-fix pipeline math [wiki §2.3, `processing.py` Txt2Img.sample + `calculate_target_resolution`]

1. First pass txt2img at (width,height).
2. Target second-pass size: both `hr_resize_x/y == 0` ⇒ `hr_scale` multiplier (floor of w·scale); exactly one nonzero ⇒ that dimension is the target, aspect preserved; both nonzero ⇒ **at least** those dims, then center-crop (legacy `use_old_hires_behavior` = exact resize).
3. Upscale first-pass image with the chosen upscaler — pixel GAN/PIL model **or** one of six `Latent (…)` modes that resize the latent directly (§2.8) — then re-encode (pixel path) as img2img init.
4. Second img2img pass with `denoising = hires denoising`, `hr_second_pass_steps` (0 = main steps), optional alt checkpoint/sampler/scheduler/**prompt pair** (`hr_prompt`/`hr_negative_prompt` — empty = same; schedule `when` values 1.0–2.0 address this pass, §3.3).
5. Infotext contributions `Hires upscale`, `Hires upscaler`, `Hires steps`, hires prompt/negative arrive via **`extra_generation_params`** — a dict any script fills with str | per-image list | **callable evaluated with the live locals** (Features' `get_hr_prompt` example: read the *effective* prompt after scheduling per image).
6. `initial_noise_multiplier` also applies to the hires pass (§2.5).
7. txt2img gallery has a ✨ ToolButton: *"Create an upscaled version of the current image using hires fix settings"* (`ui_common.py:203`, `if tabname == 'txt2img'`) — re-runs pass 2–4 from the finished image without regenerating pass 1.

### 2.5 Initial noise multiplier / Extra noise [wiki §2.4]

- **`initial_noise_multiplier`** (img2img param, default = same-named setting, 1.0 = off): scales the noise tensor added to the encoded init latent at the pass start (`<1` ⇒ sticks closer to source image, `>1` ⇒ more deviation). Features' "Extra noise" narrative: legacy *Noise multiplier for img2img* was superseded by **Extra noise multiplier for img2img and hires fix** (1.6.0, PR #12564) — must be **lower than the denoising strength**, used to re-inject detail into hires passes ("cross between GAN upscaling and latent upscaling"); callback `on_extra_noise(x, noise)` lets extensions spatially mask the extra noise (Features cites catboxanon gist).

### 2.6 img2img "Generation" tab controls [repo §A.2]

Same toprow + sampler/seed scripts (elem ids `img2img_*`). Additional controls:

| Control | Label (elem_id) | Widget | Range/choices | Default | Effect |
|---|---|---|---|---|---|
| Mode tabs | `mode_img2img` | Tabs: **img2img / Sketch / Inpaint / Inpaint sketch / Inpaint upload / Batch** + hidden `img2img_selected_tab` Number | — | img2img | which init/mask pipeline |
| Source image | `img2img_image` | gr.Image tool="editor", RGBA, height=opts.img2img_editor_height (default 512 in this table; 720 in `shared_options.py` — see §2.11 opts note) | — | sample if exists | init image; in-webui draw/annotate via editor brush |
| Copy-image-to buttons | "Copy image to: img2img/sketch/inpaint/inpaint sketch" | Buttons | — | — | move image between tabs |
| Sketch | `img2img_sketch` | gr.Image tool="color-sketch", RGB, brush_color=opts.img2img_sketch_default_brush_color | — | — | paint w/ solid color → i2i |
| Inpaint | `img2maskimg` | gr.Image tool="sketch", RGBA, brush_color=opts.img2img_inpaint_mask_brush_color | — | — | image + drawn alpha mask |
| Inpaint sketch | `inpaint_sketch` | gr.Image tool="color-sketch", RGB, brush=opts.img2img_inpaint_sketch_default_brush_color | — | — | color-shape = mask |
| Inpaint upload (base) | `img_inpaint_base` | Image upload pil | — | — | base image |
| Inpaint upload (mask) | `img_inpaint_mask` | Image upload RGBA | — | — | external mask file |
| Batch tab | `img2img_batch_upload` Files / `img2img_batch_input_dir` / `img2img_batch_output_dir` / `img2img_batch_inpaint_mask_dir` | Files + Textboxes | — | — | batch img2img from dir/upload; optional per-image masks dir |
| Batch PNG info | `img2img_batch_use_png_info` Checkbox "Append png info to prompts"; `img2img_batch_png_info_dir` Textbox; `img2img_batch_png_info_props` CheckboxGroup [Prompt, Negative prompt, Seed, CFG scale, Sampler, Steps, Model hash] | Checkbox/Textbox/CheckboxGroup | — | off | reuse params embedded in batch inputs |
| Resize mode | "Resize mode" (`resize_mode`) | Radio (index) | 0 Just resize / 1 Crop and resize / 2 Resize and fill / 3 Just resize (latent upscale) | "Just resize" | how init image is fit to target W/H (`resize_mode`) |
| Dimensions tab "Resize to" | width/height sliders (`img2img_width/height`) | Sliders | 64–2048/8 | 512 | target size |
| 📐 detect size | `img2img_detect_image_size_btn` | ToolButton | — | — | copy source resolution into W/H |
| Tab "Resize by" | "Scale" (`img2img_scale`) | Slider | 0.05–4.0 / 0.05 | 1.0 | `scale_by` → sets W/H = source×scale (selected_scale_tab 1) |
| ⇅ | `img2img_res_switch_btn` | ToolButton | — | — | swap W/H |
| Denoising strength | "Denoising strength" (`img2img_denoising_strength`) | Slider | 0.0–1.0 / 0.01 | **0.75** | strength of init-image corruption |
| CFG Scale | `img2img_cfg_scale` | Slider | 1–30 / 0.5 | 7.0 | as txt2img |
| Image CFG Scale | "Image CFG Scale" (`img2img_image_cfg_scale`) | Slider | 0–3 / 0.05 | 1.5 (visible only when `sd_model.cond_stage_key == "edit"`, i.e. sd-edit models) | separate guidance for image conditioning |
| Batch count / size | `img2img_batch_count/size` | Sliders | 1–…/1, 1–8/1 | 1/1 | as txt2img |
| Mask blur | "Mask blur" (`img2img_mask_blur`) | Slider | 0–64 / 1 | 4 | gaussian blur radius on mask edges (px) |
| Mask transparency | "Mask transparency" (`img2img_mask_alpha`) | Slider | 0–1 | 0.3 (JS-side) | **editor-only** alpha of overlay; never reaches processing |
| Mask mode | "Mask mode" (`img2img_mask_mode`) | Radio(index) | "Inpaint masked" / "Inpaint not masked" | Inpaint masked | `inpainting_mask_invert` 0/1 |
| Masked content | "Masked content" (`img2img_inpainting_fill`) | Radio(index) | fill / original / latent noise / latent nothing | "original" | what the masked area is replaced with before denoising (`inpainting_fill` 0–3) — ⚖️ C-1 |
| Inpaint area | "Inpaint area" (`img2img_inpaint_full_res`) | Radio(index) | "Whole picture" / "Only masked" | Whole picture | `inpaint_full_res`: crop to mask bbox at full res |
| Only masked padding, pixels | "Only masked padding, pixels" (`img2img_inpaint_full_res_padding`) | Slider | 0–256 / 4 | 32 | `inpaint_full_res_padding` (when radio "Only masked" this slider label switches to "Masked padding, % of side length" — the % semantics is a JS label swap only; value passed through; see img2img.py) |
| inpaint_controls group | FormGroup `inpaint_controls` visible only on tabs 2,3,4 (Inpaint / Inpaint sketch / Inpaint upload) | — | — | — | mask params auto-hide elsewhere |
| Override settings / scripts / refiner / sampler / seed | same as txt2img (`img2img_*`) | | | | |
| Interrogate CLIP 📎 / DeepBooru 📦 | `interrogate`/`deepbooru` | ToolButtons | — | — | caption init image → prompt (batch-mode: dir→txt) |

Sketch/inpaint eraser tools: provided by Gradio canvas JS (`javascript/`): draw mode rectangle/fill are extra in-webui JS tools over the editor — brush size, draw rectangle (Shift), fill (bucket) all client-side; server just receives the composited RGBA image and splits alpha into mask (`modules/masking.py`: `split_segmask`/`get_with_alpha`). [repo §A.2]

### 2.7 Inpainting / mask pipeline semantics [wiki §4.1–§4.3]

**Mask creation paths (6 img2img subtabs, `ui.py` verbatim):** img2img (image editor), Sketch (Color Sketch: brush color *is* the conditioning), Inpaint (sketch editor + mask brush), Inpaint sketch, Inpaint upload (separate base + mask images), Batch. Upload conventions: hand-drawn brush, transparent PNG (alpha defines mask), or plain B/W PNG where **white = inpaint** (mask decoded via PIL `convert("L")` → /255 float — bright = inpaint; `processing.py:344`).

Mask pipeline facts:

- `mask_blur` (slider 0–64, default 4; separate X/Y params exist in `processing.py` — `mask_blur_x`/`mask_blur_y` — single slider in UI) — Gaussian blur on the pixel-space mask; **`mask_round`** (processing param, default True — *not* a UI toggle in core): rounds the blurred mask back to binary 0/1 before conditioning (`torch.round`) — i.e. blur only survives when round is off (soft-inpainting ext / openOutpaint latent_mask use).
- No brush-size slider in this core UI — Gradio sketch default brush; blur+round are the documented edge controls. **Invert** = the *Mask mode* radio, not a canvas button.
- **Soft inpainting built-in extension** (`extensions-builtin/soft-inpainting`, AlwaysVisible script on img2img, title "Soft Inpainting"): uses the *unrounded* blurred mask for seamless blending; UI block: enable accordion + sliders **Mask blend power** (0–8 step .1), **Mask blend scale** (0–8 step .05), **Inpaint detail preservation** (1–32 step .5), pixel-composite settings; in-app help: *"seamlessly blend original content with inpainted content according to mask opacity. High Mask blur values are recommended!"*.

**Inpaint parameters (`ui.py:680-703` radios + processing params):**

| Control | Values | Semantics |
|---|---|---|
| Mask mode | radio `Inpaint masked` / `Inpaint not masked` | `inpainting_mask_invert` 0/1 — flips mask before use |
| Masked content | radio `fill`,`original`,`latent noise`,`latent nothing` (default **original** in UI; API default 0=fill) | What the inpaint region of the *conditioning image* is set to (pre-encode). `fill` = blur+pad pixel fill; `latent noise` = fresh randn in region; `latent nothing` = zero latents. ⚖️ C-1: exactly four values, 0–3 (verified `ui.py:702`, `processing.py:1567` + branch legs). |
| Inpaint area | radio `Whole picture` / `Only masked` | `inpaint_full_res`: Only masked crops to mask bbox expanded by **`inpaint_full_res_padding`** (slider 0–256 step 4, UI default 32; API class default 0), generates at model res, pastes back inside the full image |
| Mask blur / Mask transparency | slider 0–64 / visual-only alpha slider | latter purely display |
| Dedicated inpaint model path | automatic when checkpoint is an inpainting model (config `conditioning_config` uses `c_concat` of mask+image) | conditioning built as: `conditioning_image = lerp(source, source·(1−mask), inpainting_mask_weight)` then VAE-encoded; concatenated with the (interpolated-to-latent) mask on the channel axis (`processing.py:352-370`, exact). Setting **"Inpainting conditioning mask strength"** slider 0–1 step .01 default 1.0 → infotext `Conditional mask weight` (only emitted when in use). Weight 1 = region fully masked-out in conditioning; lower leaks original in |

`latent_mask` (API param): pre-downsampled mask given directly (skips pixel→latent interpolation path) — used by canvas extensions. [wiki §4.2]

**Outpainting:** built-in script **Poor man's outpainting** (`scripts/poor_mans_outpainting.py`, img2img Scripts dropdown): pads canvas by L/R/T/B px, generates whole padded image with masked-content=fill semantics + gradient mask ramp. **Outpainting mk2** script: iterative masked outward expansion, padding sliders. `canvas-zoom-and-pan` built-in extension adds Zoom (Ctrl+wheel)/Reset Zoom on every img2img/inpaint canvas with configurable hotkeys (`canvas_hotkey_zoom`). Serious outpainting = extensions (openOutpaint §5.9). [wiki §4.3; repo §B.6]

**Image preprocessing (old wiki page — removed from core):** no `modules/preprocessing/` exists in this generation; preprocessors (openpose, canny, depth, normal, MLSD, HED, scribble, segmentation…) ship with **ControlNet**, which owns the UI panel. Historical note only — don't budget TUI fields for core preprocessing; budget them for the ControlNet-style panel. [wiki §4.4]

**img2img resize modes (`ui.py` radio verbatim):** `Just resize` = stretch to WxH; `Crop and resize` = resize preserving aspect to cover, center-crop; `Resize and fill` = fit inside, **fill empty margin by mirroring rows/columns of the source** then resize exact; `Just resize (latent upscale)` = encode then upscale the *latent* with `latent_scale_mode` (bilinear w/o antialias by default) — requires tiling-compatible thinking for >2×. Plus per-dimension "Resize by" tab (0.05–4) with live preview, and 🔍 detect-from-image. [wiki §4.5]

### 2.8 Upscalers [repo §A.5; wiki §5.3]

`shared.sd_upscalers` names (code, `initialize_util.load_upscalers()` via modelloader): **None, Lanczos, Nearest** (pure PIL); **ESRGAN** family (.pth in `models/ESRGAN`; `ESRGAN_4x`, `RealESRGAN_x4plus`, `RealESRGAN_x4plus_anime_6B`, `4x-UltraSharp`, `NMKD_Siax`, `4x_AnimeSharp` — realesrgan_model.py names; R-ESRGAN General 4xV3 / WDN 4xV3 / AnimeVideo / 4x+ / 4x+ Anime6B / 2x+ auto-downloaded; "All 2x models most likely unsupported" Features warning); ext-provided built-ins: **SwinIR** (`Swin2SR_*`/`SwinIR_*`, 4 variants), **ScuNET** (PS/CL/IP), **LDSR** ("Demon" — needs `--no-half-vae` era caveat, slow, inner-model), **DAT**. Hires dropdown additionally offers six **latent resizers** (`shared.latent_upscale_modes`): `Latent, Latent (antialiased), Latent (bicubic), Latent (bicubic antialiased), Latent (nearest), Latent (nearest-exact)` — they resize the *latent* between passes (skip decode/encode; cheap, softer). `hypertile` ext patches attention to run tiled for huge generations (max tile sizes settings + XYZ axes). API: `/sdapi/v1/upscalers` returns `{name, model_name, model_path, model_url, scale}`; `/sdapi/v1/latent-upscale-modes` returns `{name}`.

### 2.9 Samplers & schedulers — FULL roster [repo §A.6; wiki §2.6]

Format: `label | func | aliases | default options (scheduler, second_order, brownian_noise, uses_ensd, discard_next_to_last_sigma, solver_type, no_sdxl)`:

k-diffusion (`sd_samplers_kdiffusion.py`):
1. DPM++ 2M (`k_dpmpp_2m`) sched=karras
2. DPM++ SDE (`k_dpmpp_sde`) karras, second_order, brownian_noise
3. DPM++ 2M SDE (`k_dpmpp_2m_sde`) exponential, brownian_noise
4. DPM++ 2M SDE Heun (`k_dpmpp_2m_sde_heun`) exponential, brownian, solver_type=heun
5. DPM++ 2S a (`k_dpmpp_2s_a`) karras, uses_ensd, second_order
6. DPM++ 3M SDE (`k_dpmpp_3m_sde`) exponential, discard_next_to_last, brownian
7. Euler a (`k_euler_a`,`k_euler_ancestral`) uses_ensd
8. Euler (`k_euler`)
9. LMS (`k_lms`)
10. Heun (`k_heun`) second_order
11. DPM2 (`k_dpm_2`) karras, discard_next_to_last, second_order
12. DPM2 a (`k_dpm_2_a`) karras, discard, uses_ensd, second_order
13. DPM fast (`k_dpm_fast`) uses_ensd
14. DPM adaptive (`k_dpm_ad`) uses_ensd
15. Restart (`restart`) karras, second_order

Timesteps/CompVis (`sd_samplers_timesteps.py`):
16. DDIM (`ddim`)
17. DDIM CFG++ (`ddim_cfgpp`)
18. PLMS (`plms`)
19. UniPC (`unipc`)

LCM (`sd_samplers_lcm.py`):
20. LCM (`k_lcm`)

Schedulers (`sd_schedulers.py`): Automatic, Uniform, Karras(ρ7), Exponential, Polyexponential(ρ1), SGM Uniform (alias SGMUniform), KL Optimal, Align Your Steps (hardcoded SD1.5/SDXL sigma ladders), Simple, Normal, DDIM, Beta.

Sampler/scheduler pairing: "Automatic" picks each sampler's `options['scheduler']` default; legacy `sampler_index` names (`k_euler`, …) still resolve via aliases; `fix_p_invalid_sampler_and_scheduler` autocorrects combos. UI pairs *Sampling method* × *Schedule type* as two dropdowns — the TUI should keep that decoupling (introduced 1.8/1.9; older single dropdown had "Karras" suffix variants). Tooltips mark ancestral (Euler a, DPM2 a, DPM++ 2S a, DPM fast/adaptive — ENSD-carrying) vs deterministic samplers.

### 2.10 Stochasticity / sampler-parameter settings (the "Karras/eta/epsilon" family) [repo §A.5; wiki §2.5]

| opt key | label | widget range | default | infotext | notes |
|---|---|---|---|---|---|
| hide_samplers | Hide samplers in UI | CheckboxGroup | [] | | |
| eta_ddim | Eta for DDIM | Slider 0–1/0.01 | 0.0 | `Eta DDIM` | "noise multiplier; higher = more unpredictable results" |
| eta_ancestral | Eta for k-diffusion samplers | Slider 0–1/0.01 | 1.0 | `Eta` | "noise multiplier; currently only applies to ancestral samplers (i.e. Euler a) and SDE samplers" |
| ddim_discretize | img2img DDIM discretize | Radio uniform/quad | "uniform" | — | (excluded from API) |
| s_churn | sigma churn | Slider 0–100/0.01 | 0.0 | `Sigma churn` | stochasticity, "only applies to Euler, Heun, and DPM2" (Karras churn) |
| s_tmin | sigma tmin | Slider 0–10/0.01 | 0.0 | — | churn window start |
| s_tmax | sigma tmax | Slider 0–999/0.01 | 0.0 | — | 0=inf; churn window end |
| s_noise | sigma noise | Slider 0–1.1/0.001 | 1.0 | `Sigma noise` | "additional noise to add to the churn; values below 1 reduce detail loss" family semantics |
| sigma_min | sigma min | Number | 0.0 | `Schedule min sigma` | 0=default ~0.03 |
| sigma_max | sigma max | Number | 0.0 | `Schedule max sigma` | 0=default ~14.6 |
| rho | rho | Number | 0.0 | — | 0=default (7 karras / 1 polyexp) |
| eta_noise_seed_delta | ENSD | Number(precision 0) | **0** ⚖️ C-3 | `ENSD` | deterministic ancestral noise offset; §2.3 |
| always_discard_next_to_last_sigma | Always discard next-to-last sigma | Checkbox | False | `Discard penultimate sigma` | |
| sgm_noise_multiplier | SGM noise multiplier | Checkbox | False | — | SDXL initial-noise parity |
| uni_pc_variant | UniPC variant | Radio bh1/bh2/vary_coeff | "bh1" | `UniPC variant` | |
| uni_pc_skip_type | UniPC skip type | Radio time_uniform/time_quadratic/logSNR | "time_uniform" | `UniPC skip type` | |
| uni_pc_order | UniPC order | Slider **1–50**/1 ⚖️ C-4 | 3 | `UniPC order` | "must be < sampling steps" (`shared_options.py:404` verified) |
| uni_pc_lower_order_final | UniPC lower order final | Checkbox | True | `UniPC lower order final` | |
| sd_noise_schedule | Noise schedule | Radio Default/Zero Terminal SNR | "Default" | — | |
| skip_early_cond | Ignore negative prompt during early sampling | Slider 0–1/0.01 | 0.0 | `Skip Early CFG` | |
| beta_dist_alpha / beta_dist_beta | Beta scheduler alpha/beta | Slider 0.01–1/0.01 | 0.6/0.6 | — | |
| s_min_uncond | Negative Guidance minimum sigma | Slider 0–15/0.01 | 0.0 | `NGMS` | "skip negative prompt for some steps when the image is almost ready; 0=disable, higher=faster" |
| s_min_uncond_all | NGMS all steps | Checkbox | False | `NGMS all steps` | every step instead of alternating |
| samplers_in_dropdown | (UI) Sampling use radio buttons instead of dropdown | Checkbox | | — | |
| hires_fix_show_sampler / hires_fix_show_prompts | reveal rows 3/4 | Checkbox | False | — | |

Applies via `extra_args` to Euler/Heun/DPM2 (`sd_samplers_cfg_denoiser.py` k-diffusion sampler options list). No separate "Karras epsilon" opt in this version — epsilon is expressed via `sigma_min`/`sigma_max`; Forge-era "Karras epsilon" maps there. [repo §A.5 note; wiki §2.5]

### 2.11 Settings (opts) that ride every generation — SD section roster [repo §A.5]

`modules/shared_options.py` (`options_templates`, key → `OptionInfo(default, label, component, component_args, infotext=…)`):

**Stable Diffusion section:** `sd_model_checkpoint` (Dropdown, refresh), `sd_checkpoint_dropdown_use_short` (False), `sd_unet` ("Automatic"), `sd_vae` ("Automatic"/"None"+list), `sd_vae_checkpoint_cache` 0–10, `sd_vae_overrides_per_model_preferences` True, `sd_vae_encode_method` Radio Full/TAESD ("Full"), `sd_vae_decode_method` Radio, `CLIP_stop_at_last_layers` Slider 1–12/1 = **clip skip** (default 1), `restore_faces`→`face_restoration` (False, infotext "Face restoration"), `face_restoration_model` Radio (CodeFormer default; choices = registered `face_restorers`: built-ins GFPGAN, CodeFormer), `codeformer_weight` Slider 0–1/0.01 default 0, `tiling` (False, infotext "Tiling"), `img2img_extra_noise` ("Extra noise multiplier for img2img and hires fix", Slider 0–1/0.01, 0=disabled, infotext "Extra noise"), `initial_noise_multiplier`/"Noise multiplier for img2img" Slider 0–1.5/0.001 default 1.0 (infotext "Noise multiplier"), `samples_save`, `samples_format` (png), `jpeg_quality` (80), `pnginfo_add_modules`, `filename_pattern` (`{date}-{time}` default templates), … (full list in file; ~500 opts total), GRID opts (`Return grid`, `save_true_cfg` etc.) — plus `save_images_before_face_restoration`, `save_images_before_highres_fix`, `save_images_before_color_correction`, `save_mask`, `save_mask_composite`, `export_for_4chan`, `img_downscale_threshold`, `target_side_length`, `use_original_name_batch`, `use_upscaler_name_as_suffix`, `save_selected_only`, `save_init_img`, `temp_dir`, `directories`/`outdir_*` (outdir_txt2img_samples, outdir_img2img_samples, outdir_extras_samples, outdir_grids, …), `img2img_editor_height` (default 720), `img2img_sketch_default_brush_color`, `img2img_inpaint_mask_brush_color`, `img2img_inpaint_sketch_default_brush_color`, `return_mask`, `return_mask_composite`, `disabled_tags`, `show_warnings`…

**CLIP skip exact option text** [wiki §2.7]: `CLIP_stop_at_last_layers`: OptionInfo(1, Slider 1–12, infotext "Clip skip") tooltip verbatim: *"ignore last layers of CLIP network; 1 ignores none, 2 ignores one layer"*. SDXL was trained with penultimate CLIP output ⇒ skip 2 is the SDXL-correct value.

### 2.12 Face restoration [repo §A.5; wiki §5.4]

Settings: `face_restoration` checkbox ("Restore faces", infotext `Face restoration`, tooltip verbatim *"will use a third-party model on generation result to reconstruct faces"*), model choice **CodeFormer | GFPGAN** (`face_restoration_model`; registered in `shared.face_restorers` from `modules/codeformer_model.py` / `modules/gfpgan_model.py`; RestoreFormer not in core), `codeformer_weight` 0–1 ("0 = best quality, 1 = most similar to original"). Applied post-decode pre-save on pixels; per-image path via postprocessing. Built-in postprocessing scripts + the Settings→Postprocessing "After generation" list (CodeFormer/GFPGAN/RealESRGAN ×4 automatically after each generation — `extensions-builtin/postprocessing-for-training`).

### 2.13 Extras (postprocessing) tab [repo §A.3]

The "Extras" tab hosts both **Postprocessing** (script runner over a single image) and the legacy "Scale and crop" + "Face restoration" panels (`modules/ui_postprocessing.py`):

| Control | Label | Widget | Range | Default | Effect |
|---|---|---|---|---|---|
| Source image | `postprocess_image` | gr.Image (upload, pil) | — | — | input |
| Batch input dir / output dir | Textboxes (`postprocess_batch_input_dir`/`output_dir`) | Textbox | — | — (hidden if `--hide-ui-dir-config`) | batch over directory |
| Script | Dropdown `postprocess_script` | Dropdown | `scripts_postproc` titles (Image caption, Upscale, GFPGAN, CodeFormer, …) | first | picks postprocessor + its UI |
| Postprocessor args | dynamic | per-script (`setup_ui`) | per-script | per-script | see below |

Built-in postprocessing scripts (`scripts/postprocessing_*.py`, all `ScriptPostprocessor`):

**Upscale script** (`postprocessing_upscale.py`):

| Control | Widget | Range | Default |
|---|---|---|---|
| Upscale by | Slider | 1–8 / 0.05 | 4 |
| Resize width / Resize height | Sliders | 64–2048 / 8 w/ resize-mode radio | 2048 each |
| Resize mode | Radio ["Scale", "Crop and resize", "Resize and fill"] | index | "Scale" |
| 1st order upscaler | Dropdown | all upscaler names | opts.postprocessing_upscale_options[0] default "Lanczos"/"None" |
| 2nd order upscaler | Dropdown | same | "None" |
| 2nd order upscaler visibility | Slider | 0–1/0.01 | 0 |
| Upscale first | Checkbox | — | False (before face restoration) |

**GFPGAN script** / **CodeFormer script**: `visibility` Slider 0–1/0.01 default 1; CodeFormer additionally `CodeFormer weight` Slider 0–1/0.01 default 0 (0 = max model effect = highest quality in A1111 semantics).

**Image caption (interrogate)**: model Dropdown (clip/deepbooru…), outputs caption into prompt.

Legacy Extras API (`/extra-single-image`, `ExtrasBaseRequest` in api/models.py — fields): `resize_mode (0=by factor,1=to W×H)`, `show_extras_results`, `gfpgan_visibility 0–1`, `codeformer_visibility 0–1`, `codeformer_weight 0–1`, `upscaling_resize >0 (default 2)`, `upscaling_resize_w/h ≥1 (512)`, `upscaling_crop (True)`, `upscaler_1 ("None")`, `upscaler_2 ("None")`, `extras_upscaler_2_visibility 0–1 (0)`, `upscale_first (False)` — implemented in `modules/extras.py: run()`; `run_pnginfo` parses info text; there's also `image_from_url_text`. [repo §A.3]

**Extras tab verbatim controls (UI view)** [wiki §5.6]: Tabs **Single Image / Batch Process (multi-file) / Batch from Directory** (input dir, output dir empty = `<outdir_extras>/…`, optional per-image txt, csv log, show-result-images). Resize mode radio (by factor slider `upscaling_resize`, or to WxH); **Upscaler 1**; **Upscaler 2** + **Upscaler 2 visibility** 0–1 (linear blend of the two results); **Crop to width/height** (`upscaling_crop` center-crop); **GFPGAN visibility**; **CodeFormer visibility + weight**; **Upscale before face-fix** (`upscale_first`). Processing = same pipeline as API `/sdapi/v1/extra-single-image` (`postprocessing.run_postprocessing`).

**img2img Batch tab** [wiki §5.6]: directory of images (+ optional CSV whose second+ columns are *appended to the prompt* per row) + checkbox group **"Append png info to prompts"** with sub-checkboxes `Prompt, Negative prompt, Seed, CFG scale, Sampler, Steps, Model hash` = per-image parameters are *read from each file's PNG info* and merged under the UI values — the vid2vid/loopback-style workflow foundation.

### 2.14 PNG Info tab [repo §A.4; wiki §5.7]

`image` upload → `extras.run_pnginfo` shows parsed infotext; "Put info into images"/"Send to" buttons per tab (`parameters_copypaste`): txt2img / img2img / inpaint / extras; also (via `infotext_fields` PasteField api= mappings) fills styles, hires fields, refiner, script fields. Read-only tab.

### 2.15 X/Y/Z plot script [wiki §5.1, `scripts/xyz_grid.py` 817 lines]

- **X axis = rows, Y axis = columns, Z = separate grid per Z value** (Features:334 verbatim "X and Y are used as the rows and columns, while the Z grid is used as a batch dimension").
- Axis picker: three dropdowns ("X type/Y type/Z type") + comma-separated value fields. Axes are `AxisOption(name, type, apply_fn, …)` entries (`Nothing, Seed, Var. seed, Var. strength, Mask blur, Sigma churn, … Steps, CFG scale, Sampler, Scheduler, Face restoration, Clip skip, Denoising, Model, VAE, Prompt S/R, Prompt template, Seed resize…`). **Extensions add axes** by importing the script module and appending `AxisOption`s (hypertile built-in does exactly this: `[Hypertile] Unet Max Tile Size` with `confirm_range` validators — the *documented* extension surface without a formal API).
- **Value range syntax:** `1-5` → inclusive int range; `1-5 (+2)` / `10-5 (-3)` → arithmetic step; `1-10 [5]` → 5 evenly-spaced points. `Prompt S/R` axis = `search,replace1,replace2,…` per-cell prompt search/replace; CSV-style quoting caveats.
- Grid image + per-cell images saved; grid filename `[gen-x-y]` placement.
- XYZ axis catalog is itself a tunable inventory: seed, steps, cfg, sampler name, sigma correction, clip skip, denoising, var seed/strength, var format, model, VAE, mask/blur, inpaint fill, hires scale/upscaler/denoising/resize/steps/checkpoint/sampler, refiner ckpt/switch, CFG end at/restart at/window, noise multiplier, extra noise, image CFG, token merging, per-axis prompt replace `[xxxx]`. [repo §B.6]

### 2.16 Extra networks: LoRA / TI / Hypernetworks [wiki §5.2]

| Type | Syntax | Semantics |
|---|---|---|
| **Textual Inversion** | plain **keyword** (file's trained token; `embeddings/` dir) | extra CLIP embedding rows swapped in for the trigger token(s); multiple trigger aliases via `string_to_param` keys in the `.pt` (embedding DB wired in `sd_hijack.py`) |
| **LoRA** | `<lora:name:multiplier>` — built-in `extensions-builtin/Lora` (kohya-merged implementation): dir `models/Lora`, recursive subdirs, strength default 1 | applies low-rank weight patches to UNet (optionally CLIP per metadata) for the generation then restores; builtin also supports kohya **network types LoKr, GLoRA, Hada, IA3, Norm, OFT** alongside LoRA (file-class detection). Alt/`Negative prompt` click → inserts into **negative prompt** too (`extraNetworks.js` textToAddNegative). Batch of prompts: networks from *all* prompts applied to all images (XYZ-Prompt-S/R workaround documented). Infotext: networks are *not* a core key — the Lora ext writes its own `Networks:` line. |
| **Hypernetworks** | trained via Train tab / `models/hypernetworks`; select per generation (or `<hypernet:name:strength>` via ext scripts) | small net modulating UNet **attention Q/K/V projections** at inference — global style nudge; practically superseded by LoRA. |
| More formats | `sd-webui-additional-networks` (kohya) adds its own page/dirs on top of extra-networks | |

**Extra-networks card UI** (click-to-insert asset browser, `ui_extra_networks.py` + JS): toggle button per tab; pages = Lora/Textual Inversion/Hypernetworks/**Stable Diffusion checkpoints** (+ext pages via `register_page(ExtraNetworkPage(...))`); card = preview image (item `preview` metadata, ssmd sidecars, or auto-named png) + name; **click = insert trigger text** into prompt; search box filters (filename + dir + metadata search terms; hidden dirs via `search_only`); per-item `.json` metadata sidecar (description, sort key, preview, searchterms, per-type fields); **'i' opens the metadata editor panel** (`ui_edit_user_metadata.py`); copy-to-clipboard per card; settings: card width/height, description visibility, order field (Path/Name/Date…), asc/desc, dirs-first, separator before inserted text, hidden-per-page checkbox.

### 2.17 Styles [wiki §5.5]

`styles.csv` (name, prompt, negative prompt; `--styles-file` multiple/wildcards); UI = multi-select dropdown next to prompt (Toprow); **selected styles append their prompt text** — `{prompt}` placeholder inside a style inserts the user's original text where written; save-current-prompt-as-style button (`ui_prompt_styles.py: save_style/refresh_styles`); styles participate in token counters (§3.2) and infotext (`Style` extra param when selected).

### 2.18 Gallery + keyboard/mouse interaction spec [wiki §6.3, §6.4]

**Gallery** (`ui_common.py:181-224` + JS, verbatim tooltips): `gr.Gallery columns=4 preview height=gallery_height`; entry click → auto-refreshes info panel (`generationParams.js`) + arrow-key navigation (37/39). Info shown as HTML block `html_info_<tab>` (parsed, per-key `<span>` with tooltips) + hidden raw `generation_info_<tab>` textbox. Buttons row (exact): `📂 "Open images output directory."` · `💾 "Save the image to a dedicated directory (outdir_save)"` · `🗃️ "Save zip archive with images to a dedicated directory (outdir_save)"` · `🖼️ "Send image and generation parameters to img2img tab"` · `🎨️ "…to img2img inpaint tab"` · `📐 "…to extras tab"` · `✨ "Create an upscaled version of the current image using hires fix settings."` (txt2img only). **No delete button and no copy-params button in this core** (images-browsing ext supplies delete/browse). Paste-buttons row under info (`parameters_copypaste`).

**Keyboard/mouse (JS verified against the repo)** [wiki §6.4]:

- `edit-attention.js`: **Ctrl/Cmd + ↑/↓** in a prompt box → weight of the parenthesized block (or selection, wrapped in `()`) by ×1.05 / ÷1.05, respecting existing `(x:w)` values.
- `edit-order.js`: **Alt + ←/→** → move current word/comma-clause left/right.
- `contextMenus.js`: right-click **Generate/Interrupt buttons** → `Generate forever` + `Cancel generate forever`; public API `appendContextMenuOption/removeContextMenuOption/addContextMenuEventListener` = the extension hook for right-click everything.
- `imageviewer.js` lightbox keys (verbatim switch): `s` save, `ArrowLeft/ArrowRight` prev/next, `Escape` close; modal opens on gallery image click.
- `dragdrop.js`: drag-drop files + **paste image from clipboard** into any image component.
- `canvas-zoom-and-pan`: hotkey-configurable Zoom/Reset Zoom/Pan modifiers on canvases.
- Prompt-box token counters update on input; red mismatch tooltip from bracket checker.
- ⚖️ **C-2 Conflict:** [repo §E.4] claims "UI keyboard: `Esc` = interrupt, `Alt+Enter` = skip, `Ctrl+Enter` = generate (toprow.js)"; [wiki §6.4] states "(No Ctrl+Enter-to-generate binding exists in this codebase's JS — correct the widespread assumption.)" **Adjudicated (2026-10-07, read-only check @82a973c): the wiki is right** — there is no `javascript/toprow.js` and no ctrlKey submit handler in the webui's own JS. The Ctrl+Enter/Alt+Enter/Esc wording exists only in the prompt textarea's *placeholder string* (`ui_toprow.py`, quoted at §2.1 above); any real behavior would live in the forked Gradio frontend and is outside this repo. Our TUI should *define* these chords deliberately rather than inherit the A1111 placeholder myth.

**Loading/saving/notification niceties** [wiki §5.7]: drag-drop anywhere onto img2img/inpaint components or paste-from-clipboard; progressbar hiding flag (`--no-progressbar-hiding`); **notification.mp3** root file plays on completion; `<title>` generation-progress setting. Saving: `outdir_*` settings, `save_txt` sidecar `.txt` with the exact info string, grids (`Grid format` pattern), `Samples filename pattern` tags (`[seed] [steps] [cfg] [width] [height] [model_name] [model_hash] [date] [datetime] [job_timestamp] [prompt] [prompt_no_styles] [prompt_spaces] [prompt_words] [prompt_hash] [styles] [sampler] [clip_skip] [denoising] [hasprompt] [batch_number] [generation_number]`…), subdirectory auto-create (`use_save_to_dirs_for_ui`, `directories_filename_pattern`), `Add number to filename when saving`, image mirror-write, upscaler-suffix option, webp/jpg quality + lossless webp. Also: Checkpoint Merger tab, Train tab (embeddings + legacy hypernetworks), about/system-info page, `--allow-code` Python-console script escape hatch, themes/CSS (`user.css`, `--theme`, `__theme=` URL param), mobile built-in ext, localizations.

---

## 3. Prompting syntax, PNG info metadata, infotext round-trip

### 3.1 Attention weights — `re_attention` stack machine [wiki §3.1]

```
(word)        ×1.1 per paren pair (nestable: ((word)) = ×1.21)
[word]        ÷1.1 per bracket pair
(word:1.5)    explicit float weight — ONLY valid inside round parens
\( \) \[ \]   backslash-escaped literals; (word with \( in it\) works)
```

Unbalanced closing parens apply to end-of-prompt; weights multiply over token ranges, then ranges are rounded to token boundaries; equal-weight adjacent tokens merge. Old-parser compat setting `use_old_attention_syntax`; NAI-import conversion in Features: `{word}` ≙ `(word:1.05)`, NAI `[word]` ≙ `(word:0.952)` (inverse meaning!). 2022-09-29 parser change = seed-breaking (§3.7) — the new parser *preserves* structural chars in infotext. [wiki §3.1]

### 3.2 BREAK, 75-token chunks, validation [wiki §3.2]

- CLIP segments: 75 tokens/chunk incl. SOT/EOT; prompt of N tokens ⇒ ceil-ish concatenation of chunk conditionings. `BREAK` (uppercase) pads/flushes the current chunk and starts a new one ⇒ words after BREAK influence only later chunks.
- `comma_padding_backtrack` setting (0–75, default 20): if the 75th token lands inside a comma-group within N tokens of the boundary, backtrack the split to the comma — reduces word-spillover artifacts.
- **Prompt validation:** Features documents the "the following part of your prompt may be ignored" warning; code: live **token counters** under both prompt boxes (`update_token_counter`, positive & negative; count tokens *including enabled styles* per `include_styles_into_token_counters`; disable via `disable_token_counters`; pre-hook callback `on_before_token_counter`).
- `prompt-bracket-checker` built-in extension: counts `() [] {}`; mismatch ⇒ token counter turns red with tooltip naming the unbalanced kind.

### 3.3 Prompt editing over time — `schedule_parser` lark grammar (code-verified) [wiki §3.3]

```
[from:to:when]     replace `from` with `to` at time `when`
[to:when]          `to` appears at when (before: nothing)
[from::when]       `from` disappears at when
[a|b|c]            ALTERNATING words (no colon): rotate one item per step
nested brackets    legal; empty segments legal
when: NUMBER       fraction 0<v<1 → v·steps; integer ≥1 → absolute step
```

`get_learned_conditioning_prompt_schedules` compiles to `[[step, text], …]` boundaries; conditioning re-encoded only at boundaries; docstring example `[a|(b:1.1)]` = alternating word where the second option carries a weight.

**Hires-aware timeline (1.6.0, default on; `use_old_scheduling` opts out):** integers count from the start of the **combined base+hires** step sequence (`int_offset = base_steps`), decimals 0–1 address the base pass fraction, 1.0–2.0 the hires pass (`(v−1)·hires_steps` + offset) — Features walks through a worked example. Alternation lists expand across the same combined timeline.

Caveats documented: schedules must not cross `AND` segment boundaries (composable splits re-parse per segment); scheduling of `<lora:…>` strength over time is unsupported in core (extra-network tags are extracted before scheduling — Features explicitly says so).

### 3.4 Prompt matrices & composable prompts [wiki §3.4]

- **Prompt matrix script** (`scripts/prompt_matrix.py`, dropdown script): `<a|b|c>` groups cross-producted; N groups ⇒ one image per combination; **seed shared across all cells**. Distinct from `[a|b]` per-step alternation.
- **`AND` composable diffusion** (Features §Composable): prompt split on `\bAND\b` into independent conditionings, each optionally weighted (`a cat:1.2 AND a dog:0.8` — `re_weight` per segment); guidance composed across the segments (code cites the energy-based-composition model, Liu et al.). Community scripts (prompt-morph, prompt-interpolation) interpolate between AND-segments over steps to make videos.
- `<…>` matrix syntax and `[from:to:when]`/`[a|b]` interact only via separate passes — matrices are resolved by the script before parsing, schedules during encoding.

### 3.5 The PNG metadata format — spec for byte-compatible interop [wiki §6.2]

**Container:** PNG tEXt chunk key **`parameters`** (default `pnginfo_section_name='parameters'`, `modules/images.py:565,624`); existing chunks of the init image are preserved (`existing_pnginfo` dict copied into `PngInfo`). JPG/WebP/AVIF: EXIF `UserComment` (piexif helper unicode dump); GIF: comment chunk. Toggle: `enable_pnginfo`. Sidecar: `.txt` with `info + "\n"` when `save_txt`.

**Payload string** (`create_infotext`, exact order):

```
<final prompt>
Negative prompt: <negative>
Steps: 20, Sampler: Euler a, Schedule type: Automatic, CFG scale: 7, Image CFG scale: 1.5, Seed: 42, Face restoration: CodeFormer, Size: 512x512, Model hash: 6ce0…, Model: v1-5…, FP8 weight: …, Cache FP16 weight for LoRA: …, VAE hash: …, VAE: …, Variation seed: 7, Variation seed strength: 0.2, Seed resize from: -1x-1, Conditional mask weight: 1.0, Clip skip: 2, ENSD: 31337, Token merging ratio: 0, Token merging ratio hr: 0, Init image hash: abc…, RNG: GPU, Tiling: True, <extra_generation_params…>, Version: v1.10.1, User: hush
```

- Line 3 pairs joined `', '`; conditional key emission: `Variation seed`/`Variation seed strength` only if strength≠0; `Seed resize from` only if set; `Conditional mask weight` only for inpaint models; `Clip skip` only if >1; `ENSD` only for `uses_ensd` samplers with a delta; `Init image hash` only img2img; `RNG` only when non-GPU; `Tiling: True` only when on; then **`extra_generation_params`** (Hires upscale / Hires upscaler / Hires steps / Networks / Style / NGMS / Sigma churn / Eta / UniPC variant / Discard penultimate sigma… — anything contributed with `infotext=` by options or scripts), then `Version`, `User`.
- **Quoting:** any value containing `,` `\n` `:` is `json.dumps`-quoted (round-trip safe).
- **Prompt comments:** `#…` to end-of-line stripped from the saved/displayed prompt (1.8.0; `enable_prompt_comments` opt; `ScriptStripComments` alwayson).
- **Parse-back:** `re_param_code` per pair; `infotext_versions.py` maps legacy key spellings; NovelAI Comment-JSON conversion (`read_info_from_image` compat branch).

### 3.6 Infotext round-trip contract [repo §E.6; wiki §6.1]

`create_infotext` emits ordered `Label: value` lines + every `p.extra_generation_params` entry (extensions inject theirs here — that's how ControlNet params persist in PNGs) + opts whose `OptionInfo(infotext=…)` differ from default (via `infotext_to_setting_name_mapping`). The paste path (`png info → Parameters panel → apply`) maps labels back through `infotext_fields` (UI component ↔ label table, extensions append theirs, format `[(component, "Key")]`). **sugar-crush directive:** implement the same two-way label↔field table so generated PNGs/JSON re-load into the popup form verbatim, and the CLI can take an `--infotext` string as full param spec. On the API side this is `apply_infotext` (fills unset fields via `paste_fields`) + `mentioned_script_args` auto-routing (§4.5); the callback `on_infotext_pasted(infotext, vars)` lets extensions mutate the parsed dict before fields apply. [repo §B.4, §C.5, §E.6; wiki §6.2, §7.1]

### 3.7 Reproducibility doctrine [wiki §10, Seed-breaking-changes.md]

Official position: seed reproducibility is guaranteed *only* across an identical stack: webui version + checkpoint + VAE + attention impl + `randn_source` + half/float + **sampler+scheduler pair** + parser era + schedule era (new prompt parser 2022-09-29; composable-schedule 2023-08-04; hires-aware scheduling 1.6.0; sampler default-schedule changes incl. DPM++ SDE karras re-default 2024-02-27 — the page lists every change with a table row). Infotext records `Version`, `RNG` (when non-GPU), model/VAE hashes, `ENSD`, `Discard penultimate sigma`, `UniPC variant` precisely so a run can be re-created or diagnosed. **Sugar-crush: embed `Version:` + backend/precision stamps from day one; publish our own breaking-changes ledger page.**

---

## 4. A1111 REST API contract

### 4.1 How request models are built (exact mechanism) [repo §C.1]

`PydanticModelGenerator` (modules/api/models.py:32-96) introspects the **MRO `__init__` signatures** of `processing.StableDiffusionProcessingTxt2Img` / `…Img2Img`, drops every name in `API_NOT_ALLOWED` = `[self, kwargs, sd_model, outpath_samples, outpath_grids, sampler_index, extra_generation_params, overlay_images, do_not_reload_embeddings, seed_enable_extras, prompt_for_display, sampler_noise_scheduler_override, ddim_discretize]` (note: `do_not_save_samples`/`do_not_save_grid` are commented out ⇒ they ARE settable), types every field `Optional[T]` with default = the `__init__` default (property defaults become None), then appends endpoint-specific extra fields. `allow_population_by_field_name = True` (pydantic v1). **So the canonical parameter list = the processing dataclass fields in the tables below.**

### 4.2 `POST /sdapi/v1/txt2img` — request fields (name : type = default) [repo §C.2]

| Field | Type = default | Notes |
|---|---|---|
| prompt | str = "" | |
| negative_prompt | str = "" | |
| styles | list[str] = None→[] | names from GET /prompt-styles; appended prompt/negative |
| seed | int = -1 | -1 = random each call |
| subseed | int = -1 | |
| subseed_strength | float = 0 | blend toward subseed latents |
| seed_resize_from_h | int = -1 | -1=current dim, 0=disabled |
| seed_resize_from_w | int = -1 | same |
| sampler_name | str = None | accepts legacy `sampler_index` too (extra field, default "Euler") — resolved via `get_sampler_and_scheduler` w/ aliases |
| scheduler | str = None | "Automatic" when omitted |
| batch_size | int = 1 | |
| n_iter | int = 1 | batch count |
| steps | int = 50 | UI default 20 |
| cfg_scale | float = 7.0 | |
| width / height | int = 512 | |
| enable_hr | bool = False | hires fix |
| denoising_strength | float = 0.75 | **hr denoising** on this endpoint |
| firstphase_width / firstphase_height | int = 0 | 0 = width/height |
| hr_scale | float = 2.0 | |
| hr_upscaler | str = None | name from GET /upscalers or latent mode list |
| hr_second_pass_steps | int = 0 | 0 = same as steps |
| hr_resize_x / hr_resize_y | int = 0 | overrides hr_scale |
| hr_checkpoint_name | str = None | swap model for 2nd pass |
| hr_sampler_name | str = None | |
| hr_scheduler | str = None | |
| hr_prompt / hr_negative_prompt | str = '' | 2nd-pass prompts |
| restore_faces | bool = None→opts.face_restoration | |
| tiling | bool = None→opts.tiling | |
| eta | float = None | ancestral eta / uni_pc |
| s_churn / s_tmax / s_tmin / s_noise | float = None | Karras sigma params (−1 sentinel handling in code) |
| s_min_uncond | float = None | dynamic thresholding |
| token_merging_ratio | float = 0 | |
| token_merging_ratio_hr | float = 0 | |
| refiner_checkpoint / refiner_switch_at | str/float = None | SDXL refiner |
| override_settings | dict = {} | any opts key; applied around the call |
| override_settings_restore_afterwards | bool = True | |
| do_not_save_samples | bool = False | note: handler overwrites from `save_images` |
| do_not_save_grid | bool = False | ditto from `save_images` |
| disable_extra_networks | bool = False | |
| comments | str = None | |
| script_name | str = None | selectable script title |
| script_args | list = [] | positional flat args (§4.5) |
| send_images | bool = True | return base64 in response |
| save_images | bool = False | write to disk (drives do_not_save_*) |
| alwayson_scripts | dict = {} | `{"ControlNet": {"args": [...]}}` (§4.5) |
| force_task_id | str = None | client-chosen task id |
| infotext | str = None | paste a PNG parameter string; fills any unset fields |
| init_images… (none) | | txt2img does not expose them (`init=False` excluded) |

**Response** `TextToImageResponse`: `{images: [base64 PNG…], parameters: {echo of request vars}, info: "<JSON of Processed: seeds, infotexts, workflow…>"}`. `info` parses to: `images`, `parameters` (=Processed vars), `infotexts[]`, `index_of_first_image`, `prompt`, `negative_prompt`, `seed`, `subseed`, … plus extension keys.

### 4.3 `POST /sdapi/v1/img2img` — same base; subclass/extras replace hr fields [repo §C.3]

| Field | Type = default | Notes |
|---|---|---|
| init_images | list[str base64] = None | **404 "Init image not found"** if null; multi-item = batch |
| resize_mode | int = 0 | 0 stretch, 1 crop, 2 fill/letterbox (see §2.7 latent modes) |
| denoising_strength | float = 0.75 | extra-field override beats dataclass |
| mask | str base64 = None | L mask over first init image; white=repaint |
| mask_blur | int = None→(mask_blur_x=mask_blur_y=4) | |
| inpainting_fill | int = 0 | 0 fill, 1 original, 2 latent noise, 3 latent nothing — ⚖️ **C-1**: the repo report's C.3 row printed "3 latent, 4 nothing"; real code (`ui.py:702`, `processing.py:1567` + branch legs) has exactly 4 values 0–3 |
| inpaint_full_res | bool = True | "Only masked" |
| inpaint_full_res_padding | int = 0 | px (UI slider 0–256 step 4, default 32) |
| inpainting_mask_invert | int = 0 | 0 masked, 1 not-masked |
| initial_noise_multiplier | float = None→opts | img2img only |
| image_cfg_scale | float = None | edit-model image conditioning weight |
| latent_mask | str = None | not in dynamic model (init=False) — UI/canvas-side |
| include_init_images | bool = False | **exclude=True**: response echoes `initial_images` in info when true |
| mask_blur_x / mask_blur_y | int = 4 | present via dataclass (post-1.9 anisotropic blur) |
| mask_round | bool = True | |

### 4.4 All other endpoints (exact shapes) [repo §C.4; wiki §6.5]

| Endpoint | Request | Response |
|---|---|---|
| `GET /sdapi/v1/progress` (query: `skip_current_image=false`) | — | `{progress: 0..1, eta_relative: secs, state: State.dict(), current_image: b64\|null, textinfo, current_task: {id,type,preview,progress,eta,finished}}` |
| `POST /sdapi/v1/extra-single-image` | `ExtrasSingleImageRequest` = `ExtrasBaseRequest` + `image: str=""` | `{image: b64, html_info}` |
| `POST /sdapi/v1/extra-batch-images` | `ExtrasBatchImagesRequest{imageList: [{data: b64, name}]}` | `{images: [{image, html_info}], html_info}` |
| `POST /sdapi/v1/png-info` | `{image: b64}` | `{info: str, items: {k:v}, parameters: dict}` (split of info lines) |
| `POST /sdapi/v1/interrogate` | `{image: b64, model: "clip"\|"deepbooru"\|"blip"\|"none"}` | `{caption}` |
| `POST /sdapi/v1/interrupt` | — | `{}` → `shared.state.interrupt()` |
| `POST /sdapi/v1/skip` | — | `{}` → `state.skip()` |
| `GET /sdapi/v1/options` | — | whole OptionsModel (dynamically built from `opts.data_labels`, Optional-typed) |
| `POST /sdapi/v1/options` | partial options dict | `{}`; 400 `Not allowed to set the following values` for `--api-restrict-opts` / `restrict_api` keys |
| `GET /sdapi/v1/cmd-flags` | — | `{sd_model_checkpoint, no-half, …}` from argparse Namespace |
| `GET /sdapi/v1/samplers` | — | `[{name, aliases, options}]` |
| `GET /sdapi/v1/schedulers` | — | `[{name, label, aliases, default_rho, need_inner_model}]` |
| `GET /sdapi/v1/upscalers` | — | `[{name, model_name, model_path, model_url, scale}]` |
| `GET /sdapi/v1/latent-upscale-modes` | — | `[{name}]` |
| `GET /sdapi/v1/sd-models` | — | `[{title, model_name, hash, sha256, filename, config}]` |
| `GET /sdapi/v1/sd-vae` | — | `[{model_name, filename}]` |
| `GET /sdapi/v1/hypernetworks` | — | `[{name, path}]` |
| `GET /sdapi/v1/face-restorers` | — | `[{name, cmd_dir}]` |
| `GET /sdapi/v1/realesrgan-models` | — | `[{name, path, scale}]` |
| `GET /sdapi/v1/prompt-styles` | — | `[{name, prompt, negative_prompt}]` |
| `GET /sdapi/v1/embeddings` | — | `{loaded: {name: {step, sd_checkpoint, sd_checkpoint_name, shape, vectors}}, skipped: {…}}` |
| `POST /sdapi/v1/refresh-embeddings` / `refresh-checkpoints` / `refresh-vae` | — | info strings |
| `POST /sdapi/v1/create/embedding` `/create/hypernetwork` | `CreateResponse` fields (embedding_name, step, learning_rate…) | `{info}` |
| `POST /sdapi/v1/train/embedding` `/train/hypernetwork` | train params | `{info}` |
| `GET /sdapi/v1/memory` | — | `{ram: {total,active,…}, cuda: {alloc, reserved, max…}}` |
| `POST /sdapi/v1/unload-checkpoint` / `reload-checkpoint` | — | — |
| `GET /sdapi/v1/scripts` | — | `{txt2img: [titles], img2img: [titles]}` (selectable dropdowns) |
| `GET /sdapi/v1/script-info` | — | `[{name, is_alwayson, is_img2img, args: [{label, value, minimum, maximum, step, choices}]}]` — **machine-readable mirror of every script's ui() widgets**; the endpoint a form-generator client scrapes to build popups dynamically |
| `GET /sdapi/v1/extensions` | — | `[{name, remote, branch, commit_hash, version, commit_date, enabled}]` |
| `POST /sdapi/v1/server-kill` / `server-restart` / `server-stop` | — | only with `--enable-server` |
| `GET /sdapi/v1/tabs` · `GET /sdapi/v1/themes` · `GET /sdapi/v1/ping` · `POST /sdapi/v1/flush-memory` · `POST /sdapi/v1/internal/token-count` | — | tab structure / gradio themes / liveness / cache flush / tokenizer count [wiki §6.5] |
| `/internal/*` (Gradio-side, same auth) | progress POST `{id_task, id_live_preview, live_preview}` | `{active, queued:[{id,type,preview}], completed:[…], progress, eta, live_preview: "data:image/png;base64,…", id_live_preview, textinfo}`; also pending-tasks, quicksettings-hint, ping, profile-startup, sysinfo, task-metadata, model-metadata |

**ExtrasBaseRequest shared fields:** `resize_mode 0|1=0` (0 = by factor, 1 = to WxH), `show_extras_results=true`, `gfpgan_visibility 0..1=0`, `codeformer_visibility 0..1=0`, `codeformer_weight 0..1=0`, `upscaling_resize >0 =2`, `upscaling_resize_w/h ≥1 =512`, `upscaling_crop=true`, `upscaler_1="None"`, `upscaler_2="None"`, `extras_upscaler_2_visibility 0..1=0`, `upscale_first=false`.

**Auth/CORS:** HTTP Basic when `--api-auth user:pass`; CORS via `--cors-allow-origins(-regex)`; TLS `--tls-keyfile/--tls-certfile/--disable-tls-verify`; routes mounted only with `--api`; Swagger at `/docs`; `--nowebui` = API-only mode. Launch note [wiki §6.5].

**Doctrine:** images returned as base64 list + `info` (the exact PNG string) — API consumers and PNG files carry identical metadata **by construction**; one param registry serves UI, CLI-flags, PNG-info, and REST — the architecture sugar-crush should copy. [wiki §6.5; repo §C.1]

### 4.5 Script args over the API (the splice algorithm — mirror this) [repo §C.5]

- Flat positional: `script_args` values for a **selectable** script land in `script.args_from..args_to`; UI order = dropdown order × each script's `ui()` order.
- Alwayson: `alwayson_scripts: {"<exact script title>": {"args": [v0, v1, …]}}` — each list is spliced into the script's own `args_from` slot by index; unknown title or a *selectable* script placed here ⇒ 422. This is exactly how ControlNet (1 ext, 3 unit blocks) and all modern extensions receive params; **sugar-crush should adopt both encodings** (positional + named per-script dict).
- `infotext` paste: `apply_infotext` maps every parsed `Label: value` line back onto request fields through `paste_fields` (UI label→API key table + extension `infotext_fields`); unset fields only. Then `mentioned_script_args` auto-routes alwayson values by infotext label — round-trips a PNG's parameters blob into a full request.

### 4.6 Concurrency / queueing [repo §C.6]

One global `queue_lock` per API instance serializes txt2img/img2img; tasks recorded in `modules/progress.py` OrderedDict `pending_tasks` (cap 20), `finished_tasks` (cap 16), `record_results` last 2. `task_id` format `task(type-XXXXXXX)`; `force_task_id` lets clients pre-assign. `GET /progress` reads the **shared UI state singleton**, not per-task — per-task progress is `/internal/progress`.

### 4.7 How results come back; live previews; interrupt [repo §E.1–E.4]

- `POST txt2img/img2img` is **synchronous and batch-final**: response arrives only after ALL `n_iter × batch_size` images finish; `images` = `encode_pil_to_base64` PNGs (PIL save with `pnginfo` carrying infotext). No streaming of individual images; mid-run observation is exclusively via progress endpoints + `shared.state`. `send_images=false` → run and optionally save to disk, return empty `images` (still full `info`). [repo §E.1]
- Progress math (public `GET /progress`), exact: [repo §E.2]

```
if job_count == 0: progress = 0
else:
  progress = 0.01 + (job_no / job_count) + (1/job_count) * (sampling_step / sampling_steps)
  progress = min(progress, 1.0)
  eta = time_since_start / progress          # total-estimate
  eta_relative = eta - time_since_start      # remaining
```

`state` dict carries `{skipped, interrupted, job, job_no, job_count, sampling_step, sampling_steps, …}` and `textinfo` (per-stage text like "Sampling 512x512"). `current_image` present only when preview generation ran (`state.do_set_current_image()`; null when `skip_current_image=true`). `force_task_id` + `/internal/progress`/`/internal/pending-tasks` let a client correlate a queued request with previews while blocked on the sync response; `/internal/progress` returns `live_preview` as `data:image/png;base64,…` keyed by an `id_live_preview` counter (client echoes back the last-seen id to avoid duplicates; public `/progress` `current_image` is raw base64 without data-URI prefix). [repo §E.1]

- **Live-preview generation (the mechanism to mirror for TUI thumbnails):** inside the sampling loop (`sd_samplers_kdiffusion.py`/`sd_samplers_timesteps` via `state.job` callbacks — `modules/shared_state.py::state.end_of_step`/`sampling_step_callback`): every `opts.show_progress_every_n_steps` steps (-1 = every step, 0 = off), if `opts.live_previews_enable` and interval elapsed, decode `state.current_latent` with the cheap path (`opts.live_previews_method`: `Full` VAE or `TAESD`/`Latent` approximations), scale, set `state.current_image`, bump `id_live_preview`. Lowvram gating via `opts.live_preview_allow_lowvram_full` etc. The UI (`progressbar.js`) polls `/internal/progress` ~0.5s while a request is in flight and paints the data-URI into the per-gallery placeholder; the progress widget shows active/queued/completed task rows. [repo §E.3]
- **Interrupt / skip semantics:** `POST /interrupt` → `state.interrupt()`: sets `interrupted=True`; the innermost sampling loop checks **after each step** and breaks; in this version the interrupted batch is **discarded** (the repo report flags fork-behavior variance — verify against the actual backend when implementing; A1111 api currently just returns whatever Processed holds, UI discards). Second click with `opts.interrupt_after_current` and `job_count>1` → `stop_generating()`: finishes remaining images of the current batch then stops ("Generation will stop after finishing this image"). `POST /skip` → `state.skip()`: abandons the current image only, advances to next `job_no`. `state.nextjob()`/`end()` reset flags between jobs; `stopping_generation` consumed+cleared after the job. ⚖️ **C-2:** the repo report's E.4 also asserts UI chords `Esc`=interrupt, `Alt+Enter`=skip, `Ctrl+Enter`=generate "(toprow.js)" — verified false for the webui's own JS at 82a973c (no such file/handler; the chords exist only as the prompt-box placeholder string, §2.1); the JS-verified real keymap is §2.18. Define our TUI chords deliberately. [repo §E.4]

### 4.8 Canonical generation pipeline order (`processing.process_images_inner`) — any backend impl must reproduce this [repo §E.5]

1. `apply_infotext`/`override_settings` wrap (API only) → `p.init()` (prompt expansion, img2img latent prep, resize/crop bookkeeping `paste_to`)
2. `scripts.control(p,"before_process")` → seeds fixed → conditioning (CLIP encode, multi-cond concat)
3. per image-batch (`n_iter × batches`): `proc_before_batch` → `process_before_every_sampling` → noise init (seeded `rng_philox`; img2img: `init_latent + sigmas*noise*initial_noise_multiplier`, `on_extra_noise` callbacks) → **sampler loop** (Karras/Uniform sigma ladder chosen by scheduler; CFG incl. dynamic thresholding + skip-early-cond opts; refiner switch at `refiner_switch_at`; interrupt/skip check each step; live-preview each N steps) → `post_sample` → VAE decode
4. `postprocess_batch` (tensor) → `postprocess_batch_list` → per image: **hires fix 2nd pass** (`before_hr`, upscale latent via `hr_upscaler`/latent modes, re-noise at hr `denoising_strength`, re-sample w/ hr sampler/checkpoint/prompt; saves `-before_highres.png` when `opts.samples_save`) → **face restoration** (CodeFormer w/ `codeformer_weight` / GFPGAN; `-before_face_fix.png`) → img2img **color correction** (`opts.img2img_color_correction`) → **overlay paste-back** (inpaint full-res crop-back via `paste_to`; `-mask` / `-mask-composite` sidecars per `opts.return_mask*`) → append `output_images`+`infotexts` → save (`images.save_image`, filename from `opts.samples_filename_pattern` templates §2.11, `create_infotext` in PNG `parameters` chunk)
5. grids (unless `do_not_save_grid`/`opts.grid_save=False`/single-image w/ `opts.grids_only_individual`) — infotext-less title text per `opts.grid_text`
6. `scripts.control(p,"postprocess")` → `Processed` (info = infotexts, `js()` for API `info` field) → `state.end()`

---

## 5. Extension/script pluggability model (pattern to mirror)

A1111 has **two independent plugin planes** (selectable + always-on `Script`s; `ScriptPostprocessing` chain) plus a **global callback registry** and a **declarative settings plane**. For sugar-crush's pluggable generation params, the `Script` + `ScriptRunner` plane is the one to mirror almost 1:1. [repo §B; wiki §7]

### 5.1 `Script` base class (modules/scripts.py) — full member table [repo §B.1]

| Member | Signature / type | Purpose |
|---|---|---|
| `filename` | str | source file it was loaded from |
| `alwayson` | bool | False = selectable via dropdown; True = always active, its `ui()` rendered inline |
| `api_info` | `ScriptInfo\|ScriptFileInfo` | metadata served by `GET /script-info` (auto-generated from `ui()` component props: label, value, minimum, maximum, step, choices) |
| `order` | int = 1000 | sort key for script list |
| `args_from` / `args_to` | int | slot range this script's values occupy in the flat `script_args` list (assigned by the runner at UI-build time) |
| `title()` | → str | display name; also the key in `alwayson_scripts` API dict |
| `show(is_img2img)` | → False \| True \| `AlwaysVisible` | `AlwaysVisible` (module-level sentinel) = render inline on the tab instead of in the dropdown; returning `is_img2img` = img2img-only selectable |
| `describe()` | → str | tooltip shown when script selected |
| `ui(is_img2img)` | → list[gr.Component] | the section's widgets; their values passed **positionally as `*args`** to every hook below |
| `setup(p, *args)` | alwayson-only | before processing; maps widget values onto the `p` processing object (what built-in `ScriptSeed`/`ScriptSampler` use to move core controls into the hook system) |
| `before_process(p)` | | very first hook, before anything (X/Y/Z rewrites prompt/batch here) |
| `process(p)` | | before sampling loop |
| `before_process_batch(p, *, batch_number, prompts, seeds, subseeds)` | | before each batch |
| `after_extra_networks_activate(p, *, seed, prompts, negative_prompts, extra_network_data)` | | after embeddables (LoRA/LyCORIS syntax) expand |
| `process_before_every_sampling(p, *args, **kwargs)` | | before **each** sampling call (incl. hires 2nd pass) — Loopback-style re-entry point |
| `post_sample(p, *args, **kwargs)` | `kwargs["images"]` | after sampling, before decode/postprocess |
| `postprocess_batch(p, x_samples_ddim, *, batch_number)` | | on the decoded-but-not-yet-PIL batch tensor |
| `postprocess_batch_list(p, pp, *, batch_number)` | `pp.images` mutable list | per-batch image list, before per-image processing |
| `postprocess_image(p, pp)` | `pp.image` mutable `PostprocessImageArgs` | per-image (Upscale scripts rewrite `pp.image` + append `pp.pnginfo`/`pp.extra_images`/`pp.nametags`) |
| `postprocess_maskoverlay(p, pp)` | `index, mask_for_overlay, overlay_image` | mutate the overlay mask before paste-back (inpaint scripts) |
| `on_mask_blend(p, pp)` | `mask, mask_for_overlay` | final blend control |
| `postprocess_image_after_composite(p, pp)` | | after the composite/paste-back |
| `postprocess(p, processed)` | | once per batch after everything (Processed object) |
| `before_hr(p, *args, **kwargs)` | | before hires-fix second pass |
| `elem_id(item_id)` | → `f"{tabname}_{scriptname_slug}_{item_id}"` | stable DOM ids for CSS/JS |
| `before_component(c)` / `after_component(c)` + `on_before_component(cb, elem_id=…)` / `on_after_component(cb, elem_id=…)` | `ComponentCallbackParams` | intercept/wire **any** component on the tab by `elem_id` (how scripts grab the generate button, gallery, etc.) |

Wiki-side additions [wiki §7.1]: `run(p, *args)` **owns the loop** — may call `process_images(p)` multiple times; returns `Processed(images, all_seeds, all_subseeds, comments, iteration, position_in_batch, info, …)`. `section` / `sections()` = category placement (`'seed'`, `'sampler'`, `'refiner'`, `'extra_options'`, `'comments'`…) so `ordered_ui_categories` re-lays them (user reorders via settings; `ui.py` assembles the tab by category loop). Scripts round-trip their widgets through paste via `self.infotext_fields = [(component, "Key"), …]` (ExtraOptionsSection does it generically). `AlwaysVisible` is how **Seed block, Sampler/Scheduler block, Refiner, Comments, Extra-options, Soft-inpainting, Hypertile, Lora** surface persistent form sections; the bundled roster (`scripts/`: img2imgalt, loopback, outpainting_mk_2, poor_mans_outpainting, postprocessing_codeformer/gfpgan/upscale, prompt_matrix, prompts_from_file, sd_upscale, xyz_grid, custom_code + `processing_scripts/`: Seed, Sampler, Refiner, Comments) means **the A1111 form itself is ~90% assembled out of these**.

### 5.2 `ScriptRunner` mechanics [repo §B.2]

- Loads from `scripts/ **and** every extension's folder via `load_scripts()` (`modules/script_loading.py`: imports module, registers every `Script`/`ScriptPostprocessing` subclass).
- Selectable scripts appear in the tab's **`Script` Dropdown** (`script.txt2img`/`script.img2img`); value 0 = "None", value *i* = script *i−1*. `ScriptRunner.run(p, *args)` dispatches only that one.
- All scripts' `ui()` components concatenate into one flat hidden `script_args` list; the runner slices `args_from..args_to` per script and passes them positionally — this is the mechanism the API exposes raw (§4.5).
- `AlwaysVisible` scripts get a `gr.Accordion(title)` on the tab; their components always feed `script_args`.
- `setup_ui(tabname, script_dropdown)` returns `custom_inputs` appended to the Generate button's input list so one click captures every tunable.
- `ScriptBuiltinUI` subclass: same hooks plus a `section` attr (`'sampler'`, `'seed'`, `'accordions'`, `'cfg'`) so **core controls themselves are injectable/reorderable** by extensions (`prepare_ui`/`setup_ui_for_section`). The four built-ins in `modules/processing_scripts/`:
  - **ScriptSampler** — Steps Slider (1–150, UI default 20), Sampler Dropdown, Schedule Dropdown; dropdown-vs-radio switchable via `opts.samplers_in_dropdown`.
  - **ScriptSeed** — Seed Number (default −1, live mode), Random ♻️ / Reuse 🔃 buttons, `Extra` checkbox revealing Subseed (−1), Subseed strength (0–1), From width / From height Number (−1 = current, 0 = off). `setup()` writes onto `p` only when strength > 0 / both dims > 0.
  - **ScriptRefiner** — InputAccordion off; Refiner checkpoint Dropdown, Switch at Slider (0.01–1, step .01, default .8) → `p.refiner_checkpoint/refiner_switch_at` consumed in `sd_samplers_common.apply_refiner`.
  - **ScriptStripComments** — alwayson, no UI; strips `//` prompt comments per `opts.enable_prompt_comments`.
- `scripts.control(p, "name", ...)` is how processing invokes hook chains generically (pipeline §4.8).

### 5.3 Postprocessing scripts (Extras tab) — `modules/scripts_postprocessing.py` [repo §B.3]

`ScriptPostprocessing`: `title()`, `sortkey`, `ui()` → **dict param-name → component**, `process_firstpass(pp, **args)`, `process(pp, **args)`, `image_changed()`. Runner chains over a list of `PostprocessedImage{image, info(dict, becomes infotext), shared(target_width/height dict), extra_images, nametags, disable_processing, caption}`; `pp.create_copy(new_image, nametags=…, disable_processing=…)` emits "before" intermediates (Upscale/GFPGAN/CodeFormer save -before-upscaled / -before_face_fix files). Extras UI: per-script visibility groups + ordered execution list. Built-ins: "Image caption", "Upscale", "CodeFormer", "GFPGAN".

### 5.4 Global callback registry (modules/script_callbacks.py) [repo §B.4; wiki §7.2]

All registered as `on_X(callback, name=None)`. Full list with payload dataclasses:

| Callback | Params dataclass | Fires |
|---|---|---|
| `on_app_started(demo, demo_api)` | — | after UI+API constructed (add routes here) |
| `on_before_reload()` | — | model reload start |
| `on_model_loaded(model, is_default_model)` | — | every checkpoint load |
| `on_ui_settings()` | — | extensions register settings (§5.5) |
| `on_ui_tabs()` | returns `[(gr.Blocks, tab_title, tab_id), …]` | **add whole new tabs** (how ControlNet/ADetailer inject UI) |
| `on_ui_train_tabs()` | | same for Training tabs |
| `on_before_image_saved(ImageSaveParams(filename, basename, name, js, info, p))` | | before PNG write — can veto/rename |
| `on_image_saved(ImageSaveParams)` | | receives mutable `params.pnginfo` **before** the file is written ⇒ canonical way to inject extra PNG-info keys (ControlNet, aesthetic scorers) [wiki §7.2] |
| `on_extra_noise(ExtraNoiseParams(noise, sigma, p))` | | img2img/hires re-noise — mutate `noise` |
| `on_cfg_denoiser(CFGDenoiserParams(x, denoised, cond, uncond, text_cond…))` | | inside each CFG eval (pre) |
| `on_cfg_denoised(CFGDenoisedParams(x, denoised, total_noise_pred_count))` | | after each denoise step (ControlNet's hook) |
| `on_cfg_after_cfg(AfterCFGCallbackParams)` | | after CFG combine |
| `on_before_component` / `on_after_component` | `ComponentCallbackParams(component, elem_id)` | see §5.1 |
| `on_image_grid(ImageGridLoopParams(imgs, grid))` | | before grid is saved |
| `on_infotext_pasted(infotext: str, vars: dict)` | | user pasted settings — mutate parsed `vars` before fields apply |
| `on_script_unloaded()` | | on Reload UI |
| `on_before_ui()` | | before Blocks built |
| `on_list_optimizers(optimizers)` / `on_list_unets(unets)` | | backend/training registries |
| `on_before_token_counter(BeforeTokenCounterParams(prompt, steps, styles, is_positive))` | | before CLIP tokenization for counter |

Wiki additions: `on_options_changed`-equivalent = per-option `onchange` (fires only on real changes, `options.py:163`) — no global broadcast besides that. **Callback identity = (name, category, filename)** with user-visible reordering via `metadata.ini` `[callbacks/…]` Before/After and `sort_callbacks`. [wiki §7.2]

### 5.5 Settings plane (declarative option registry) [repo §B.5; wiki §6.1]

Settings are a flat dict `shared.opts.data` + `opts.data_labels[key] = OptionInfo(default, label, component, params…, infotext='Infotext Label', visible=…, restrict_api=…, section=(key, Title, icon), onchange, js?)`, declared across core files + extensions; `opts.add_option` idempotent. Extensions add sections inside `on_ui_settings`:

```python
shared.options_templates.update(shared.options_section(
    ('sd_myext', "My Extension", "myicon"), {
        "myext_option": shared.OptionInfo(5, "Option", gr.Slider, {"minimum":1,"maximum":10,"step":1}, infotext="Option"),
    }))
```

- Settings tab renders **categories → accordions → per-type components** + Apply/Unload buttons; persisted to **config.json** (`--ui-settings-file`, atomic + `.bak`); `--freeze-settings[-in-sections "saving-images,upscaling" | -specific-settings "samples_save,samples_format"]` read-only modes. New settings groups appear without touching core (accordions created lazily).
- **Every option flows to five projections:** **API** (`GET/POST /sdapi/v1/options` persisted; per-request `override_settings` auto-restored), **infotext** (any option with `infotext=` set auto-appends to the parameters line *when non-default*), **quicksettings row** (`quicksettings_list` DropdownMulti, default `["sd_model_checkpoint"]`, choices = all keys), **generation-tab widgets** via extra-options-section (below), and settings persistence. `.needs_reload_ui()` markers gate restart-requiring changes.
- **`ui-config.json` sidecar** overrides per-elem-id display attributes (`minimum/maximum/step/value/precision`, radio defaults, checkbox states) **with no code change** — elem-id convention `<tab>/<Label>/<attr>` (e.g. `txt2img/CFG Scale/step`). Sugar-crush note: an analogous display-override file is cheap and extremely powerful for TUI layout.
- **Built-in ext `extra-options-section`** (the killer pluggability demo): settings listed in `extra_options_txt2img` / `extra_options_img2img` (DropdownMulti of every known option key) are **rendered as live widgets inside the generation tab** (accordion toggle, column count settings), with infotext round-trip — *any setting can become a form field at runtime, zero code*. The single most important pattern for sugar-crush's "future panels like ControlNet".
- `restrict_api` / `shared.opts.restrict_opts` ⇒ POST /options rejects those keys (400 listing them).

### 5.6 Built-in selectable scripts (scripts/ folder) [repo §B.6]

| Title | Tabs | What it demonstrates |
|---|---|---|
| `Custom code` | both, only with `--allow-code` | arbitrary python in `process()` — escape hatch |
| `img2img alternative test` | img2img | replaces sampler math (`sample()` override via monkeypatch) — advanced |
| `Loopback` | img2img | re-feeds output as input: Loops 1–32 default 4, Final denoising strength 0–1 default .5, Denoising curve Aggressive/Linear/Lazy |
| `Outpainting mk2` | img2img | iterative masked outward expansion, padding sliders |
| `Poor man's outpainting` | img2img | single-pass padded outpaint, blur-direction sliders |
| `Prompt matrix` | both | `<a|b|c>` cross-product → batch grid (§3.4) |
| `Prompts from file or textbox` | both | file/list batch runner |
| `SD upscale` | img2img | tile-based latent upscale: Upscale by 1–4 default 2, Tile width/height default 512, Tile overlap default 64 |
| `X/Y/Z plot` | both | axis Dropdown (per-axis `axis_options` name→parser→apply-fn tables — reference pattern for enumerated option axes) + values Textbox, plot type 2D/3D |

(X/Y/Z value syntax + axis catalog: §2.15.)

### 5.7 Extensions packaging (`Developing-extensions.md`) [wiki §7.3]

`extensions/<dir>/` autodiscovered; `install.py` (pre-launch deps via `import launch; launch.is_installed / run_pip`); `scripts/`, `javascript/`, `style.css`, `localizations/*.json` (same-name = replace, not merge), `preload.py::preload(parser)` (**adds cmdline args before parsing**), `sys.path` += dir, `scripts.basedir()` (import-time only!), unique filenames (module-cache collisions warn). **`metadata.ini`** graph: `[Extension] Name=<lowercase unique id> Requires=a, b|c` (pipe = either); folder-level `[scripts] Requires/Before/After` (ext names only) and file-level `[scripts/file.py] …` (may name another ext's file); sections apply equally to `javascript`/`localization` by rename; `[callbacks/<ext>/<file>/<cat>]` reorders callback chains; keys case-sensitive. Extra-networks pages: `ui_extra_networks.register_page(ExtraNetworkPage(...))` + `extra_networks.add_pages_from_ext`. Official extension index lives in a separate repo (wiki Extensions pages = its rendered JSON).

### 5.8 Mirror-design summary (wiki §7.4) + cheat-sheet (wiki §12)

Six items [wiki §7.4]:
1. **Option registry first**: declarative options (default, label, widget-spec, min/max/step/choices, section, infotext-key, onchange) drive TUI rendering, config persistence, CLI override, REST/IPC, PNG-info, paste — one source, five projections (exactly A1111's `OptionInfo` + api-reflect + `infotext_to_setting_name_mapping`).
2. **Section-placement + AlwaysVisible scripts** = how optional blocks (inpaint tools, video params, ControlNet-style panels) join a form without forking it.
3. **Pre-save image-info mutation hook** (`on_image_saved`) ⇒ any module can stamp its params into `parameters`.
4. **ui-config.json equivalent** ⇒ users retune ranges/layout without code.
5. **extra-options-section pattern** ⇒ "promote any setting to the main form" as a *setting*, not a code change.
6. **Context-menu/hotkey registry** ⇒ extensible interactions.

Ten-item form spec [wiki §12]: (1) one param registry rendering into form/config/CLI/API/PNG-info/paste, `infotext=<Key>` tags decide what lands in `parameters`; (2) exact A1111 ranges/defaults of §2.2; `−1`=random, ♻ reuse, Extra(subseed slerp+strength, resize-seed-from), ENSD, randn-source semantics §2.3; (3) prompt field with live pos/neg token counters incl styles, bracket-mismatch red state, comment-stripping on save, hotkeys Ctrl/Cmd± (weight ±5%), Alt+arrows (reorder), right-click forever/menu registry; (4) mask/inpaint section = §2.7 radios (mask mode, masked content 4-way, whole/only-masked + padding, blur; soft-inpaint-style extra sliders as optional subpanel); upload convention white=mask; (5) hires subpanel with 3 sizing behaviors + latent-vs-pixel upscaler split + optional per-pass prompt/sampler reveal (§2.4); (6) resize-mode radio copy §2.7 strings; img2img Batch-with-PNG-info checkbox group §2.13; (7) gallery: select→info sync, arrows, s/←/→/Esc modal, 📂💾🗃️🖼️🎨️📐✨ actions §2.18 + local delete (missing in A1111 core; images-browsing ext fills gap); (8) `parameters` chunk spec §3.5 **byte-compatible** incl. json-quoting rule, key order, `Version:` stamp, NovelAI import branch if feasible; (9) pluggability §5: sections+AlwaysVisible scripts, on_ui_tabs-style panel registry, on_image_saved info mutation, extra-options "promote any setting" block, context-menu API, preset save/load per §5.10 (Preset Utilities demand); (10) video panel fields per §6.5 table; frame timeline reuses §3.3 schedule grammar.

### 5.9 Backend launch flags & performance (operational context for skynet2) [wiki §9]

**Flags (`cmd_args.py`, complete, grouped)** [wiki §9.1]: Launcher/skip: `--skip-prepare-environment --skip-torch-cuda-test --skip-python-version-check --skip-install --skip-check-version --reinstall-…` · Paths: `--data-dir --models-dir --config (yaml) --ckpt --ckpt-dir --vae-dir --gfpgan-dir --gfpgan-model --ESRGAN-dir --codeformer-models-path --bsrgan-models-path --realesrgan-models-path --dat-models-path --ldsr-models-path --scunet-models-path --swinir-models-path --LDSR-models-path --clip-models-path --embeddings-dir --textual-inversion-templates-dir --hypernetwork-dir --localizations-dir --lora-dir(Lora ext preload) --styles-file (multi, wildcards) --ui-settings-file --ui-config-file --gradio-allowed-path --subpath` · Memory: `--medvram --medvram-sdxl --lowvram --lowram --no-half --no-half-vae --precision full|half|autocast --upcast-sampling --opt-channelslast --force-torch-fp16 --disable-model-loading-ram-optimization --use-cpu LIST --use-ipex` · Attention backends: `--xformers --force-enable-xformers --opt-sdp-attention --opt-sdp-no-mem-attention --opt-split-attention --opt-split-attention-invokeai --opt-split-attention-v1 --opt-sub-quad-attention --disable-opt-split-attention --sub-quad-q-chunk-size --sub-quad-kv-chunk-size --sub-quad-chunk-threshold --disable-nan-check` · UI: `--theme --use-textbox-seed --hide-ui-dir-config --freeze-settings[-in-sections | -specific-settings] --ui-debug-mode --allow-code --no-progressbar-hiding --disable-console-progressbars --autolaunch --share --ngrok --ngrok-options --listen --port --enable-insecure-extension-access --administrator --gradio-auth(-path) --gradio-debug --no-gradio-queue (websocket off) --max-batch-count(no-op)` · Server/API: `--api --nowebui --api-auth --api-log --cors-allow-origins(-regex) --tls-keyfile --tls-certfile --disable-tls-verify --timeout-keep-alive --api-server-stop --skip-load-model-at-start` · Files/misc: `--unix-filenames-sanitization --filenames-max-length 128 --no-prompt-history --no-hashing --no-download-sd-model --do-not-download-clip --disable-safe-unpickle --test-backend --test-steps --deepclean --allow-upgrade --device-id --log-level` (+ legacy no-ops `--gradio-img2img-tool/--gradio-inpaint-tool/--enable-console-prompts/--add-stop-route/--gradio-queue`). **Env:** `COMMANDLINE_ARGS` in `webui-user.bat|.sh`, `HF_HOME`, `TORCH_ALLOW_TF32_CUBLAS_OVERRIDE` etc.; documented precedence: **args > env > config.json > defaults**.

**Performance semantics** [wiki §9.2]: user-visible knobs that change speed/VRAM (and which change the picture — every nondeterminism source is seed-breaking, §3.7): attention backend table (xformers vs SDPA vs sub-quadratic vs split — equivalent output only within same impl), `no_half/no_half_vae` (fp16 rounding ⇒ different images; SD2 black-image workaround), `medvram/lowvram` (module paging), `TAESD` tiny-VAE decode (quality vs VRAM), `upcast_sampling`, `precision`, `sdp_no_mem`, token merging ratio, hypertile, `unload_cross_attention/keep_vae_loaded/discard_weights_on_swap/pin_memory/cuda_enable_preprocessor_cache` family, `cross_attention_optimization` dropdown + `on_list_optimizers` extensibility. [wiki §9.2]

**Troubleshooting condensed** [wiki §9.3]: black images (SD2 fp16) → `--no-half`/`--no-half-vae`; CUDA OOM ladder (batch→res→medvram→attention backend→TAESD); "invalid checksum"/download fails → `--skip-download-*`; extension breakage → `--disable-all-extensions` bisect; port conflict → `--port`; websocket issues → `--no-gradio-queue`; corrupt-model safety → `--disable-safe-unpickle` (danger); standing pointer: verify with `--test-backend` before reporting bugs.

### 5.10 Extension ecosystem (future panels sugar-crush should accommodate) [wiki §8]

- **ControlNet (Mikubill) — the reference panel design.** Multi-unit accordion; per unit: enable · control-image upload (+ draw canvas + **preprocessor dropdown**: canny/depth/normal/openpose/lineart/scribble/seg/tile/inpaint_global…, each with dynamic param sliders — threshold pairs, resolution, camera angle etc.) · ControlMode radio (balanced vs "prompts are more important"/"controlnet is more important") · weight slider (~0–2) · **starting/ending control step** sliders using the 0–1 / hires-aware 1–2 timeline of §3.3 · pixel-perfect option · merge-unit / interrogate / latency knobs; whole-sheet preset rows; contributes own infotext keys (`ControlNet-1: …`) + paste bindings + **XYZ axes** via §2.15/§5.1 mechanisms + its own model/asset browser.
- **sd-webui-additional-networks (kohya)**: extra LoKr/LoCon/LoHa… network pages + `<lora>` compatibility + per-network strength control outside core Lora ext.
- **Ultimate SD Upscale**: tiled upscale with tile size/overlap/denoise/seed-mode/per-line prompt offset sliders.
- **Deforum**: full animation *tab* — keyframe schedules (`0: param`), math expressions (seed/angle/zoom as functions of step), hybrid-video modes (depth/optical-flow warping of prev frame), 3D camera, border modes, cadence, init/from/to weights; exports mp4/gif/frame dirs.
- **sd-parseq (Parameter Sequencer)**: generic keyframe track system over every generation parameter — the closest thing to a video NLE UI in the ecosystem; its knob set (timeline, easing, per-frame param dicts, action scripting) is the model for a video-mode form.
- **Animator / video-loopback / gif2gif / Vid2Vid / steps-animation / prompt-travel / ebsynth**: frame-loop img2img pipelines w/ mask+strength schedules (pure diffusion frame-by-frame → flicker); steps-animation reuses *denoise steps of one image* as frames.
- **ddetailer**: per-detection auto redetail passes (detector dropdown, confidence/IoU, inpaint denoise, mask offset) — a **multi-pass post-generation pipeline panel**.
- **openOutpaint / inpaint-draw / canvas zoom exts**: infinite-canvas server-side workflows over the API.
- **Images-browser**: gallery+search over saved PNG info (console analog: gallery browser reading our own `parameters` chunks).
- **Tokenizer, DAAM, attention visualizers**: debug panels (tokenization/attention maps) — cheap high-value TUI panels (`on_cfg_denoiser` hooks).
- **Regional prompts / attention cond**: per-region prompt×mask conditioning panels.
- **Preset/collection tooling** (Preset Utilities, styles-plus, quick-css): confirms demand for first-class preset management — **our TUI should ship preset save/load/apply in v1.**

---

## 6. Beyond-A1111: Flux / Qwen-Image / SD3.5 / LTX / Wan 2.2 tunable supplement

> **Explicit supplement** [repo §D]: none of this exists in the A1111 core repo; compiled from ComfyUI/SD.Next/Diffusers-era pipeline knowledge (state of practice, mid-2026). Mark UI groups as such so future maintainers know what maps to A1111-compatible fields and what is new surface.

### 6.1 Universal modern-image controls (Flux / SD3.5 / Qwen-Image class) [repo §D.1]

| Control | Type | Range/default (typical) | Meaning |
|---|---|---|---|
| guidance / true-CFG | slider | Flux dev ≈3.5 (0–20; guidance-distilled → 1.0 ignored); SD3.5 ≈4.5 (1–10); Qwen-Image ≈4.0; A1111-style CFG 1–30 | Flux/SD3/Qwen use **true CFG** (separate cond/uncond batch) or a baked guidance embedding (Flux: normalized 0–1 scalar fed to the model, ~3.5/10 ≈ 0.35 embedding) — UI must expose both "Guidance (model)" vs "CFG scale (classic)" because semantics differ; distilled (schnell/Lightning/Hyper-SD) checkpoints want guidance≈1 and low steps |
| steps | number | 20–50 base; **4–8 with Lightning-style LoRAs; 1–4 Hyper-SD**; Flux dev sweet spot 20–28 (40+ overcooks) | flow-matching sigmas are non-uniform; step count interacts with scheduler choice |
| solver / sampler | dropdown | Flux: Euler (default), DPM++ 2M, dpmpp_2m_sde_karras, Derivative V2-M (DPM-Solver++-family "dpm_solver_variant" heun-m-/rk2-m); SD3.5: `res_multistep` (default), `simple`, dpm++_sde; Qwen: Euler/res2m | diffusers "schedulers" collapse to flow-matching variants — expose solver + sigma-shift as separate tunables |
| sigma shift / timestep spacing | slider | Flux base: `sigmoid/power2/beta/none` + shift ≈ 0.5–3.1 (3.1 SD1.5-like, 0.5–1.15 Flux "butterfly/sigmoid" default, higher→more early-step time); Qwen ~3.1; "time shift" in Wan | equivalent in spirit to A1111 Karras rho but for rectified-flow/linear-sigma schedules |
| denoise (strength) | slider | 0–1 default 1.0 (t2i), i2i edit 0.3–0.75 | same field as img2img denoising_strength; also per-node "strength" for Refiner/Redux |
| LoRA stack | repeatable list | {model, strength 0–2 (negative = inverse-LoRA in some stacks), optional start/end % 0–1} | **A1111 has no first-class LoRA API (extensions do it) — we need it built in**; Flux/SD3.5/Qwen all LoRA-able |
| text encoder toggles | checkboxes | SD3.5: drop T5-XXL (memory); Qwen-Image: none; Flux: T5 required for long prompts | affects prompt length limits (77-token CLIP vs T5 512) |
| model precision/offload | dropdown | fp8 / fp8_disk / bf16 / GGUF-Q8… | worth a "backend" select since we call a remote service — **keep it out of the form** |
| resolution presets | dropdown | 512², 768², 1024², 2048²+ (per model class: SD1.5 512, SDXL/Flux/SD3.5/Qwen 1024) + aspect-ratio picker (1:1, 16:9, 9:16, 4:3, 3:2, 2:3, 3:4, 21:9) | modern DiTs are resolution-tuned; width/height alone insufficient |
| Flux-specific | | `Flux guidance` embedding value; FLUX.1-Fill (inpaint model), FLUX.1-Canny/Depth/Redux (img variants), Kontext (multi-image instruction edit, strength 0–1 + "max shift") | i2i conditioning surface — §6.3 |
| Qwen-Image-specific | | Qwen-Image-Edit 2509 multi-image input (image 1..n order matters), `edit_image_strength` (ComfyUI exposes conditioning scale), true-CFG + shift 3.1, distilled 4/8-step LoRA | |
| negative prompt | textarea | still honored SD3.5/Qwen; **Flux dev ignores naive negative** (CFG-off path) — UI should grey it out when guidance≈1 | |

### 6.2 Video-diffusion controls (Wan 2.2 / LTX-Video class) [repo §D.2]

| Control | Type | Range/default (typical) | Meaning |
|---|---|---|---|
| num_frames | number | Wan 81/121 typical, LTX 121/193/257 (must be 8k+1); 25–257 | temporal VAE compression 4×(+1) — frame count must satisfy the stride law; **snap slider to the valid ladder** |
| fps | number | Wan 16/24, LTX 24–30 | metadata + affects motion-speed conditioning in some pipelines |
| resolution | dropdown | 480p/580p/720p/1080p classes (Wan 2.2 5B: 720p24; 14B: 480p/720p16) | token budget → VRAM |
| motion strength / motion bucket id | slider | Wan: none built-in; `motion_bucket_id`-style conditioning in AnimateDiff-era (1–255 default 127) and "Motion score" in some wrappers | expose 0–1 "motion magnitude" that maps to the per-model mechanism |
| i2v image conditioning | image picker + dropdown | **first-frame** (universal), **first+last-frame** (Wan FLF2V / LTX keyframes), reference-image style (LTX "ic-lora"), v2v (Wan VACE) | A1111 init_images covers only first-frame; add explicit role selector: none/first/first+last/reference/video-init |
| camera motion | preset dropdown + sliders | Wan 2.2 has no explicit camera params in core; wrappers expose Motion-LoRA strength; AnimateDiff-era camera: pan/tilt/zoom/horizontal/vertical/roll −10..10 | keep as optional "camera" accordion mapping to LoRA/strength pairs |
| two-expert denoise split (Wan 2.2 T2V-14B A14B) | number pair + slider | `high-noise model` + `low-noise model` + boundary/split sigma (timestep ≈ 0.875 / 900 of 1000) | model field becomes a pair; UI: "Expert boundary" slider 0–1 |
| double CFG (Wan) | 2 sliders | `CFG scale` + `CFG scale 2` (second pass / negative branch weight per expert; defaults ≈4–6, sometimes cfg2 lower) | |
| flow shift / sigma schedule | slider | Wan `sample_shift` 3–12 (commonly 5/12 by res), LTX `shift` ≈ 1.7–3 | same knob family as §6.1 sigma shift |
| STG (Spatio-Temporal Guidance) | slider + skip list | LTX/ERNIE-style: `stg_scale` 0–2 (≈1 typical), `stg_block_indices` | skip-layer guidance — expose when the model family supports it |
| distilled/fast variants | checkbox+steps | LTX 2-stage (pro→distilled_refiner), Wan 5B preview, Lightning LoRAs 4–8 steps, TBCT training-free cache, teacache/threshold, first-block-cache | caching params: `rel_l1_thresh` 0.25–0.4 etc. — advanced accordion |
| frame interpolation (post) | dropdown+number | RIFE / FILM, multiplier 2/4/8 | video-only postprocessor — maps to the postprocessing script plane (§5.3) |
| video upscaling (post) | as A1111 extras | per-frame upscaler (RealESRGAN video) | |
| seed/variation | | identical semantics to A1111; per-frame noise offset not standard | **reuse the A1111 seed row unchanged** (§2.3) |

### 6.3 i2i / inpaint modern additions [repo §D.3]

- **FLUX.1-Fill**: dedicated inpaint checkpoint — inpaint becomes a *model selection* concern, not a mask pipeline; still needs the A1111 mask/inpaint-area fields (§2.7).
- **Kontext / Qwen-Image-Edit / SeedEdit-style**: natural-language multi-image **instruction edit** — surface = list of input images + strength/denoise; no mask needed; negative prompt usually n/a.
- **Redux** (Flux image variant): style-transfer strength 0–1 — image conditioning without denoise.
- **IP-Adapter family** (SDXL/SD3): image-prompt weight per adapter, weight type (linear/style/…), combiner add/multiply — the alwayson-script pattern (§5.1) is the right shape.
- **ControlNet-style stacks**: unit arrays (model, strength, start/end %, preprocessor + threshold params) — proven API shape: `alwayson_scripts: {"controlnet": {"args":[unit,...]}}`.

### 6.4 Sugar-crush UI-mapping notes (repo opinion) [repo §D.4]

- Every A1111 "Number with live/reuse buttons" (seed, hires W/H) and "Slider with numeric readout" maps to our popup-form numeric widget with clamp+step — the ranges/defaults in §2 tables are the spec.
- `script` Dropdown + `alwayson_scripts` ⇒ serialize params as `{script_name, script_args, alwayson_scripts}` to be wire-compatible with any A1111-derived backend (Forge/SD.Next/RV-Rayu forks), plus a v2 named-dict mode.
- Keep the `override_settings` escape hatch as an "Advanced JSON" textarea — it covers every opts tunable (§2.11) without a bespoke widget.

### 6.5 Video semantics — what the wiki documents, and mapping to Wan 2.2 / LTX-Video [wiki §11]

**Honest finding:** the current wiki documents **no AnimateDiff/modelscope/sd-webui-video page**; video lives entirely in the extension ecosystem (the official Extensions index carries an `"animation"` category: *"an extension related to creating videos with stable diffusion"*). Wiki-recorded animation-adjacent features: Deforum (official port — keyframed 2D/3D sequences, math params *inside prompts*, dynamic masking, depth warping), Animator, Video Loopback, gif2gif, steps-animation, prompt-travel, ebsynth-util, depth-maps-3D-video — plus the core primitives a video mode needs (batch seeds, §3.3 step-timelines, img2img Batch-with-PNG-info §2.13, hires per-frame, preprocessors via §5.10, prompt-morph/AND interpolation).

**A1111-ecosystem video UI semantics** (AnimateDiff-Evolved et al.; marked external knowledge): frame count/duration + fps at export; **motion module** asset slot (per model-gen, version dropdown); **context window** size/overlap/stride + per-frame prompt scheduling ("Prompt S/R"-style per-frame rows or schedule strings + interpolators) + init-image conditioning at denoise<1 (v2v) + corner-case knobs (noise augmentation, overlapping denoise, LoopN/seamless).

**Mapping to sugar-crush (design judgment):** the Wan 2.2 / LTX form should expose, in A1111 idiom:

| A1111 concept | Wan 2.2 / LTX analog |
|---|---|
| txt2img tab vs img2img tab | single tab, optional **first-frame slot** (TI2V) / clip-condition slot (v2v denoise slider) |
| Batch count → frames | **frame count / duration sec** (+ fps export) |
| Steps/scheduler | per-model sampler defaults; expose steps (few for LTX distilled; Wan T2V 20–50); guidance/CFG (Wan two-stage → expose text-CFG + late-CFG like refiner switch) |
| Seed/subseed slerp | identical semantics for noise init; keep |
| Prompt `[from:to:when]` | per-frame prompt schedule rows / `when`-in-seconds (timeline math 0–1 fraction works directly) |
| Hires fix | per-frame spatial upscale pass + temporal-consistency denoise (like ultimate-upscale-for-frames) |
| Extra networks (LoRA cards) | motion-LoRA / motion-module asset browser entries via the same register_page mechanism |
| Context batch/overlap (A1111-ext idiom) | Wan windowed generation overlap + frame-blend slider |
| `parameters` PNG info | per-video PNG-info sidecar (JSON-in-text or mkv tag `comment`) + first-frame PNG carrying it |
| Scripts dropdown | video generation as a `Script`/panel so ControlNet-style add-ons (camera, depth control) can register sections |

---

## 7. SugarCraft widget/component inventory for the form

Source: [forms] — full inventory of sugar-crush + candy-forms widgetry with file:line, plus the gaps and the recommended panel architecture. Verified against the live tree 2026-10-07 (candy-forms is a crush dependency `sugar-crush/composer.json:47`; candy-mosaic `:55`).

### 7.1 Verdicts up front [forms §0]

- **No widget gap** for the requested form except a **tab bar** and a **visible pill radio**: candy-forms already ships `Slider`, `Select` (incl. `withEnum()`), `MultiSelect`, `Confirm`, `Input`, `Text`, `FilePicker`, `Viewport`, and the whole `Form`/`Group`/`KeyMap` state machine, with focus/scroll/validation and immutable-fluent `with*` ergonomics.
- **Recommended hosting is Shape A — a full-band App-owned modal view following `Tui/Settings/SettingsEditor.php` exactly** (state slot, key gate, wheel routing, zone-click routing, render branch), not a Veil popup (Shape B) or a docked pane (Shape C) for v1.
- Command + palette + i18n + drift-guard obligations are fully mapped (§7.6).

### 7.2 Component inventory (with file:line) [forms §1]

| Need | Component | Where | Notes |
|---|---|---|---|
| Text input (single line) | `Input` | `candy-forms/src/Field/Input.php:120` (`::new()` factory) | masked mode `withPasswordMask`, restriction, echo modes |
| Textarea (multi-line) | `Text` | `candy-forms/src/Field/Text.php:25` | rows, wrap; prompt fields map here |
| **Slider** | `Slider` | `candy-forms/src/Field/Slider.php:111` | renders `[███◆░░░]` bar (`view()` :221 normalise = clamp + lattice-snap; throws on `min >= max`); steps/labels — perfect for all A1111 sliders |
| Dropdown | `Select` | `candy-forms/src/Field/Select.php:101` | `withEnum()` :233 binds a PHP enum (samplers/schedulers/upscalers as enums); searchable; `withHeight()` list window |
| Multi-select dropdown | `MultiSelect` | `candy-forms/src/Field/MultiSelect.php:92` | options :78-110, `withEnum` :118 — styles/override-settings |
| Checkbox | `Confirm` | `candy-forms/src/Field/Confirm.php:42` | yes/no toggle w/ labels — every A1111 checkbox |
| Read-only text row | `Note` | `candy-forms/src/Field/Note.php:24` | computed readouts (`txtimg_hr_finalres` analog) |
| File picker | `FilePicker` | `candy-forms/src/Field/FilePicker.php:37` | + crush's own `sugar-crush/src/Tui/DirectoryPicker/DirectoryPicker.php:43` (`open():70` consumes-as-action precedent) |
| Color | `Color` | `candy-forms/src/Field/Color.php:86` | brush-color settings analog |
| Date | `Date` | `candy-forms/src/Field/Date.php:87` | |
| **Form state machine** | `Form` | `candy-forms/src/Form.php:40` | `::groups()` :91, `nextGroup()` :311-324, `update()` :337, `values()` :554, `hydrate()` :624, typed getters :759-857, `isSubmitted()` :870 |
| Key routing map | `KeyMap` | `candy-forms/src/KeyMap.php:28` | Tab/Shift-Tab/Enter/Esc field navigation |
| Scrollable viewport | `Viewport` | `candy-forms/src/Viewport.php` (586 L) | `pageUp/Down()` :375-408, `scrollPercent()` :435, `withMouseWheelEnabled()` :329 — tall forms |
| Tab strip (settings) | `SettingsTabStrip` | `sugar-crush/src/Tui/Settings/SettingsTabStrip.php:19` | **static render, non-interactive**; canonical interactive `Tabs` lives in sugar-bits (candy-zone-based; divergence documented :12-16) |
| Styled borders | `Sprinkles` `Style`/`Border` | candy-sprinkles | panel chrome |
| Poster/thumbnail grid | `PosterGrid`/`PosterCard` | sugar-gallery (`withImage:82`) | **not currently a crush dep** (G-4) |
| Image display | `Mosaic` | **already wired**: `sugar-crush/src/Renderer.php:16-18`, `:1726` | see §8 |
| Toasts/progress | `Toast` | `Chat.php:1515`; progressToast :234, alert :209; painted `Renderer.php:6598`, suppressed under modals :2076 | generation progress notices |
| Veil overlay | `Veil` | painted `Renderer.php:2059-2065` | Shape B substrate |
| Docked panes | `DockLayout` | `toArray :673/:712`; `App.php:44-45` PaneDragController | Shape C substrate |
| i18n | `Lang` | `candy-pty/src/Lang.php:27` pattern; crush `lang/en.php` (~2,354 lines, `tui.<area>.<name>`) | every label a Lang key |
| Setting schema/writer | `SettingsSchema::all :72` / `byKey :97` / `inCategory :115` / `layeredKeys :128`; `SettingsWriter` | sugar-crush/src/Config | `SettingsFieldFactory` builds candy-forms fields from schema; `SettingsWriter::refusal()` is the single validation authority |
| Test harness | `ProgramSimulator :27`, `ScriptedInput :45`, `assertGoldenAnsi` (`UPDATE_GOLDENS=1`) | candy-testing | **NOT currently in crush require-dev** (G-7 decision) |

**Façade law** [forms]: sugar-bits/sugar-prompt re-export forms classes via `class_alias` — code against the **canonical `SugarCraft\Forms\*`** namespace only (AGENTS.md #1275/#1312/#1314); canonical non-forms widgets (Tabs/Help/Paginator/Progress/Table/Tree) live in sugar-bits, which is not a crush dependency.

### 7.3 candy-forms deep notes [forms §3]

- `Slider::update()` handles arrows + typing a numeric overlay; value normalisation (clamp to [min,max], snap to the step lattice) happens in `view()`'s `normalise()` — the widget self-enforces A1111 slider semantics (e.g. steps 1–150/1, cfg 1–30/0.5).
- `Select::withEnum()` is the exact fit for sampler/scheduler/upscaler/resize-mode dropdowns whose choice sets are closed enumerations in our code.
- `Form::hydrate()` round-trips a values map into fields — the plumbing backbone for §3.6 infotext paste-back (hydrate from a parsed `parameters` dict).
- `Viewport` + `Form` together make arbitrarily tall panels scrollable with the mouse wheel (`withMouseWheelEnabled`), matching crush's wheel routing into modals (§7.4).
- All fields are immutable-fluent with `XSet` sentinels (AGENTS.md canonical `Mutable` trait); nullable fields carry paired `bool $XSet` flags.

### 7.4 Recommended panel architecture [forms §2]

**Shape A (RECOMMENDED v1) — full-band App-owned modal, SettingsEditor precedent.** Proven complete pattern in crush:

| Seam | file:line | Detail |
|---|---|---|
| State slot | `sugar-crush/src/App/App.php:327` | `private ?SettingsEditor $settings = null;` — analogous `?GenForm $genForm` |
| Key gate | `App.php:2942` | when modal open, keys route to it with only Ctrl+C / F10 exceptions |
| Wheel routing | `App.php:2344` | wheel → Up/Down into the modal |
| Zone click | `App.php:2796` | `Mark::zone` hit-testing routes clicks; `genform:` prefix zones |
| Render branch | `sugar-crush/src/Tui/Renderer.php:566` | full-band render mirrors `renderSettingsEditor :847` |
| Zone trio | `Tui/Settings/SettingsEditor.php:75-77` | ZONE_PREFIX constants pattern (list/body/help) |

**Shape B — Veil popup overlay chain** (`sugar-crush/src/Renderer.php:2004-2020`): existing chain order keyHelp → permissionPrompt → palette → sessionPicker → directoryPicker; **ONE law: a painted overlay == a routed overlay** (any new overlay must join both lists in-step — `KeyHelpTest` chain-order pin flips in the same commit); `clipRowsToCols :2029` width invariant; `liftZonesUnderOverlay/restore :2054/:2069` — U+E000/E001 zone marks are lifted around `Veil::new()->withBackdrop(50)->composite` (:2059) and restored after; Chat doors `:3139/:3152/:3154` + Escape door `:2975`; wheel → Up/Down `Chat.php:7832`; palette zones `PALETTE_ITEM_ZONE_PREFIX :779`, `handlePointer Chat:7820/:7950`; `setPaletteAbandoned` seam `:1045/:1052` + `App:3684` (E666/E682). Use B for a compact "quick settings" popup later; A for the full form.

**Shape C — docked pane** (`DockLayout::withSlotAdded`): heavier; right shape for a persistent gallery/history pane in phase 2, not the modal form.

### 7.5 Gaps G-1…G-7 [forms §4]

| # | Gap | Recommendation |
|---|---|---|
| G-1 | **Tab bar** — SettingsTabStrip is static render; canonical interactive Tabs is in sugar-bits (not a dep) and candy-zone-based | Copy the SettingsTabStrip + App-mode switch pattern (simplest, in-tree), **or** port a candy-mouse-hittable Tabs into candy-forms (MATCHUPS bubbles/tabs note) |
| G-2 | **Visible pill radio** (A1111 Mask-mode/Masked-content radios) | Use `Select withHeight(3)` inline list now, **or** add a `Field\Radio` to candy-forms (small; the Confirm/Select mechanics cover it) |
| G-3 | Segmented button group | `Confirm` covers booleans; multi-choice → Select |
| G-4 | Thumbnail grid (gallery/extra-networks) | sugar-gallery `PosterGrid` + require bump **via path-repo-closure skill (require entry ONLY — never a per-lib repositories[] block)** |
| G-5 | Progress meter | candy-async/`Spinner` exists; `progressToast` covers generation notices |
| G-6 | KeyMap customization | no gap — follow the existing gate/exception rules (§7.4) |
| G-7 | candy-testing / candy-vt dev-deps | decision needed: adding them enables ProgramSimulator/golden-ANSI tests of the modal (recommended) but changes crush dev-deps; snapshot-mode tests exist without it (§10.3) |

### 7.6 Command / palette / i18n / drift-guard wiring [forms §2b/§2c]

- `CommandSpec` readonly props `:171-224` (name, description, argumentHint, requiresInput, …); `BuiltInCommands::all()` consumed by `CommandRegistry:89-93`; dispatch short-circuit like settings at `App.php:3379-3381`; palette actions route `runCommand` vs `runPaletteAction :3390`.
- New commands: `/generate` (open form), `/image`, `/video` — each = one CommandSpec row + a Chat dispatch arm + README/docs roster updates **in the same commit** (drift guards below).
- i18n: every label via `Lang::t($key, $params)` (`candy-pty/src/Lang.php:27` wrapper; lookup exact → base → en → raw); keys `tui.genform.<name>`; locale files `sugar-crush/lang/*.php` per add-locale skill.
- **Mandatory drift guards that will red-line until updated in-step:** `KeyBindingDriftTest` (any new key chord → registry row + docs), `ReadmeRosterDriftTest` (command roster), `TrustKeyDocumentationDriftTest`, `EnvRosterDriftTest`, `ConfigWriteProducerDocumentationDriftTest` (any new persisted setting), plus `KeyBindingRegistry` `CONTEXT_*` :62-86 + `live()` :118 for modal contexts. A new test file must land in `scripts/parallel-tests-durations.tsv` and the suite figure in `sugar-crush/tests/Config/Support/suite-figure.json` + README must be re-pinned (AGENTS.md gotcha).

### 7.7 Wiring sketch (from the forms report) [forms §6]

```
Lang keys tui.genform.* (en.php)
config: GenerationSettings category rows (SettingsSchema + SettingsWriter::refusal validation)
src/Tui/GenForm/GenForm.php                — TEA model (init/update(Msg): [Model,?Cmd]/view/subscriptions)
Form::groups([Params, Advanced, Output])   — three groups mirroring A1111 categories
App slot ?GenForm + key gate + wheel + zone trio (genform:list/body/help) + render branch
GenFormAction enum { CANCEL, PREVIEW, GENERATE, SAVE_PRESET }  — action enum; App owns the writer (no I/O in view-models)
progressToast notices; Mosaic render of returned bytes via the EXISTING ToolResult image pipeline
GenFormTabStrip (clone SettingsTabStrip)   — Params/Advanced/Output or mode tabs
/generate command + Chat dispatch arm
tests: GenFormKeysTest / GenFormRenderTest (golden ANSI) / GenFormNarrowTest / GenFormSaveFlowTest + durations.tsv row + suite-figure re-pin
```

---

## 8. Image/video rendering stack (terminal display)

Source: [media] — full audit of candy-mosaic/sugar-reel/overlay transport, the phlix-console-client reuse patterns, and sugar-crush's already-shipped image pipeline. Headline verdict [media]: **the monorepo already contains almost everything needed.** candy-mosaic is a full port of charmbracelet/x/mosaic with ALL seven requested display modes plus probe-based detection, dithering, scaling, APNG/animation, disk cache and an out-of-band `ImageOverlay` paint channel that **sugar-crush already wires end-to-end for image-bearing tool results**. sugar-reel is a complete terminal video player (ffmpeg raw/PNG pipe → timed redraw, 8 render modes, seek, subtitles, audio via ffplay/mpv) — it simply isn't depended-on by sugar-crush yet. phlix-console-client is a *consumer* of the same stack, so its gems are app-level integration patterns (graphics-mode chrome, grid-vs-blob constraint, trickplay/semaphore/caching loaders), not new capabilities. Genuinely missing pieces: a user-switchable render-mode setting in crush, a crush pane/command hosting the reel Player, the image *upload* (multipart/base64 img2img) client-side plumbing in `sugar-crush/src/Providers`, and an encode-to-PNG helper for source images.

### 8.1 Capability matrix (protocol → provider → status) [media]

| Capability | Provider | Status |
|---|---|---|
| iTerm2 inline (OSC 1337, base64 PNG) | `candy-mosaic/src/Renderer/Iterm2Renderer.php` (`:12` OSC 1337, `:54` base64 PNG) | **exists** |
| Kitty graphics (transmit/place, virtual ids) | `candy-mosaic/src/Renderer/KittyRenderer.php` + `KittyOptions.php` (`KittyOptions::transmit(id)->withUseVirtual(true)`, README demo of `a=p` placement) | **exists** |
| Sixel (+dither choice) | `candy-mosaic/src/Renderer/SixelRenderer.php`, `Dither.php` (enum incl. `FloydSteinberg`, `Mosaic::sixel(Dither)` at `Mosaic.php:249`) | **exists** |
| Half-block ▀ truecolor | `candy-mosaic/src/Renderer/HalfBlockRenderer.php` (`Mosaic::halfBlock()` `Mosaic.php:197`) | **exists** |
| Quarter-block ░▒▓█ 2×2 | `candy-mosaic/src/Renderer/QuarterBlockRenderer.php` (`Mosaic.php:209`) | **exists** |
| 256-color blocks / ASCII | `AsciiRenderer.php` + `AsciiColorMode` enum `Mono\|Ansi256\|TrueColor` (`AsciiColorMode.php:11-20`); `Mosaic::ascii()` `Mosaic.php:229` | **exists** |
| External fallback renderer | `ChafaRenderer.php` (spawns `chafa`, memoized availability; `Mosaic::chafa()` `Mosaic.php:270`) | **exists** |
| Terminal capability detection | `Detect.php` — env heuristics + DA1 `\x1b[c` query + XTWINOPS 14/16/18 font-size probe, 100 ms timeout (`:20-24`), per-process cache (`:37`) | **exists** (no XTGETTCAP — see gaps §8.6) |
| Color-depth detection | `candy-palette/src/Probe.php:36` `colorProfile()` (COLORTERM/TERM/NO_COLOR/FORCE_COLOR + `infocmp` upgrade `:130`) | **exists** |
| PNG/JPEG/WEBP decode | `candy-mosaic/src/ImageSource.php` — `fromFile:138`, `fromString:215` via ext-gd; format sniff incl. GIF `:280`, webp `:289`; guards `MAX_PIXELS=50M:29`, `MAX_BYTES=64MiB:38` | **exists** (this box's gd has WebP) |
| Animated GIF / APNG decode | `ImageSource::fromAnimatedFile:501` → `Animation` (frames+delays); `ApngDecoder.php` (fcTL/fdAT/acTL spec); budget notes at `ImageSource.php:42-63` | **exists** |
| MP4/AVI/WEBM video decode | `sugar-reel/src/Decode/FfmpegDecoder.php` — ffmpeg pipe; pure-PHP GIF fallback `GifDecoder.php` | **exists** (ffmpeg dep present) |
| Video playback loop (frame-at-tick) | `sugar-reel/src/Player.php` — TEA Model: `open:170`, `update:402`, `updateTick:434`, `view:871`, `frameToBuffer:924`, `renderDirect:1164` (graphics modes bypass cell buffer), seek `withSeek:1244`/`seekToSeconds:1337`/`frameAt:1385`, resize rebuild `rebuildDecoderAt:1220`, `subscriptions:1631` | **exists** |
| Animation-in-TUI driver (GIF/APNG) | `candy-mosaic/src/AnimationDriver.php` (`init:46`, `update:64`, `view:93`, `FrameTickMsg`) + `Animation.php` | **exists** |
| Aspect/scale math | `candy-mosaic/src/Scale.php:60` `computeDimensions`; letterbox+pad in ffmpeg `-vf` (`FfmpegDecoder::buildCommand:365-376`, `force_original_aspect_ratio=decrease` + center pad, quarter-block grid-squash); crush `/2` cell-aspect (`Renderer.php:4983`) | **exists** |
| Color quantization / nearest palette | `candy-palette/src/NearestColor.php` — Lab-space `ansi256():61`, `ansi16():77`, `palette256Lab():148` | **exists** |
| Out-of-band overlay paint into diff-rendered frames | `candy-core/src/ImageOverlay.php` — `MARKER_BASE=0xE002:62`, `marker:97`, `markerBlock:112`, `resolve:145`, `paint:229`, `signature:249`, `coveredRows:267`; consumed in `candy-core/src/Program.php:1353-1377` | **exists** |
| Image placement lifecycle (multi image, release) | `candy-mosaic/src/ImageLayer.php` — `digestFor:99`, `place:112`, `placeTracked:136`, `release:210`, `releaseAllExcept:234` | **exists** |
| tmux handling | `Capability::*(..., bool $inTmux)` + `TmuxPassthroughDecorator.php` (DCSS passthrough) | **exists** |
| Terminal→PNG/SVG export (inverse) | `candy-freeze/src/PngRenderer.php`/`SvgRenderer.php`; ANSI→GIF `candy-vcr/src/Encode/FfmpegGifEncoder.php:24-118` | exists (not requested) |
| SSRF-guarded remote image fetch | `ImageSource::fromUrl:1119` + `overrideHostResolver:1181`; async `fromUrlAsync:1428` | **exists** |
| Audio (out of scope) | `sugar-reel/src/AudioPlayer.php:15-104` — ffplay `-nodisp -autoexit` / mpv `--no-video` via `proc_open` array form | exists |

### 8.2 candy-mosaic — the protocol ladder and its detection [media]

Public entry `Mosaic.php`: `probe():80` · `auto():111` · `diagnose(): ProbeReport :137` · forced backends `kitty():173` `iterm2():185` `halfBlock():197` `quarterBlock():209` `ascii():229` `sixel():249` `chafa():270` · `withDither():291` · `protocol():319` · **`isInline():352`** (the inline-vs-blob switch) · `render():398` · `builder():499` (MosaicBuilder `withRenderer`/`withResize`) · `withScale():558` · `adaptive():569` (AsyncRenderer + LRU) · `precompute():577` · **`fromModeString():598`** (user-mode-string → Mosaic — the vocabulary for a crush setting) · `poster():642` / `posterAsync():679` / `posterFile():712` (fetch+render+cache helpers). `composer.json` requires `ext-gd`, `ext-mbstring`, candy-core, candy-flip, candy-palette, react/promise; suggests react/http — deps satisfied on this box. [media]

**Detection detail (`Detect.php`):** precedence **Kitty → iTerm2 → Sixel → HalfBlock** (`:17`). Env: `KITTY_WINDOW_ID`, `TERM_PROGRAM∈{WezTerm,ghostty}`, `TERM~xterm-kitty` (`:460-467`); iTerm2 family incl. mintty/`LC_TERMINAL=iTerm2` (`:469+`). DA1 query only when env is inconclusive (`:49-77`); XTWINOPS 14/16 for cell pixel size → `CellSize` (`probeFontSize`). Test seams: `setProbeStdin:105`, `lastDa1Reply:92`, `reset:82`. E318 hardening: dead-fd-0 handled without fatal (`:230-260` docblock). Gap: no XTGETTCAP `Qu;` sixel query; DA1 answers only "sixel yes/no" per reply `c` param (`Detect::parseDa1Reply`); XTVERSION plumbing exists in candy-core (`TerminalVersionMsg`) but mosaic's `Detect` does not consult it — low priority given the env+DA1 ladder works on every terminal in scope. [media]

### 8.3 sugar-reel — terminal video player (not yet a crush dep) [media]

README: "plays mp4/gif/avi/webm … like `mpv -vo tct`, but in PHP". Requires candy-core/buffer/palette/mosaic/flip. `Player::open(videoPath, cellsW, cellsH, fpsOverride, Mode, loop, ramp, cellPxW, cellPxH, ?WebVtt, headers[], frameBudgetMs)` `:170`; factory-injectable `fromDecoder:267`, `openForTest:352`. `Render\Mode` enum = **the exact requested mode set** (`Mode.php:15-36`) + `isGraphics():57`; `RendererFactory::autoMode()` picks by capability. `Decode\FfmpegDecoder::buildCommand:330` — **two stream shapes**: `rawvideo rgb24` for cell modes, `image2pipe -vcodec png -compression_level 1` split on IEND for graphics modes (`:355-360`, `nextPng():523`); fast seek `-ss` before `-i` (`:302-306`); network: `-reconnect` flags + authenticated `-headers`/`-user_agent` input passthrough (`:340-350`, `HttpHeaders.php`); letterbox pad filter chain with quarter-block grid squash documented at `:313-320`. `Source\Probe.php:27-65` — `which(ffmpeg|ffprobe|ffplay|mpv)` availability probes. `Source\VideoSource::probe:120` — ffprobe JSON → duration/fps/dims. Audio: ffplay/mpv subprocess, silent no-op when neither installed (`AudioPlayer.php:25`). Related: **candy-flip** = ASCII GIF animation viewer (port of namzug16/gifterm; `Decoder/Frame/Player/Renderer/TickMsg`, `solid`/`density` luma presets, Space/←/→ keys) — superseded for embedding by sugar-reel's `GifDecoder` path / mosaic `AnimationDriver` but a clean reference. [media]

### 8.4 candy-core overlay transport (how images ride a diff-rendered TUI) [media]

`ImageOverlay.php`: view text carries zero-width PUA marker cells starting at `U+E002` (`MARKER_BASE:62`, `MAX_IMAGES:6398:89`; chosen disjoint from the `U+E000/U+E001` zone sentinels swept by `Sanitize::untrustedForMarkedFrames`). `Program.php:1353-1377`: each frame → `ImageOverlay::resolve()` strips markers and collects paints; `signature()` diff skips re-paint when placement unchanged; `paint()` written after the frame body; `coveredRows()` recorded to protect image rows. Msgs: `CapabilityMsg` (generic query/reply, `:29`), `TerminalVersionMsg` (XTVERSION — "only emit Kitty graphics on kitty 0.21+" gate, docblock `:14-19`). [media]

### 8.5 phlix-console-client reuse patterns (app-level, same stack) [media]

The client already depends on candy-mosaic, sugar-reel, sugar-gallery — reuse here is *patterns*, not ports. All paths relative to its root.

| phlix file | purpose | verdict |
|---|---|---|
| `src/Capabilities.php:26-68` | doctor report: `Mosaic::auto()` + `capability()` + `Probe::colorProfile()` + tmux flag | **adopt shape** into crush `/doctor` (crush already partial: `Doctor.php:11,144`) |
| `src/Media/MosaicFactory.php:26-75` | **Grid-vs-blob law**: graphics protocols emit one opaque escape blob with no per-row structure — stitching cards beside it "shreds it" ⇒ tiled grids must force a cell renderer; mode vocabulary via `Mosaic::fromModeString`; disk cache keyed by `Mosaic::protocol()` | **port the policy** — directly applicable to crush panes/multiplexed output |
| `src/Screen/PlayerScreen.php` (2,759 ln) | Full video UX as TEA Model wrapping `Reel\Player` as inner model: `productionFactory:294` (mode string → `Mode::tryFrom ?? RendererFactory::autoMode`), `buildPlayerCmd:654` via `Cmd::promise` (ffprobe+spawn off the sync path), `renderBase:602` cell-chrome vs `graphicsChrome:642` — **absolute cursor rows pinned to bottom because candy-core diffs line-by-line and appending newline-chrome to a multi-row blob lands it "on row 3"** (the named bug), scrub previews via `Player::frameAt` (`:253`, `fetchTrickplay:721` failure silently absorbed), `ResizeRebuildMsg`/`ReelTickMsg` routing (`:518`), transcode fallback on `PlayerPrepareFailedMsg` | **adopt wholesale as crush video-pane blueprint** |
| `src/Media/PosterLoader.php:62-197` | async posters through `Mosaic::posterAsync` (fetch+render+disk-cache one call, `:77-88`), host allowlist `:103`, `Semaphore` concurrency `:115`, `ImageLayer::digestFor`/`placeTracked`/`release` window-lifecycle discipline `:172-197` | **adopt pattern** for crush image rails |
| `src/Media/TrickplayCache.php:22-60` | 2-tier cache (LRU 512 + `Mosaic\DiskCache`) behind `Semaphore` | adopt for generated-media thumbnails |
| `src/Download/DownloadService.php:63-160` | React `Browser` streaming body→tmp→rename, Content-Disposition filename `:232`, ext-from-content-type `:254` | adopt for model-output file saves |
| `src/Screen/PhotoViewerScreen.php:206-228,326-340` | EXIF side-panel re-render at new width; slideshow tick via epoch-guarded `slideTickCmd:320` | adopt for an image-viewer mode |
| `src/Ui/MetricsOverlay.php` | fps/frame-budget overlay | optional for video pane |

Nothing in phlix decodes media itself — all decode/render delegates to the sugarcraft libs; its `--mode` vocabulary matches `Mosaic::fromModeString` (`halfblock|ansi|quarterblock|ascii|ansi256|truecolor|sixel|kitty|iterm2`). [media]

### 8.6 sugar-crush's EXISTING image pipeline, end-to-end (already shipped) [media]

An image-bearing tool result already paints in the transcript:

1. **Capability probe once**: `ToolResult::$mosaic` static cache (`src/ToolResult.php:49-66`, "probe the terminal once (DA1 + XTWINOPS ~100 ms) and reuse"), exposed by `ToolResult::mosaic()`; `Cli/Bootstrap.php:1390` threads `mosaic: ToolResult::mosaic()` into `Chat` (param docblock `Chat.php:697-707`; accessor `Chat::mosaic():9663`; cloned through `withXXX` at `:10086`).
2. **Carrier**: `ToolResult` readonly fields `imageBytes/imagePath/imageProtocol` (`:113-115`; protocol values `kitty|sixel|iterm2|halfblock|quarterblock|chafa`).
3. **Per-frame render**: `Renderer::renderView` mints a fresh `ImageLayer` (`src/Renderer.php:1682`) threaded through `renderHistory:4058` → `renderToolResults:4632` → `renderToolPicture:4845` / `renderToolImage:4923`.
4. **Encode + memoize**: `$mosaic->render(ImageSource::fromString($bytes), $cols, $rows)` under LRU `self::$imageCache` keyed `xxh3(bytes):COLSxROWS:protocol` (`:4934-4951`, MRU re-insert eviction `:4940-4944`); decode failures cost one transcript line, never a throw (`:4910-4912` docblock). Height from `@getimagesizefromstring` header only, `/2` cell-aspect, clamped to pane budget (`imageRows():4971-4985`); width clamped to pane (`:4928-4932`).
5. **Inline vs blob**: `$mosaic->isInline() ? body : $images->place($body, $cols, $rows)` (`:4965`) — cell renderers go in the diff-able frame; pixel protocols become an `ImageOverlay::markerBlock` region + out-of-band bytes parked on the layer.
6. **Handoff**: `new View($frame, images: $images->placements())` (`:2122`) → candy-core `Program.php:1353-1377` `resolve/signature/paint/coveredRows`.
7. `Doctor.php:11,144` surfaces the probed protocol; **no built-in tool currently *produces* `imageBytes`** except screenshot-style capture paths (grep of `Tools/BuiltIn/Read.php` shows **no image-file reading support**).
8. **Video**: zero references to sugar-reel anywhere in `sugar-crush/src` — absent from its `composer.json` require block.

### 8.7 Media gaps → where new code should live [media]

1. **User-switchable display mode (crush setting)** — `ToolResult::mosaic()` always `Mosaic::auto()`. Add e.g. `media.render_mode` (values = `Mosaic::fromModeString` vocabulary, `Mosaic.php:598`); construct the forced Mosaic in `Cli/Bootstrap.php` beside `:1390`. Settings-roster drift tests (README/EnvRoster) will require doc updates in the same PR.
2. **Video playback in crush** — add `sugarcraft/sugar-reel` to crush requires; host `Reel\Player` as an inner model exactly per phlix `PlayerScreen` (blueprint above; the `graphicsChrome` bottom-pin and `Cmd::promise` build are load-bearing). Alternative minimal path already in-tree: `Mosaic::AnimationDriver` for GIF/APNG without ffmpeg.
3. **img2img / i2v source upload** — decode/resize/crop exist (`ImageSource::fromFile:138`, `crop:1797`, `resize:1838`); **missing** is (a) a small encoder helper (RGBA/PNG bytes out for multipart or base64 `image` fields — natural home: new `src/Encoder.php` in candy-mosaic, or ext-gd `imagepng` directly in crush), and (b) provider request-shape work in `sugar-crush/src/Providers/` for `/v1/images/generations|edits` (multipart) — pure HTTP, no rendering dependency.
4. **Read tool image support** — `Tools/BuiltIn/Read.php` ignores image files; wiring `ToolResult::withImage(...)` there instantly reuses the whole pipeline above (it already probes once, caches encodes, and handles both frame shapes).
5. **Detection hardening (optional)** — XTGETTCAP absent (§8.2).
6. **Constraints** — ffmpeg is a hard runtime dep for MP4 (present, 6.1.1); WebP depends on gd build (true here, but an `ext-gd` variant may lack it — `ImageSource` already sniffs webp at `:289` and would throw a caught decode error elsewhere; chafa is the ideal fallback encoder for exotic formats and IS installed). crush tests run headless: keep any new probes behind the existing `isInteractiveTty` early-exits so piped CI never blocks 100 ms.

### 8.8 Video-playback architecture options found in-tree [media]

1. **sugar-reel Player (recommended)**: ffmpeg → rgb24 (cell modes) or PNG stream (graphics modes) → `RgbFrame` → Mode renderer → TEA tick loop with `frameBudgetMs` pacing, seek via decoder rebuild (`-ss` input seek), network sources with auth-header passthrough, ffplay/mpv audio side-channel.
2. **candy-mosaic `AnimationDriver` + `Animation` + `ApngDecoder`/`fromAnimatedFile`**: in-process animated stills (GIF/APNG) with `FrameTickMsg` — no ffmpeg, good for short loops/preview cards in crush panes.
3. **candy-flip**: standalone GIF viewer app (gifterm port) — superseded by (2) for embedding, useful as reference for luma ramps.
4. mplayer is nowhere; mpv is used only as an audio sink (`AudioPlayer`) — no mpv IPC exists in-tree (a crush-specific option if ever wanted).

### 8.9 Environment tooling (probe box, 2026-10-07) [media]

`ffmpeg 6.1.1` ✓, `ffprobe` ✓, `ffplay` ✓, `mpv` ✓, `chafa` ✓, ImageMagick `convert` ✓, PHP 8.3.6 with `gd` (PNG+JPEG+**WebP** support all true per `gd_info()`), `imagick`, `FFI`, `exif`, `curl`. **No gifsicle.** (CI/dev boxes without ffmpeg still run: cell-mode image rendering, GIF/APNG animation, and the chafa fallback need no ffmpeg; only MP4/WEBM decode requires it.)

---

## 9. sugar-crush integration seams

Source: [arch] Part 2 — every seam the media feature must plug into, with file:line evidence. All paths relative to `/home/sites/sugarcraft/sugar-crush/`. Baseline fact: **no image/video endpoint exists anywhere today** (tree grep for `images/generations|txt2img|image_gen` = 0 hits); embeddings is the only non-chat capability. [arch]

### 9.1 Providers layer (HTTP inference clients) [arch §2.1]

| Fact | Evidence |
|---|---|
| Provider contract = capability flags + `complete`/`completeStream` + **`embeddings()`** (the existing second-capability precedent) | `src/Providers/ProviderInterface.php:11-18` (`supportsStreaming/FunctionCalling/Vision/JsonSchema`), `:72` `embeddings(EmbeddingsRequest): EmbeddingsResponse` |
| Dedicated DTO pair per capability | `src/Providers/EmbeddingsRequest.php`, `EmbeddingsResponse.php` |
| HTTP transport = **Guzzle** (not ReactPHP streams, not proc_open curl), configured once in a trait; curl + StreamHandler dual-path, connect-bound only, **deliberately NO total timeout** on completion paths (docblock law) | `src/Providers/Concerns/HttpClientDefaults.php` (header docblock); `CompleteRequest.php:144-156` (blocking-`curl_exec` note + heartbeat options); `ClaudeCodeProvider` is the one proc_open child process |
| Sglang provider anatomy: ctor `(baseUrl, apiKey, …)`, factory `openAiCompatible()`, `complete()` `:1143`, `completeStream()` `:1181` (SSE gen), `embeddings()` `:1612`, server-info discovery wired in ctor `:711` | `src/Providers/SglangProvider.php:34,595-598,678,711,1143,1181,1612` |
| **Live server-capability discovery already exists** — GETs root `/model_info` + `/server_info`, concurrent, 3.0 s total timeout (the sanctioned metadata exemption), reads `served_model_name`, `context_length`, `has_image_understanding` | `src/Providers/SglangServerInfo.php` (`DISCOVERY_TIMEOUT_SECONDS:63`, `MODEL_INFO_PATH:67`, `rootUrl()` strips `/v1`) |
| Provider registry: types `['openai','anthropic','claude-code','sglang','bedrock','vertex','custom']`; per-type config-schema table — `sglang` required `[baseUrl, model]`, optional `[apiKey, toolCallParser, discoverServerInfo, supportsVision, modelPrices, contextWindow, fallbackModels]`; `custom` required `[name, baseUrl, model]` + capability flags — **this table is where a `mediaKinds: [image|video]`-style optional key slots in** | `src/Providers/ProviderFactory.php:403`, schema table `:85-120`; `${VAR:-default}` env interpolation `:380-397` |
| Named extra backends come from the project `'providers'` map (`config.dev.json`) — "this backend supports image gen: flux2dev" is declarable here with zero code | `ProviderFactory.php:428` `projectProviderConfig()`, `defaultConfig()` fallback |
| Per-model metadata (context window / price) cached + background-refreshed — pattern for per-model media capability metadata | `src/Providers/ModelMetadata.php:175` `cachedAt(…, $background)` |
| Non-chat capability consumer pattern (opt-in, lazy) | `src/Backend/EngineBackend.php:3526-3542` embeddings for memory recall |

### 9.2 Tool layer [arch §2.2]

| Fact | Evidence |
|---|---|
| NOTE: root `src/ToolRegistry.php` is a superseded legacy shim (5 dummy tools, "zero production callers"); do not extend it | `src/ToolRegistry.php:16-22` docblock |
| Real contract: `name()/description()/inputSchema()/execute()` | `src/Tools/Tool.php:21-27`; complex-param example `src/Tools/BuiltIn/Bash.php:351`, `WebFetch.php:266` |
| Declaration = attribute on the class; discovery = reflection scan; docs generated from it | `#[BuiltInTool(name:'WebFetch', permission: ToolPermissionClass::Ask, position:7)]` at `src/Tools/BuiltIn/WebFetch.php:74`; scan `src/Tools/Catalog/ToolCatalog.php:222`; `src/Tools/Catalog/{BuiltInTool,CatalogEntry,ToolBuildContext,ToolDocGenerator}.php` |
| Permission classes: `Read / Write / Ask / NoAsk`; gate consults catalog classes + rules | `src/Tools/Catalog/ToolPermissionClass.php:29-32`; `src/Permissions/PermissionGate.php:84` class, `decide()` `:587`, Read-class shortcut `:1414`, Write-class route `:1450`; network egress target analysis `src/Permissions/FetchTarget.php` (WebFetch SSRF work); permission asks relay through the TEA loop (`src/PermissionRequestMsg.php`, `Tools/RelaysPermissionAsks.php`) |
| Long-running work executes in a **forked child**; results/events return over a socket pair; `pcntl_fork` at EngineBackend `:3564-3590`, pair at `:4219-4225`; event codec union `encodeEvent():5057` / `decodeEvent():5135` (`ToolStarted\|ToolFinished\|SpendCapBreached\|SubAgentActivity\|ContextLedgerChanged`) | `src/Backend/EngineBackend.php` |
| TUI progress: shared queue + payload-free `ToolEventPumpMsg` wake-ups, drained by `Chat::pumpLiveToolEvents()` `:5502` (live rows) and `applyBackendToolEvent()` `:5149` (turn-end batch) — this is the "progressive tool rows" pipeline | `src/ToolEventPumpMsg.php` docblock; `src/Chat.php:5149,5502`; `src/Events/*.php` (12 classes) |
| Turn notices side-channel (launch/aggregate stderr→transcript) | `src/Diagnostics/RuntimeNoticeSink.php:173` |
| **Image results already ride ToolResult**: candy-mosaic protocol probed once, `imageProtocol` stamped onto results | `src/ToolResult.php:9,51,66,152-186`; `src/Message.php:75` |
| Idle ceiling that matters for generation: EngineBackend's 120 s `COMPLETE_TIMEOUT_SECONDS` is an **idle** bound re-armed per streamed frame | `HttpClientDefaults.php` docblock (interactive path description) |

### 9.3 Slash-command layer [arch §2.3]

| Fact | Evidence |
|---|---|
| Registry rows drive slash menu + Ctrl+P palette; behaviour lives in `Chat::submit()` dispatch chain; file-based custom commands merged by loader | `src/Commands/CommandRegistry.php:36` (`all()` `:89`), `CommandSpec.php:18-36` docblock, `CommandLoader.php` |
| Newest worked example of adding a command (registry row + Chat arm + README roster IN-STEP, drift-tested) | `src/Commands/NoticesCommand.php`; arm `Chat::handleNoticesCommand()` `src/Chat.php:12926`; dispatch entry `submit()` `:10685`; command-name allowlist needing a judgment for each new command `READ_ONLY_COMMANDS` `:11486-11491` (contains `'model'`) |
| `/model` selection axis: per-provider `models` map is the persisted choice; palette "Switch model" routes via `backendFor()` | `src/Cli/Bootstrap.php:4005-4016` docblock |
| Docs are pinned by tests: adding commands/env/settings without matching README/docs edits goes red | `tests/Commands/KeyBindingDriftTest.php`, `tests/Config/ReadmeRosterDriftTest.php`, `EnvRosterDriftTest.php` (AGENTS.md "Docs are pinned by tests") |

`/generate`, `/image`, `/video` = three `CommandSpec` rows + `handleGenerateCommand()`-style arms in Chat + README/COMMANDS.md rows in the same commit. Each also needs the `READ_ONLY_COMMANDS` judgment (they are NOT read-only — they open a modal / spend money). [arch]

### 9.4 TUI / Model / overlay plumbing [arch §2.4]

| Fact | Evidence |
|---|---|
| TEA contract on Chat | `src/Chat.php` `init()` `:1679`, `update()` `:1764`, `view()` `:7486`, `subscriptions()` `:17919` |
| Modal-overlay precedent = command palette: immutable state object + dedicated key router + render branch, clipping via `clipOverlayToCols` | `src/Palette/PaletteState.php:28` (`withQuery:49 … withModelsOf:86`), `src/Palette/PaletteAction.php`, `Chat::handlePaletteKey()` `src/Chat.php:16796`, `Renderer::renderPalette()` |
| Blocking generation form = same shape: a `MediaFormState` on Chat, routed in `update()` while `mode===media`, painted from `view()`; App-level Msg/Cmd vocabulary in `src/App/` (`Msg.php`, `Cmd.php`, `CallToolCmd.php`, `DockPaneMsg` …) | `src/App/` dir |
| Image painting: candy-core `ImageOverlay` U+E002+id marker arena is the sanctioned paint channel; zone sentinels U+E000/E001 disjoint by construction; markers survive via `Sanitize::untrustedForMarkedFrames()` | `src/Renderer.php:1518-1544,1596,3344,3611`; `src/Tui/Components/ChatPane.php:87`; candy-core `ImageOverlay`/`Sanitize` (a32c4faae/cdd4f36fd era) |
| Image INGRESS already exists (paste) — img2img/edits can reuse it | `src/ClipboardImagePastedMsg.php`, `src/AttachmentType.php:12-13` (`File`, `Image`) |
| candy-mosaic is a declared dependency and the terminal graphics capability prober | `composer.json:55`, `ToolResult::probeMosaic()` `:152` |

(Hosting-shape decision — Shape A SettingsEditor-modal vs Shape B Veil-overlay — is settled in §7.4; both precedents coexist and the arch report's palette shape and the forms report's SettingsEditor shape are the same seam family: state slot + key gate + wheel + zone routing + render branch.)

### 9.5 Server / ACP — web-UI protocol surface (phase-2 shape) [arch §2.5]

| Fact | Evidence |
|---|---|
| `sugarcrush serve` = one ReactPHP loop serving HTTP + WebSocket; engine turns run in forked children, NOT on the loop thread | `src/Server/Server.php:1-35` (imports React\Http, Ratchet frames; docblock "one HTTP + WebSocket listener on the SAME ReactPHP loop") |
| Transports/auth: WS upgrade+outbox; HTTP router, static files, origin/address guards, bearer auth | `src/Server/Ws/{Upgrade,Connection,MessageHandler,Outbox,ProtocolPendingHandler}.php`; `src/Server/Http/{Router,ApiController,StaticFiles,AuthMiddleware,HostAndOriginGuard,ClientAddressGuard}.php`; `src/Server/Auth/` |
| RPC core: JSON-RPC + method registry + per-domain method classes (`TurnMethods`, `CommandMethods`, `SettingsMethods`, `MemoryMethods`, `PermissionMethods`, …) — a `MediaMethods.php` drops in beside them | `src/Protocol/{JsonRpc,MethodRegistry:11,Dispatcher,MethodSpec,Schema}/`, `src/Protocol/Methods/*.php` |
| Event fan-out to web clients: typed `EventEnvelope{sessionId,seq,type,ts,turnId,durable,data}` → `notification()`; `SessionFeed` log-then-broadcast with per-client outboxes | `src/Protocol/EventEnvelope.php:30-36,123`; `src/Protocol/SessionFeed.php:58,203,660-665` |
| Session hosting shared by TUI/serve/ACP | `src/Host/{SessionHost,SessionHub,TurnRunner,TurnController,TurnTicket,EventLog,TranscriptStore}.php`; ACP: `src/Acp/{AcpServer,AcpSession,AcpUpdateMapper,AcpPermissionBridge}.php` |
| Server self-advertisement record (`server.json`: pid/protocol{min,max}/url) — format precedent for advertising media capability to `sugar-crush-web` | `src/Server/DiscoveryFile.php:8-18` |
| Admin-declared "this backend supports image gen" plumbing: per-provider settings already flow through `SettingsMethods` + `LAYERED_KEYS` trust tiers | §9.6 |

### 9.6 Config / settings [arch §2.6]

| Fact | Evidence |
|---|---|
| Layered (user→project→flag) trusted-key registry, generated block from `SettingsSchema`; contains `provider`, `models` (per-provider model choice, decision D9), `modelPrices`, `maxCostUsd`, `parallelToolDeadlineSeconds`, compaction/contextPruning namespaces … — new media keys (`media.baseUrl`, `media.presets`, `providers.<name>.mediaKinds`) are added to this roster + schema + docs in-step | `src/Config/LayeredSettings.php:474-504+` (`settings:layered-keys:begin` generated block); `src/Config/Settings/` |
| Env-var roster + trust-key docs are drift-tested | `tests/Config/{EnvRosterDriftTest,TrustKeyDocumentationDriftTest,ConfigWriteProducerDocumentationDriftTest}.php` |
| Provider base URL/key per type live in the factory schema table + `${ENV}` interpolation | `ProviderFactory.php:85-120,380-397` |
| i18n user strings via `Lang::t` (widgets' door messages have no-Lang precedent — candy-forms mask) | `src/Lang.php` |

### 9.7 Recommended `src/Media/` module layout [arch]

```
src/Media/
  Capability/
    MediaKind.php            # enum: Image, Video
    EndpointFamily.php       # enum: OpenAiImages, SglangDiffusion, ComfyUi, Custom
    MediaCapability.php      # immutable descriptor: kind, family, endpoint, size/steps/duration bounds
    CapabilityDiscoverer.php # discovery GETs (3.0 s total timeout, HttpClientDefaults exemption
                             # pattern from SglangServerInfo:63), merge: config-declares > server-probes > none
  Generators/
    GeneratorInterface.php   # generate(MediaRequest): MediaResponse  (+ streamProgress(): Generator)
    MediaRequest.php / MediaResponse.php   # DTO pair, mirroring EmbeddingsRequest/Response
    OpenAiImagesGenerator.php   # POST /v1/images/generations (+ edits), b64_json url decode
    SglangDiffusionGenerator.php
    ComfyUiGenerator.php        # /prompt + /history poll + /view fetch (poll = progress events)
  Preset.php / PresetRegistry.php   # named presets ("flux2dev-1024", "wan-5s-720p"), with* fluent
  MediaJob.php               # state machine record (queued|running|done|failed, artifact path, cost)
  Events/  (fold into src/Events): MediaProgress.php, MediaCompleted.php  # extend encodeEvent/decodeEvent unions @EngineBackend:5057/:5135
Wire points (outside the module):
  src/Tools/BuiltIn/{GenerateImage,GenerateVideo}.php  #[BuiltInTool(permission: Ask, position: n)] + inputSchema;
      heavy HTTP executed in the forked tool-child (EngineBackend pcntl path), progress via the $onEvent queue
      → ToolEventPumpMsg → pumpLiveToolEvents; result carries the file path + imageProtocol via ToolResult.
  src/Commands/ + Chat.php submit() arms — /generate /image /video; READ_ONLY_COMMANDS judgment.
  src/Chat.php — MediaFormState modal (palette pattern) for interactive generation.
  src/Protocol/Methods/MediaMethods.php + EventType/EventEnvelope arms — web parity.
  src/Config/LayeredSettings LAYERED_KEYS + SettingsSchema + docs/SETTINGS.md (+ README roster IN-STEP).
  composer: no new dep needed for images — Guzzle already the provider transport; candy-mosaic already
      paints results. (Video: + sugarcraft/sugar-reel require per §8.7-2, path-repo-closure skill.)
```

The `MediaRequest`/`MediaResponse` DTO pair mirrors the A1111 lesson of §4.1: **one param registry, many projections** — the same field set renders into the TUI form (§7.7), serializes into the provider request (§4.2-4.3 field tables), persists into the PNG `parameters` chunk (§3.5), round-trips from pasted infotext (§3.6), and crosses the RPC wire unchanged (§9.5).

---

## 10. Risks, constraints, drift-guard obligations, test strategy

### 10.1 Risks (arch report, verbatim severity order) [arch]

1. **Event-loop blocking** — provider HTTP is synchronous Guzzle; in `serve`/ACP mode a generation POST issued on the loop thread freezes every client. Must run inside the existing forked tool-child channel (EngineBackend `:3564` + socket-pair `:4219`), never inline. (`completeAsyncBlocking()` fallback without pcntl is the degraded path — a 5-min video there blocks the UI outright.)
2. **Idle-timeout ceiling** — the 120 s `COMPLETE_TIMEOUT_SECONDS` idle bound kills silent children; ComfyUI polling / non-streaming diffusion calls must emit progress frames (heartbeat options, `CompleteRequest.php:144-156`) or get a media-specific ceiling; **blanket wall-clock timeouts are banned campaign-wide (E646 ruling)**, and video gen legitimately runs minutes.
3. **Permission model** — generation = network egress to operator-configured hosts + file writes of artifacts: use `ToolPermissionClass::Ask` floor, `FetchTarget` SSRF classification for URLs, and the PathJail for output dirs; bypass-mode floors (deny rules) still apply.
4. **Web-mode parity** — the TUI overlay won't exist in sugar-crush-web; the job/event model must be the single source of truth (Protocol methods + durable EventEnvelope arms), TUI form a thin client; `EventEnvelope.durable` + `SessionFeed` replay gives reconnect semantics for free.
5. **Cost/usage accounting** — chat billing machinery (`modelPrices`, `Usage` E17 fold, spend caps) has no per-image/per-second notion; unpriced must stay honest null (`null IS THE ANSWER` law, `ProviderInterface.php:35-45`), and a generated 5-min video can silently blow `maxCostUsd` expectations unless media spend is surfaced separately.
6. **Drift guards** — every new tool/command/env/settings key must land with README/docs + roster updates in the same commit (`ReadmeRosterDriftTest`, `EnvRosterDriftTest`, `KeyBindingDriftTest`, DocFigure arms), and new test files need `scripts/parallel-tests-durations.tsv` rows + suite-figure re-pin at closeout.
7. **Discovery availability** — the 30001 server is down today; config-declared capability is the authority, live probe an override — never gate the feature on the probe (fail-open), mirroring `SglangServerInfo`'s "server's own answer comes first, transcription stays as fallback" design.

### 10.2 House constraints checklist (forms report §5) [forms]

1. `declare(strict_types=1)`, PSR-12/4, `final` public classes; bare accessors except the `Field` interface's legacy `getTitle/getError/get*` (keep interface names; new crush code uses bare getters).
2. Immutable + fluent `with*` everywhere; models return `[self, ?Cmd/Closure]` from `update()`; statics for cross-call trackers only when a gesture spans frames (`App::$chromeClickTracker`, `Chat::clickTracker()` `:7604-7614` — docblock `:2236-2247` explains the immutability reason).
3. Zone ids: prefix-namespace every clickable row (`genform:tab:`, `genform:row:`); marks are zero-width U+E000/U+E001 sentinel pairs from `SugarCraft\Mouse\Mark` — they must be lifted/restored around Veil overlays (`Renderer.php:2040-2069`) and image markers now live at U+E002+ (disjoint by construction, candy-core `Sanitize` reservation).
4. Width invariant: every produced row ≤ cols; overlays clip via `clipRowsToCols` BEFORE composite; `SHELL_CHROME_COLS = 0` is now public and shared by all overlay geometry producers (`Renderer.php:318-322`, E744 WS4).
5. Docs drift = CI red (§7.6). i18n: every user string through `Lang::t`, en.php keys first, params `{name}` style.
6. **No I/O in view-models** — actions return enums for the shell (`DirectoryPickerAction`, SettingsEditor CONFIRM_* constants; canonical "modal returns an action enum, host executes I/O" pattern at `Chat.php:17603-17628`); the shell (`App`) owns writer/executor (`confirmSettingsSave()` shape).
7. Suite figures: any added test touches `suite-figure.json`, `parallel-tests-durations.tsv`, README headline (merge-time re-pin per AGENTS.md).
8. Tests run: `cd sugar-crush && vendor/bin/phpunit` (linked mode; check `php scripts/refresh-deps.php --status`); suites that arm timers need `LoopPin::pinStableClock()` in bootstrap — crush's `tests/bootstrap.php` already handles its loop shape.
9. candy-forms CALIBER_LEARNINGS: golden ANSI via `SugarCraft\Testing\Snapshot\Assertions` + `UPDATE_GOLDENS=1`; don't re-implement fuzzy (use `SugarCraft\Fuzzy\SmithWatermanMatcher`); VimKeyHandler is shared by 4 libs — extend `VimAction`+`VimKeyHandler` only.
10. Façade law: depend on `candy-forms` canonical; sugar-bits/sugar-prompt are alias layers; ship ONE `AliasesTest` if you ever add façade coverage.

Plus the **async suggestion law** (candy-forms CALIBER_LEARNINGS via [forms]): store the `CancellationSource` on the model, `previous?->cancel()` before re-scheduling, accept the shared `LoopInterface`, never construct one; fetches go through candy-core `WorkerPool`. And `hydrate(array)` (`Form.php:624`) is the load-last-used-params entry point; `values()` (`:554`) the save entry point. Rendering-stack constraints (ffmpeg dep, WebP gd-build, headless probes behind `isInteractiveTty`) are itemized at §8.7-6.

### 10.3 Test strategy [forms §4 G-7 + AGENTS.md tea-snapshot taxonomy]

- **Four project test modes** apply directly to the generation feature: **snapshot byte** (`GenForm::view()` → raw SGR golden, `UPDATE_GOLDENS=1`), **behaviour** (`update(KeyMsg)` → `[Model, ?Action]`), **coercion** (every Slider clamps at A1111 ranges — §2 tables are the edge-case spec: steps 1/150, cfg 1/30, denoise 0/1, padding 0/256, mask_blur −64/75…), **cell-grid** where needed (`SugarCraft\Vt\Terminal`).
- **File taxonomy to clone**: `tests/Tui/Settings/SettingsEditor*Test.php` — keys/render/narrow/polish split ⇒ `tests/Tui/GenForm/{GenFormKeysTest, GenFormRenderTest, GenFormNarrowTest, GenFormSaveFlowTest}.php`. [forms §6]
- **G-7 decision**: adding `candy-testing`/`candy-vt` to crush require-dev enables `ProgramSimulator`/`ScriptedInput` end-to-end driving of the modal; without it, follow the existing hand-rolled crush test style (both viable; recommendation: adopt the dev-deps, the modal+overlay interaction is exactly what ProgramSimulator was built for).
- Stream-write tests: slice deltas with `ftell`/`fseek`/`stream_get_contents`, never `ftruncate;rewind;` (canonical `candy-core/tests/RendererTest.php`, AGENTS.md). Image-pipeline tests must exercise BOTH frame shapes: inline (cell renderer) and overlay-blob (graphics protocol, `isInline()==false` → `ImageLayer::place`) — §8.6-5.
- Any new test file → `scripts/parallel-tests-durations.tsv` row (unlisted files run in zero shards) + suite-figure re-pin at closeout (AGENTS.md gotcha; current era figure 21,196T/406,402A as of the last crush re-pin).
- Detection-path tests stay hermetic: `Detect::setProbeStdin:105`/`reset:82` seams; piped CI never blocks on the 100 ms DA1 probe (`isInteractiveTty` early-exits).

---

## 11. Open questions, phase plan, source-report appendix

### 11.1 Open questions (need operator/orchestrator ruling)

| # | Question | Context |
|---|---|---|
| Q-1 | **What does port 30001 actually expose once back up?** SGLang-Diffusion OpenAI-shape (`/v1/images/generations`), ComfyUI (`/prompt`/`/history`/`/view`/`/object_info`), or an A1111-fork REST (`/sdapi/v1/*`)? Re-probe per §1.3 checklist before picking the primary `EndpointFamily`. | [arch §1a/§1c] |
| Q-2 | **Progressive/live-preview equivalent** — A1111's per-N-step latent previews (§4.7) need a progress channel the wire can carry. Does the chosen backend emit interim frames (ComfyUI history previews / SGLang stream events), or do we synthesize progress-only (no interim image) in v1? | [repo §E.3] |
| Q-3 | **Per-media pricing** — `modelPrices` is token-shaped; images bill per-image/per-second (Flux ~$0.003–0.055/img tiers, video per-sec). Extend `Usage`/price schema or keep media spend honest-null (§10.1-5)? | [arch] |
| Q-4 | **LoRA stack + inpaint-model selection UX** — §6.1's repeatable list {model, strength, start/end} has no candy-forms widget; MultiSelect approximates; a bespoke repeatable sub-form (Form-of-groups) is new ground — build in GenForm or upstream into candy-forms? | [forms §1] |
| Q-5 | **Mask authoring** — inpaint (§2.7) needs a mask image. crush has FilePicker + clipboard-image ingress (`ClipboardImagePastedMsg`) but **no draw canvas**. Options: external-edit round-trip (document), minimal brush overlay (big new widget), or first-phase = mask-file-only. | [arch §2.4] [repo §A.2] |
| Q-6 | **Read-tool image support** (§8.7-4) — wiring `ToolResult::withImage()` into `Tools/BuiltIn/Read.php` is a one-liner-ish that unlocks "show me the generated file" for free. In phase 1 scope? | [media] |
| Q-7 | **`media.render_mode` setting vocabulary** — adopt `fromModeString`'s `halfblock|ansi|quarterblock|ascii|ansi256|truecolor|sixel|kitty|iterm2` verbatim plus `auto`? Env fallback `SUGARCRUSH_MEDIA_RENDER_MODE`? (EnvRoster + ReadmeRoster drift updates in-step.) | [media §8.7-1] |
| Q-8 | **XTGETTCAP hardening** (§8.2) — deferred unless a target terminal needs it. | [media] |
| Q-9 | **ui-config.json analog** — A1111 lets users retune any widget's range/default with no code (§5.5). Worth a crush `ui-config` sidecar for the gen form? (Cheap, high-leverage, but expands settings surface.) | [wiki §6.1] |
| Q-10 | **Flux guidance semantics on the wire** — Flux dev's guidance-distilled embedding vs true CFG (§6.1): what does the skynet2 deployment actually accept (`guidance` field? `cfg_scale` ignored?) — resolve with Q-1 re-probe. | [repo §D.1] |
| Q-11 | **Video deliverable format** — mp4 file + sugar-reel playback pane, with PNG-info-style sidecar (`<name>.json` or mkv comment) carrying the generation params? Per-frame dir only as fallback? | [repo §D.2] [wiki §11] |
| Q-12 | **candy-testing dev-dep ruling** (G-7) — adopt for ProgramSimulator-driven modal tests? Recommendation: yes. | [forms §4] |

### 11.2 Suggested phase plan

**Console phase 1 — text-to-image, display + form skeleton.**
1. `media.render_mode` setting + forced-Mosaic in `Cli/Bootstrap.php` (§8.7-1) — user-switchable iTerm2/kitty/sixel/truecolor-blocks/quarter-block/ascii immediately.
2. `src/Media/` module: `MediaKind`/`EndpointFamily`/`MediaCapability`/`CapabilityDiscoverer` + `MediaRequest`/`MediaResponse` + `OpenAiImagesGenerator` (config-declared capability; fail-open probe §10.1-7).
3. Providers: `mediaKinds` optional key in `ProviderFactory` schema table + project `providers` map entry for flux2dev (§9.1).
4. `GenerateImage` BuiltInTool (Ask permission, forked-child HTTP, progress via `$onEvent`→`ToolEventPumpMsg`, result through the existing `ToolResult::imageBytes` pipeline §8.6).
5. GenForm modal Shape A (§7.4/§7.7): Params group first (prompt/negative/model/steps/cfg/seed/size + sliders per §2.1-2.2 ranges), `hydrate`/`values` + preset save/load (`PresetRegistry`).
6. `/generate` command + palette row + `READ_ONLY_COMMANDS` judgment; i18n `tui.genform.*`; PNG `parameters` chunk byte-compatible per §3.5 written on save.
7. Tests §10.3 + drift rosters in-step + suite re-pin.

**Console phase 1.5 — image-to-image + inpaint.** Image ingress (paste/FilePicker), encoder helper (§8.7-3a), `/v1/images/edits`-or-`img2img` multipart plumbing, img2img/inpaint field groups (§2.6-2.7), mask strategy per Q-5, Flux-Fill/Qwen-Edit model-selection surface (§6.3), Read-tool wiring if Q-6 yes.

**Console phase 2 — video.** `sugarcraft/sugar-reel` require (path-repo-closure skill), video pane hosting `Reel\Player` per phlix `PlayerScreen` blueprint (§8.5) — graphicsChrome bottom-pin + `Cmd::promise` build are load-bearing — t2v/i2v fields (§6.2: frames ladder snapping, fps, first-frame slot, expert boundary, dual CFG, shift), ffplay/mpv audio, MediaJob state machine + sidecar metadata (Q-11).

**Web phase (sugar-crush-web reuse).** `MediaMethods.php` JSON-RPC domain + `MediaProgress`/`MediaCompleted` EventEnvelope arms (durable, SessionFeed replay) + `DiscoveryFile` media advertisement; the browser renders `<img>`/`<video>` natively — the TUI mode ladder is console-only, so the event `data` must carry raw artifact refs (path/URL + infotext dict), never terminal-encoded bytes. Same job/event model is the single source of truth in both shells (§10.1-4).

### 11.3 Source-report appendix

| Tag | Report | Path | Lines |
|---|---|---|---|
| [repo] | A1111 source deep-dive (~90 controls, API, script hooks, beyond-A1111 video/Flux supplement, progressive results) | `/tmp/opencode/crush-media/a1111-repo/REPORT.md` | 575 |
| [wiki] | A1111 wiki crawl (semantics, prompt syntax, PNG info format, pluggability, ecosystem) | `/tmp/opencode/crush-media/a1111-wiki/REPORT.md` | 434 |
| [forms] | SugarCraft form/popup widget inventory + gaps + integration mechanics | `/tmp/opencode/crush-media/sc-forms/REPORT.md` | 302 |
| [media] | Image/video terminal rendering stack (candy-mosaic/sugar-reel), phlix-console-client gems, env tooling | `/tmp/opencode/crush-media/sc-media/REPORT.md` | 221 |
| [arch] | skynet2 server probe findings + sugar-crush provider/tool/command/TUI/web integration seams + risks | `/tmp/opencode/crush-media/crush-arch/REPORT.md` | 197 |

**Verification artifacts used for the ⚖️ conflict rulings:** live A1111 clone `/home/sites/sdg-assets/stable-diffusion-webui` @ `82a973c` — read-only greps of `modules/ui.py:702`, `modules/processing.py:1567/1696-1753`, `modules/shared_options.py:399/404`, `javascript/` directory listing + keydown-handler grep (no `toprow.js`, no Ctrl+Enter submit handler). Wiki page list: Features, Prompting-Techniques(-ish sampling/scheduling pages), Parameters/Seed, png-info, Script-and-Extension-Writers, API, Settings, Extensions index pages, Command-Line-Arguments-and-Settings, Troubleshooting-and-FAQ, Seed-breaking-changes (as crawled — inventory in the [wiki] report §1 tables).

**About this file:** consolidation artifact for the sugar-crush media-generation project brief; created 2026-10-07; untracked (no git operations performed); supersedes nothing — the five input reports remain authoritative for anything trimmed in de-duplication. Conflicts found between inputs are C-1…C-4 (§0.3), each adjudicated against the pinned A1111 source.



---

# Appendix G — Sibling report (mystage/crush_media.md, embedded verbatim 2026-10-08; original file deleted). All "mystage §N" citations resolve below; the 8 unique facts are ALSO already folded into steps per the header index.

# crush_media.md — Image & Video Generation for sugar-crush

**Date:** 2026-10-07 · **Status:** research/design report, no code written yet
**Sources:** five research passes — (1) AUTOMATIC1111/stable-diffusion-webui source deep-dive (`/tmp/stable-diffusion-webui` @ `82a973c`, v1.10-era master), (2) full A1111 wiki crawl (all 34 pages), (3) SugarCraft form/popup library survey, (4) SugarCraft image/video library survey + phlix-console-client, (5) sugar-crush + sugar-crush-web integration map.

---

## 0. Executive summary

- **Goal:** first-class image + video generation in sugar-crush when the configured backend supports it: a rich A1111-style popup form (sliders, dropdowns, radios, checkboxes, textareas for prompt/negative-prompt/etc.), text-to-image, image-to-image, inpaint, image-to-video, text-to-video; in-terminal display at the best available fidelity (Kitty / iTerm2 / Sixel / Chafa / half-block / quarter-block / ASCII-truecolor / ANSI256 / mono) with a user-switchable render mode; video playback in-terminal; console first, with the server/WebSocket mode shaped so sugar-crush-web can reuse everything in phase 2.
- **The stack already exists.** `candy-mosaic` is a complete image→terminal renderer with a 7-backend ladder and a mode-string parser (`Mosaic::fromModeString()`); `sugar-reel` is a complete terminal video player (ffmpeg decode pipe, 8 render modes, live `m`-key mode cycling, audio, subtitles); `sugar-crush` **already renders `ToolResult::$imageBytes` through mosaic** (`Renderer::renderToolImage()`), and already depends on `candy-forms`, `sugar-veil`, `candy-mouse`, `candy-focus`, `candy-mosaic`, `sugar-toast`. phlix-console-client is a worked integration of exactly these libs and supplies the transferable glue patterns.
- **What must be written:** the A1111-compatible client (`SdClient`), the `GenerateImage`/`GenerateVideo` tools, the media popup form (mostly assembled from existing `Forms\Field\*` widgets), two genuinely-new widgets (mouse-draggable slider, bounded number field), a video player surface inside crush (sugar-reel's `Player` cannot paint inline into crush's line-diffed frame — it must go through `ImageLayer`), a render-mode setting, and phase-2 protocol fields so images cross the WebSocket wire (today `tool.finished` drops `imageBytes`).
- **Backend reality check:** the user's server `http://skynet2.interserver.net:30001` (Flux.2 dev currently loaded; also Flux.1 dev, Qwen-Image, LTX-2.5, SD3.5-Large for images; LTX-Video and Wan 2.2 for video) exposes `/model_info` — **that is not a stock A1111 path** (A1111 serves `/sdapi/v1/*`), and stock A1111 does not run Flux/LTX/Wan at all. The client must therefore be dialect-tolerant: probe capability at connect time and speak A1111 where it exists, with a separate namespace for video jobs (§8). From this host the server refused connection (`connect to 173.225.108.102:30001 failed: Connection refused`, both `/model_info` and `/internal/ping`) — likely a firewall on the source IP or the service being down; the live endpoint contract could not be enumerated and must be re-probed from a permitted network before implementation.

---

## 1. A1111 REST API contract (research unit 1 — source deep-dive)

Provenance note: the 2023-era file map (`modules/api.py`, `modules/api_models.py`) was refactored; in the current tree the API lives in `modules/api/api.py` (928 lines) + `modules/api/models.py` (329 lines), and request models are **generated dynamically at import** from the `StableDiffusionProcessing*` constructor signatures. The HTTP surface below is the stable contract both old and new servers expose.

### 1.1 Auth & error shape

- API exists only when the server was started with `--api`. FastAPI `/docs`, `/redoc`, `/openapi.json` are then available (not auth-wrapped).
- With `--api-auth "user:pass"`: HTTP **Basic** on every `/sdapi` route; failure → 401 `Incorrect username or password`, `WWW-Authenticate: Basic realm="sd-api"`. Without it: unauthenticated.
- Every response carries `X-Process-Time`. Errors are JSON `{error, detail, body, errors}` with the exception's `status_code` else 500 — the PHP client must parse this shape on non-2xx.

### 1.2 Endpoint table (verified against this tree)

| Path | Method | Purpose |
|---|---|---|
| `/sdapi/v1/txt2img` | POST | main generation → `{images:[b64…], parameters, info}` |
| `/sdapi/v1/img2img` | POST | init_images required (b64, data-URI, or http URL) |
| `/sdapi/v1/extra-single-image` | POST | Extras single → `{image, html_info}` |
| `/sdapi/v1/extra-batch-images` | POST | Extras batch → `{images:[{data,name}]}` |
| `/sdapi/v1/png-info` | POST | `{image:b64}` → `{info, items, parameters}` |
| `/sdapi/v1/progress` | GET | `{progress 0..1, eta_relative, state, current_image(b64 live preview), textinfo}`; `?skip_current_image=false` for preview frames |
| `/sdapi/v1/interrogate` | POST | `{caption}`; model `clip` (default) or `deepbooru` |
| `/sdapi/v1/interrupt` / `/skip` | POST | global generation state (not per-tab) |
| `/sdapi/v1/options` | GET/POST | read/write every server setting (persists config.json) |
| `/sdapi/v1/cmd-flags` | GET | launch args |
| `/sdapi/v1/samplers` | GET | `{name, aliases[], options{}}` — options declare default scheduler, `second_order`, `brownian_noise`, `uses_ensd`, … |
| `/sdapi/v1/schedulers` | GET | `{name, label, aliases[], default_rho, need_inner_model}` |
| `/sdapi/v1/upscalers` | GET | `{name, model_name, model_path, model_url, scale}` |
| `/sdapi/v1/latent-upscale-modes` | GET | `Latent`, `Latent (antialiased)`, `Latent (bicubic)`, … |
| `/sdapi/v1/sd-models` | GET | checkpoints: `{title, model_name, hash, sha256, filename, config}` |
| `/sdapi/v1/sd-vae` / `/hypernetworks` / `/face-restorers` / `/realesrgan-models` / `/prompt-styles` / `/embeddings` | GET | resource pickers |
| `/sdapi/v1/refresh-checkpoints` / `refresh-vae` / `refresh-embeddings` | POST | rescan |
| `/sdapi/v1/memory` | GET | RAM/VRAM report |
| `/sdapi/v1/unload-checkpoint` / `reload-checkpoint` | POST | |
| `/sdapi/v1/scripts` | GET | `{txt2img:[…], img2img:[…]}` selectable scripts |
| `/sdapi/v1/script-info` | GET | **self-describing knobs**: `{name, is_alwayson, is_img2img, args:[{label,value,minimum,maximum,step,choices}]}` — render these generically in the TUI instead of hardcoding |
| `/sdapi/v1/extensions` | GET | |
| `/sdapi/v1/server-kill` / `-restart` / `-stop` | POST | only with `--api-server-stop` |
| `/internal/ping` | GET | `{}` liveness |

**Legacy paths that do NOT exist** (pre-2023 names; map them in the client): `/sd-metadata.json`, `/internal/info`, `/sdapi/v1/settings`, `/option/{key}`, `/tick`, `/txt2img/progress|skip|interrupt` (per-tab), `/v1/images`, `/v1/embeddings`, `/refresh-checkpoints-models`, `/reload-clips`, `/mem-usage`, `/img2img-grids`. → equivalents in the table above.

### 1.3 txt2img request fields (full)

Base (`StableDiffusionProcessing`): `prompt:str=""`, `negative_prompt:str=""`, `styles:list=None`, `seed:int=-1`, `subseed:int=-1`, `subseed_strength:float=0`, `seed_resize_from_h/w:int=-1`, `sampler_name:str`, `scheduler:str`, `batch_size:int=1`, `n_iter:int=1`, `steps:int=50`, `cfg_scale:float=7.0`, `width:int=512`, `height:int=512`, `restore_faces:bool=None`, `tiling:bool=None`, `eta:float=None`, `denoising_strength:float=None`, `s_min_uncond/s_churn/s_tmax/s_tmin/s_noise:float=None`, `override_settings:dict=None`, `override_settings_restore_afterwards:bool=True`, `refiner_checkpoint:str`, `refiner_switch_at:float`, `token_merging_ratio(_hr):float=0`, `disable_extra_networks:bool=False`.

txt2img extras (hires): `enable_hr:bool=False`, `denoising_strength:float=0.75`, `firstphase_width/height:int=0`, `hr_scale:float=2.0`, `hr_upscaler:str`, `hr_second_pass_steps:int=0`, `hr_resize_x/y:int=0`, `hr_checkpoint_name`, `hr_sampler_name`, `hr_scheduler`, `hr_prompt`, `hr_negative_prompt`, `force_task_id`.

API-only: `sampler_index:str="Euler"` (legacy alias; `sampler_name` wins), `script_name`, `script_args:list`, `send_images:bool=True`, `save_images:bool=False`, `alwayson_scripts:dict={}` (`{"Script Title": {"args":[…]}}`), `infotext:str` (full parameters string — fills any field not explicitly set; free state-restoration channel).

img2img adds: `init_images:list` (required; b64 / data-URI / URL — URL fetch gated by `api_enable_requests`, `api_forbid_local_requests` (SSRF), 30 s timeout), `resize_mode:int=0` (0 Just resize · 1 Crop and resize · 2 Resize and fill · 3 Just resize (latent upscale)), `denoising_strength=0.75`, `image_cfg_scale` (SDXL-Instruct only), `mask` (b64/URL; white = masked region), `mask_blur_x/y:int=4`, `mask_round:bool=True`, `inpainting_fill:int=0` (0 fill · 1 original · 2 latent noise · 3 latent nothing), `inpaint_full_res:bool=True` (= "Only masked"), `inpaint_full_res_padding:int=0`, `inpainting_mask_invert:int=0` (0 Inpaint masked · 1 Inpaint not masked), `initial_noise_multiplier`, `include_init_images:bool=False`, `force_task_id`.

Response: `{images:[base64 PNG…], parameters:<request echo>, info:<JSON string>}`. **When a grid is enabled the grid is prepended at index 0** (`index_of_first_image=1` in `info`). `info` JSON keys include: `prompt, all_prompts, negative_prompt, seed(s), all_seeds, subseed(s), width, height, sampler_name, cfg_scale, steps, batch_size, restore_faces, sd_model_name/hash, sd_vae_name/hash, denoising_strength, extra_generation_params, index_of_first_image, infotexts[], styles, job_timestamp, clip_skip, version`. Progress state carries `job_no/job_count/sampling_step/sampling_steps/skipped/interrupted`; pin your own task with `force_task_id`.

### 1.4 The infotext ("parameters") grammar — implement both directions

```
<Prompt>
Negative prompt: <Negative>
Steps: 20, Sampler: DPM++ 2M, Schedule type: Karras, CFG scale: 7, Seed: 123,
Face restoration: CodeFormer, Size: 512x512, Model hash: abcd1234, Model: sd_xl,
VAE hash: …, VAE: …, Variation seed: …, Variation seed strength: …,
Seed resize from: 0x0, Denoising strength: 0.75, Conditional mask weight: 1,
Clip skip: 2, ENSD: 31337, Token merging ratio: …, Init image hash: …,
RNG: CPU, Tiling: True, <script extra keys>, Version: v1.10…, User: name
```

Rules: negative line omitted when empty; pairs joined `", "`; `None` skipped; values containing `": "` or `","` quoted; fixed key order; `extra_generation_params` spliced before `Version`. Saved into PNG text chunks (`parameters` section) / EXIF UserComment for jpg/webp. crush must **emit** this on save/display and **parse** it back for paste/round-trip ("send to txt2img/img2img", PNG-info import).

### 1.5 Samplers & schedulers (hardcode list, discover at runtime)

Samplers: **DPM++ 2M** (UI default), DPM++ SDE, DPM++ 2M SDE, DPM++ 2M SDE Heun, DPM++ 2S a, DPM++ 3M SDE, Euler a, Euler, LMS, Heun, DPM2, DPM2 a, DPM fast, DPM adaptive, Restart, DDIM, DDIM CFG++, PLMS, UniPC, LCM. Each declares default scheduler + behaviors via `GET /samplers` `options{}`.
Schedulers: Automatic, Uniform, Karras (ρ=7), Exponential, Polyexponential, SGM Uniform, KL Optimal, Align Your Steps, Simple, Normal, DDIM, Beta. Legacy combined names (`"DPM++ 2M Karras"`) are accepted and auto-split.
Per-sampler extra knobs reachable via `override_settings`/request fields: `eta` (0–1, .01, default 1), `eta_ddim`, `s_churn` (0–100), `s_tmin` (0–10), `s_tmax` (0–999, 0=∞), `s_noise` (0–1.1, .001), `sigma_min/max`, `rho`, `eta_noise_seed_delta` (ENSD), `always_discard_next_to_last_sigma`, `sgm_noise_multiplier`, `skip_early_cond`, `uni_pc_variant/skip_type/order/lower_order_final`, `sd_noise_schedule` (Default | Zero Terminal SNR), `beta_dist_alpha/beta`, `s_min_uncond`.

---

## 2. Full tunables catalog — A1111 UI widgets (defaults are UI defaults; API defaults differ where noted)

### 2.1 Prompt top row

| Control | Widget | Default | Notes |
|---|---|---|---|
| Prompt | multiline textbox | "" | Ctrl+Enter generate / Alt+Enter skip / Esc interrupt; drop image → extracts infotext |
| Negative prompt | multiline textbox | "" | costs no CLIP token budget |
| Styles | **multi-select dropdown** + 📋 materialize + "Save prompt as style" | none | `{prompt}`/`{negative_prompt}` substitution; API `styles` |
| Token counter | live span | "0/75" | 75 (SD1) / 152 (SDXL); counts `<lora:>` tokens, expands `[a:b:N]` |
| Tool buttons | ↙ paste params · 🗑 clear · 📎 CLIP interrogate · 📦 DeepBooru (img2img) · 🌀 restore-progress | | |

### 2.2 Generation parameters

| Control | Widget / accordion | Default | Min–Max / step | API field |
|---|---|---|---|---|
| Sampling method | Dropdown (Sampler group) | DPM++ 2M | choices from `/samplers` | `sampler_name` |
| Schedule type | Dropdown, same group | Automatic | 12 schedulers | `scheduler` |
| Sampling steps | Slider | 20 (API 50) | 1–150 / 1 | `steps` |
| Width / Height | Sliders + ⇅ swap | 512/512 | 64–2048 / 8 | `width`, `height` |
| Batch count | Slider | 1 | 1–∞ / 1 | `n_iter` |
| Batch size | Slider | 1 | 1–8 / 1 | `batch_size` |
| CFG Scale | Slider | 7.0 | 1.0–30.0 / 0.5 | `cfg_scale` |
| Image CFG Scale | Slider (SDXL-Instruct only) | 1.5 | 0–3 / 0.05 | `image_cfg_scale` |
| Seed | Number + 🎲 (−1) + ♻️ (reuse last) | −1 | int | `seed` |
| Extra (checkbox) | reveals Variation group | off | | gates `subseed` |
| Variation seed | Number + 🎲/♻️ | −1 | int | `subseed` |
| Variation strength | Slider | 0 | 0–1 / 0.01 | `subseed_strength` |
| Seed resize from W/H | Sliders | 0 | 0–2048 / 8 | `seed_resize_from_w/_h` |
| Restore faces | global setting + model radio + `code_former_weight` slider 0–1 (.01, default .5; 0=max effect) | off | | `restore_faces:null`=follow settings; per-request via `override_settings` |
| Tiling | settings checkbox | off | | `tiling` |
| Clip skip | settings slider | 1 | 1–12 / 1 | `override_settings:{CLIP_stop_at_last_layers}` |
| Override settings | hidden multiselect | empty | any key | `override_settings` + `override_settings_restore_afterwards` |
| Script | Dropdown | None | `/scripts` | `script_name` + `script_args` |
| Refiner | InputAccordion | off | `refiner_switch_at` 0.01–1 / .01 default 0.8 | `refiner_checkpoint`, `refiner_switch_at` |

### 2.3 Hires. fix (InputAccordion, default off)

| Control | Widget | Default | Range/step | API |
|---|---|---|---|---|
| Upscaler | Dropdown (latent modes + all upscalers) | "Latent" | | `hr_upscaler` |
| Upscale by | Slider | 2.0 | 1.0–4.0 / 0.05 | `hr_scale` |
| Resize width/height to | Sliders | 0 | 0–2048 / 8 | `hr_resize_x/_y` |
| Hires steps | Slider | 0 (=steps) | 0–150 / 1 | `hr_second_pass_steps` |
| Denoising strength | Slider | 0.7 | 0–1 / 0.01 | `denoising_strength` |
| Hires checkpoint/sampler/scheduler | Dropdowns "Use same …" (hidden by default) | same | | `hr_checkpoint_name`, `hr_sampler_name`, `hr_scheduler` |
| Hires prompt / negative | Textboxes (hidden by default) | "" | | `hr_prompt`, `hr_negative_prompt` |

**Precedence rule the form must implement verbatim:** both resize-to 0 → use Scale-by; one 0 → computed from the other + aspect; both set → upscale to *at least* those dims, some crop. (Scale-by is the default/preferred.) Debug tip: denoise 0 + hires steps 1 ≈ preview of the upscaled first pass.

### 2.4 img2img / inpaint

Mode tabs: **img2img** editor · **Sketch** · **Inpaint** (draw mask) · **Inpaint sketch** · **Inpaint upload** (separate B&W mask, white = inpaint; transparent PNG alpha also accepted — "any even slightly transparent area becomes mask") · **Batch** (+ PNG-info inheritance accordion). Editor height setting 80–1600 default 720.

| Control | Widget | Default | Range/step | API |
|---|---|---|---|---|
| Resize mode | Radio (4) | Just resize | | `resize_mode` |
| Resize to / Resize by | Tabbed sliders + 📐 auto-detect | 512×512 / 1.0 | 64–2048/8 · 0.05–4.0/.05 | `width/height` / UI-only scale_by |
| Denoising strength | Slider | 0.75 | 0–1 / 0.01 | `denoising_strength` |
| Mask blur | Slider | 4 | 0–64 / 1 | `mask_blur` |
| Mask mode | Radio [Inpaint masked · Inpaint not masked] | masked | | `inpainting_mask_invert` |
| Masked content | Radio [fill · original · latent noise · latent nothing] | original | | `inpainting_fill` |
| Inpaint area | Radio [Whole picture · Only masked] | Whole | | `inpaint_full_res` |
| Only masked padding | Slider | 32 | 0–256 / 4 | `inpaint_full_res_padding` |

Settings-side (per-request via `override_settings`): `inpainting_mask_weight` ("Conditional mask weight", 0–1/.01/1.0), `initial_noise_multiplier` (0–1.5/.001/1.0), `img2img_extra_noise` (0–1/.01/0 — **must stay below denoising strength**), `img2img_color_correction`, `img2img_fix_steps`, `upscaler_for_img2img`, `save_mask`/`return_mask`/`return_mask_composite`, `img2img_background_color`.

### 2.5 Extras tab (postprocessing)

Single / Batch / Batch-from-Directory inputs; **Upscale** script (on by default): Upscaler 1 dropdown, Upscaler 2 dropdown + **visibility** 0–1/.001/0, tabbed **Scale by** (1.0–8.0/.05/4 + max-side-length guard) vs **Scale to** (64–8192/8 + Crop to fit); **GFPGAN** visibility 0–1/.001/1.0; **CodeFormer** visibility 0–1/.001/1.0 + weight 0–1/.001/0 (0=max); RealESRGAN backfill + tile controls (`ESRGAN_tile` 0–512/16/192, overlap 0–48/8) via settings. **PNG Info tab**: parse any image's parameters, Send to txt2img/img2img/inpaint/extras (API twin `POST /png-info`).

### 2.6 Selectable scripts

X/Y/Z plot (axis list: Seed, Var. seed/strength, Steps, Hires steps, CFG, Prompt S/R, Prompt order, Sampler, Checkpoint, Sigma churn/min/max/noise, Schedule type/rho, Eta, Clip skip, Denoising, Hires upscaler, Cond. mask weight, VAE, Styles, UniPC order, Face restore, Token merging, Refiner…, Size…), Prompt matrix (`|` combinatorial, same seed), Prompts from file/textbox (`--flag value` lines), Loopback, Outpainting mk2, Poor man's outpainting, SD upscale, img2img alternative test, Custom code. **Render dynamically from `GET /script-info`** — do not hardcode.

### 2.7 A1111 UI layout map → TUI mapping

Left column = accordion tree (Dimensions → Sampler → Batch → Seed → Hires/Refiner accordions → Override settings → Scripts); right column = gallery (live-preview frames stream in during generation; selection drives infotext/reuse/send-to) + button row (open folder · save · save zip · send to img2img · send to inpaint · send to extras · ✨ hires-upscale) + infotext + log. Buttons: Generate (right-click = generate forever), Skip, two-stage Interrupt (first = after current image, second = immediate). **TUI:** left column → scrollable accordion tree; gallery → mosaic image pane; skip/interrupt → `POST /sdapi/v1/skip|interrupt` while polling `/progress?skip_current_image=false` (the b64 `current_image` is the live preview frame); send-to → copy image + parsed infotext between tabs.

---

## 3. Prompt semantics & wiki findings (research unit 2)

The wiki is small and lopsided — no per-tab widget list, **no CFG/steps guidance beyond task-specific recipes, no sampler guide**. What it *does* pin:

### 3.1 Prompt-language feature matrix (what the prompt widget must parse/highlight)

| Syntax | Effect |
|---|---|
| `(word)` / `((word))` | ×1.1 / ×1.21 attention |
| `[word]` | ÷1.1 (weight syntax works only with `()`) |
| `(word:1.5)` / `(word:0.25)` | explicit weight; w<1 means 1/w |
| `\(` `\)` `\[` `\]` | literal chars |
| `BREAK` (uppercase) | force new 75-token CLIP chunk (infinite prompt = chunked CLIP) |
| `# comment` | ignored; stripped from infotext (1.8+) |
| `[from:to:when]` · `[to:when]` · `[from::when]` | stepwise prompt swap/add/remove; `when` 0–1 fraction, integer = absolute step, 1–2 = hires pass; nesting works |
| `[a|b]` | alternating words, every other step, loops |
| `a \| b \| c` (Prompt matrix script) | combinatorial batch, same seed, first segment always kept |
| `A AND B:1.2 AND C` | composable diffusion subprompts + colon-before weights; <0.1 ≈ absent |
| `<lora:name:mult[@start@end]>` · `<hypernet:name:mult>` | extra-network attach; positive prompt only; erased after parse; batch uses first prompt's LoRAs |
| bare embedding filename | textual-inversion token |
| NAI compat table | `{word}`=(word:1.05), `{{word}}`=(1.1025), `[word]`=(0.952), `[[word]]`=(0.907) |

**Proven negatives (whole-wiki regex sweep):** `TCD`, `Flux`, `TAG`, regional seeds, `diffuse noise`, vary-region/fix-seed radios, per-axis weights, Prompt S/R as a standalone script, CFG presets, inpaint erode/dilate — **none exist in A1111**; they are Forge/SD.Next concepts. Do not model them as core; keep them out or behind an extension-shaped "advanced" namespace. (They *may* exist on the user's custom gateway — confirm against the live API.)

### 3.2 Seed semantics

−1 = random (source-derived, not wiki); 🎲 random / ♻️ reuse buttons; `Extra` disclosure → variation seed + strength (max strength ⇒ pure variation-seed image, except ancestral samplers) and seed-resize-from (ancestral samplers worse at it). Reproducibility is version-dependent (Seed-breaking-changes page: emphasis impl 2022-09-29, Karras sigmas 2023-01-05, LoRA weight-rewrite 2023-03-26, 2nd-order schedule 2023-04-29, hires rework + prompt-edit timeline 1.6.0, zero-terminal-SNR 1.8.0) — carry `Version:` in infotext and offer the compat toggles.

### 3.3 Model types (drives pickers)

Checkpoint (quick-settings dropdown; API accepts UI name / filename / stem / hash; `*-inpainting.ckpt` activates by filename; depth & unclip models img2img-only; SDXL = base + refiner pair) · VAE (Automatic = baked-in) · LoRA (`models/Lora`, card browser or `<lora:>`) · Textual Inversion (`embeddings`, bare filename; model-specific; Train tab exists with full widget set) · Hypernetwork (`<hypernet:>`) · Upscaler models (ESRGAN/RealESRGAN/SwinIR/LDSR/SCUNet/DAT + latent modes).

### 3.4 Settings/UI-config analogues worth cloning

`ui-config.json` per-widget `value/minimum/maximum/step/visible` overrides keyed `"<tab>/<Widget Label>/<prop>"` → crush should persist form-widget overrides similarly. Quick-settings strip = pinned settings applied+saved instantly. Filename token palette (`[seed] [steps] [cfg] [sampler] [model_name] [width] [height] [styles] [datetime<Format><TimeZone>] [prompt…] [job_timestamp] [batch_number] [generation_number] [hasprompt<…>] [number] [none]` + elision rules) for save-naming.

---

## 4. SugarCraft form/popup libraries (research unit 3)

Layer orientation:

```
candy-core      Program/Model/Cmd/Msg runtime (keys, mouse, ImageOverlay)
 ├─ candy-sprinkles   Style/Border/Canvas (chrome)
 ├─ candy-layout      Cassowary constraint solver (Form::withConstraints)
 ├─ candy-forms   ★ canonical widgets + Field/Form engine  ← write against this
 │    └─ sugar-prompt  (pure class_alias façade — do NOT target)
 ├─ sugar-bits    Tabs/Help+Key/Progress/AnimatedProgress/Paginator/Timer/Tree/Table (canonical);
 │                TextInput/TextArea/ItemList/Viewport/FilePicker/Spinner/… are aliases → use Forms
 ├─ candy-mouse   Mark/Scanner/Zone/ZoneClickTracker (hit-test primitive; crush already runs it)
 │    └─ candy-zone    Manager/DragTracker/ClickCounter/HoverTracker (bubblezone layer)
 ├─ sugar-veil    Veil/VeilStack overlay compositor (crush dep; one Veil used today)
 ├─ candy-focus   FocusRing (crush dep)
 ├─ sugar-gallery PosterGrid/Rail virtualized zone-aware grids (not a crush dep)
 ├─ sugar-toast   floating alerts w/ clickable actions (crush dep)
 └─ candy-testing ProgramSimulator/ScriptedInput/golden ANSI
```

### 4.1 Control → class map

| Required control | Lib + class | Mouse | Notes |
|---|---|---|---|
| Text input | `Forms\Field\Input` / `Forms\TextInput\TextInput` | ✗ | validators, suggestions (async/fuzzy), password mode, history, vim |
| Multiline textarea (prompt/negative) | `Forms\Field\Text` / `Forms\TextArea\TextArea` | ✗ | crush's draft box already is `TextArea` |
| Slider (steps, CFG, denoise…) | `Forms\Field\Slider` (`candy-forms/src/Field/Slider.php`) | ✗ | `Slider::new($key,$min,$max,$step,$initial)`; ←→/h/l/Home/End; clamps, saturating; renders `[███◆░░░] 7.5`; **keyboard-only — drag needs new code (§4.2.1)** |
| Dropdown / radio | `Forms\Field\Select` (wraps `ItemList`) | ✔ via ItemList | `withOptions(...)`, `withEnum()`, fuzzy+async suggestions; single-choice list *is* the radio |
| Multi-select (styles) | `Forms\Field\MultiSelect` | ✔ via ItemList | `[ ]/[x]`, Space toggles, min/max/limit |
| Checkbox / yes-no | `Forms\Field\Confirm` (y/n pills) or one-option MultiSelect | partial | |
| Scrollable list w/ filter | `Forms\ItemList\ItemList` | ✔ click + wheel | `/` filtering, `LoadMoreMsg` paging |
| Scrollable pane | `Forms\Viewport\Viewport` | ✔ wheel | scrollbar, smooth scroll |
| Tabs (form pages) | `Bits\Tabs\Tabs` | ✔ (`withZoneManager`) | needs new `sugar-bits` + `candy-zone` requires |
| Modal/popup frame | `Veil\Veil` (+`VeilStack`) | ✔ click-outside-dismiss | `withBackdrop(0–100)`, `withBorder`, `withPosition`, animations (Fade/Scale/Slide, honey-bounce easing) |
| Region focus (list vs field vs preview) | `Focus\FocusRing` | — | host maps Tab/Shift-Tab |
| Progress (generation %) | `Bits\Progress\Progress` / `AnimatedProgress` (spring) | — | |
| Spinner | `Forms\Spinner\Spinner` | — | |
| File picker (init image) | `Forms\Field\FilePicker` | ✗ | crush also has bespoke `Tui/DirectoryPicker` |
| Interactive table (XYZ axes etc.) | `Bits\Table\Table` | ✗ | sort/filter/page |
| Poster grid (gallery) | `Gallery\PosterGrid`+`PosterCard` | ✔ zone-aware | virtualized; not a crush dep |
| Key legend / help line | `Bits\Help\Help` + `Bits\Key\{Binding,KeyMap}`; crush's `KeyBindingRegistry` | — | |
| Toast notifications | `Toast\Toast`+`Alert`+`Action` | ✔ | already used by crush |
| Color picker | `Forms\Field\Color` | ✗ | 6×6×6 cube paging |
| Date | `Forms\Field\Date` | ✗ | |

Form engine: `Forms\Form::new(field…)` / `::groups(group…)` (one Group = a page — natural backing for a tabbed form), `Theme` (11 slots, presets charm/dracula/catppuccin/…), `KeyMap` (Tab/Shift-Tab/Enter/Esc), validators (`Required/MinLength/MaxLength/Pattern/Email` + async), `isHidden(values)` conditional fields (→ "Extra" seed disclosure, hires sub-controls, inpaint-only controls), `withConstraints()` for two-column solver layout.

### 4.2 Gaps — must be written new

1. **Mouse-draggable slider.** `Field\Slider::update()` never inspects `MouseMsg`. Recipe: mark the track as a zone (`Zone\Manager::mark()` or crush's existing `Mouse\Mark`), on Press/`ZoneDragMoveMsg` map `pos(msg)[0]/zone.width()` → `min + ratio*(max-min)` → `withValue()` (already clamps+snaps). `Zone\DragTracker` (candy-zone) emits the drag msgs; `AnimatedProgress`' spring is the ready-made thumb-glide layer.
2. **Number field** (bounded numeric box, ↑/↓ stepping, min/max/step, locale-safe parse) — new `Field` composing `TextInput` + Slider's normalisation.
3. **Key-value/parameter rows** (override_settings editor, alwayson_scripts args) — nothing exists; `SettingsEditor` is bespoke prior art.
4. **Horizontal radio pill row** — `Select` with prefixes gets a vertical radio; the A1111-style inline row is new (small).
5. **Hover tooltip** — `ZoneHoverTracker` + `Veil` offset compositing exist; the glue class is unwritten.
6. **Click-to-position cursor in text fields** — none.
7. **Stacked-modal management for crush** — `VeilStack` exists but crush's `Renderer.php:6305-6323` is a one-modal `if ($overlay === '')` chain; "form over permission prompt" needs the stack.

### 4.3 How crush presents popups today (the pattern to match)

Overlay = nullable readonly field on `Chat` (e.g. `?SessionPicker`, `?PaletteState`, `?DirectoryPicker`); while up it owns every key (routing short-circuits in `Chat::update()`); `Renderer` picks the first non-empty `renderX()` arm, then: `clipRowsToCols → liftZonesUnderOverlay → padForOverlay → Veil::new()->withBackdrop(50)->composite($overlay,$backdrop,CENTER,CENTER,$shift) → restoreLiftedZones → mark zones AFTER compositing` (Veil measures cells; sentinels would count). Modal chrome = `Style::new()->border(Border::rounded()->withTitle(…))->borderForeground($theme->border)->padding(1,2)->width($inner)->render(implode("\n",$lines))`. `candy-forms` **Field objects are driven directly** (SettingsEditor holds `?Field $editing`, focuses it, forwards keys, splices `view()`) — `Forms\Form` itself is used nowhere in crush. Pickers return **action objects** (`SessionListAction`) that Chat executes. The media form should follow exactly this idiom.

---

## 5. Image/video rendering libraries (research unit 4)

### 5.1 candy-mosaic — image→terminal renderer

Decodes PNG/JPEG/GIF/APNG via ext-gd. Facade ladder: `Mosaic::auto()` (never throws; env-first probe, DA1 fallback, tmux passthrough decorator), `::kitty()/::iterm2()/::sixel(Dither)/::halfBlock()/::quarterBlock()/::ascii(AsciiColorMode)/::chafa(opts…)`, `::probe()/::diagnose()`, `::supportedProtocols()`. **Auto precedence: kitty > iterm2 > sixel > chafa > halfblock.**

- **`Mosaic::fromModeString(string): ?Mosaic`** accepts `auto|sixel|kitty|iterm2|halfblock|half|ansi|quarterblock|quarter|ascii|ansi256|truecolor|chafa` — this is the single call behind the user-facing render-mode setting.
- Loading: `ImageSource::fromString($bytes)` (**the A1111 base64-decode path**), `fromFile`, `fromUrl/fromUrlAsync` (SSRF-guarded, `allowedHosts`), `fromRgb` (decoder frames), `fromAnimatedFile → Animation{frames[],delaysMs[]}` (GIF+APNG), `crop/resize/aspectRatio`, `withMaxPixels` bomb ceiling.
- Rendering: `render(ImageSource, int $cellsW, ?int $cellsH = null): string` — cells; height from aspect when null; `CELL_ASPECT=2.0`; `Scale` enum Fit/Fill/Stretch/None/Crop; `MosaicBuilder` with renderer/resize/dither/scale.
- **`isInline()`**: true for halfblock/quarterblock/ascii (one line per cell row → safe in a text frame); false for sixel/kitty/iterm2 (opaque escape blob → must be painted out-of-band via candy-core `ImageOverlay`/`ImageLayer`). `supportsAlpha()` false for half-block.
- Color depth: sixel quantises to ≤256 (Floyd–Steinberg default); ascii modes Mono/Ansi256/TrueColor (`38;5`/`38;2` SGR, ramp `' .,:;i1tfLCG08@'`); half/quarter always 24-bit `▀` fg/bg; kitty/iterm2 lossless PNG; chafa CLI `--colors=256` default.
- Placement/caching: `ImageLayer::place()/placeTracked()` → marker block + `PlacedImage{marker,imageId}` fed to `new View($frame, images: placements)`; dedup by xxh3; `DiskCache(key=id,w,h,protocol)` LRU; `KittyOptions` transmit/place/z-index/zlib; `Renderer::delete($imageId)` (iTerm2 pops topmost — id ignored).
- Detection: `Detect::probe()` env-first (`KITTY_WINDOW_ID`, `TERM_PROGRAM∈{WezTerm,ghostty,iTerm.app,mintty}`, `TERM~xterm-kitty`…), DA1 `\x1b[c` only when needed, 100 ms timeout; XTWINOP 14/16 for pixel/cell size → `CellSize`. `Capability` snapshot `{sixel,kitty,iterm2,halfblock,chafa,cellSize,inTmux}`.
- **candy-vt cannot verify sixel/kitty/iTerm2 output** (its DA1 deliberately omits `;4`; DCS is a no-op) — assert graphics protocols as raw bytes (existing `Iterm2RendererTest` pattern) or fake the probe via `Detect::setProbeStdin()`; use cell-grid/golden assertions for inline modes only.

### 5.2 sugar-reel — terminal video player (the video answer)

`VideoSource::probe` (ffprobe JSON) → `DecoderFactory`: local `.gif` → pure-PHP `GifDecoder` (reuses candy-flip); everything else + all URLs → `FfmpegDecoder` subprocess (array-form `proc_open`, no shell; `-ss` before `-i` for seek; self-delimiting PNG stream for graphics modes, rgb24 for cell modes; letterbox pad filter; network reconnect flags + re-presented headers for signed streams). `RgbFrame{bytes,w,h,?png}`.

- **`Mode` enum**: `ascii|ansi256|truecolor|halfblock|quarterblock|sixel|kitty|iterm2` with `rowsPerCell/colsPerCell/isGraphics/label`. `RendererFactory::autoMode()` video ladder: **sixel > kitty > iterm2 > truecolor-halfblock > ansi256 > ascii** (note: differs from mosaic's image ladder). `GraphicsRenderer` bridges per-frame into candy-mosaic at full pixel resolution (fixes "postage-stamp" output); sixel prefers external `chafa` encoder when available, pure-PHP `SixelRenderer` fallback.
- **`Player` is a TEA Model**: `Player::open($path,$cellsW,$cellsH,?fps,Mode,loop,ramp,cellPxW,cellPxH,?WebVtt,$headers,frameBudgetMs)`; keys `Space` pause · `←/→` ±10 frames · `[`/`]` speed 0.25–4.0 · `0-9` jump-to-% · **`m` cycle render mode** (rebuilds the ffmpeg child only when decode geometry changes — text-mode switches are free) · `q/Esc` quit; seek `withSeek/seekToSeconds`; **`frameAt(float): ?RgbFrame`** scrub previews; `Reel::toPlayer()` returns a PAUSED player for embedding without owning the loop; clamps cols 10–200, rows 5–80.
- Audio: `AudioPlayer` shells `ffplay`/`mpv --no-video` as master clock (stdio → /dev/null files, not pipes; injectable monotonic clock; credential redaction). Subtitles: `WebVtt::parse` + caption line. Sync: `Sync::targetFrame/shouldSkip/shouldHold`.
- **⚠️ Integration hazard:** `Player::view()` paints graphics blobs **inline in the view string** — fine for its own fullscreen Program, fatal for crush's line-diffed chat frame. Video in crush must either (a) be a pushed full-screen screen using phlix's absolute-cursor chrome trick, or (b) route frames through `ImageLayer::placeTracked()` + Veil.

### 5.3 phlix-console-client — transferable glue (ranked)

| File | What to borrow |
|---|---|
| `src/Media/MosaicFactory.php` | `forMode(?string)`: null/auto→`Mosaic::auto()`, else `fromModeString()` or throw; **grids force half-block** (cell modes tile as text; graphics modes are one blob) |
| `src/Media/PosterLoader.php` | async load + **inline-vs-overlay routing on `isInline()`**; `ImageLayer::digestFor/placeTracked`; protocol-keyed cache; `release()/releaseAllExcept()` scroll-windowing (frees kitty/iTerm2 terminal memory); `protocol()`/`cellSize()` feed the video player; host-allowlist handling |
| `src/Screen/PlayerScreen.php` | `productionFactory($mode,$cellSize)` → `Player::open(..., mode: RendererFactory::autoMode() fallback, cellPxW/H)`; **prepare-off-sync-path** (probe+spawn inside init Cmd behind a "Preparing…" frame, delivered via `PlayerReadyMsg`); graphics-mode chrome via `Ansi::cursorTo+eraseLine` (image blob is one logical multi-row line — `\n` chrome would land on row 3); `Teardownable` — `$player->stop()` on Esc **and** global Ctrl-C or ffmpeg/ffplay children leak (this is what `tools/check-child-lifetimes.php` gates); key **override-then-forward** table; direct-play→transcode fallback with polling |
| `src/Ui/Scrubber.php` | 94-line glyph timeline `m:ss [████│░░░] h:mm:ss` + chapter ticks — glyphs not colour, survives NO_COLOR; liftable verbatim |
| `src/Capabilities.php` | `phlix doctor` mosaic report (protocol, sixel/kitty/iterm2/halfblock, colorProfile, TERM, tmux) — lift into crush's Doctor tool |
| `src/Config/Config.php` + `bin/phlix` | `terminal_render_mode` as a device-local config key; `--mode` container wiring |

### 5.4 Backend payload → ingestion map

| Payload | Call |
|---|---|
| base64 PNG/JPEG (A1111 `images[]`) | strip `data:` URI prefix → `base64_decode` → `ImageSource::fromString` → render |
| file path (server-side output) | `ImageSource::fromFile` / `Reel::open($path)` |
| http(s) URL / signed stream URL | `ImageSource::fromUrl(Async, headers, allowedHosts)` / `Reel::openUrl($url, headers, allowedHosts)` |
| **base64 mp4 blob** | **no lib accepts it** — write to temp file (or loopback-serve) first, then `Reel::open` |
| GIF/APNG animation | `ImageSource::fromAnimatedFile` → `AnimationDriver`, or `Reel::open('x.gif')` |
| WebVTT | `WebVtt::parse` → `Player::withSubtitles` |

crush's `ToolResult::okWithImage(name, result, $bytes, ?id)` + `Renderer::renderToolImage()` (protocol-keyed LRU cache, `isInline() ? body : images->place(body,cols,rows)`, never throws in `view()`) means **an image-generation tool lands on the working display path with zero new renderer work**. crush already requires `candy-mosaic`; it does **not** yet require `sugar-reel`.

---

## 6. sugar-crush integration points (research unit 5)

### 6.1 Tools

`Tool { name/description/inputSchema/execute(array $args): ToolResult }`; registration is the **attribute catalog**: `#[BuiltInTool(name:'GenerateImage', permission: ToolPermissionClass::Ask, position: <next free, e.g. 33>, gloss:'…')]` on a class in `src/Tools/BuiltIn/` implementing `BuildsFromCatalog::fromCatalog(ToolBuildContext)`. Positions in use: 1–22, 30–32 → take 33/34. `ToolBuildContext` has no settings field — launch-wide config arrives via a new context field or the per-turn `ToolLimits::applyTo()` rebind. Empty schema properties must be `new \stdClass()` (a bare `[]` json_encodes wrong and strict servers reject). Permission class **Ask** is right for network generation tools (asks under default/accept-edits/plan; denied under dont-ask); rules grammar `'GenerateImage(...)'` works once cataloged. **Long generations die at the 120 s idle watchdog unless the tool implements `AcceptsHeartbeat::executeWithHeartbeat($args, $heartbeat)`** — call the beat closure each progress poll and check `CancellationToken::cancelTool($id)` between polls. `Doctor` (image result), `WebFetch` (schema+Ask+stream dialing), `Bash` (heartbeat) are the copy targets. **Do not reuse WebFetch's dialing** — its SSRF blocklist (`Permissions/FetchTarget`) refuses LAN/private backends, which is exactly where an SD server lives.

Docs are drift-pinned: regenerate `php tools/gen-tool-docs.php --write` (rewrites README + docs/ARCHITECTURE/AGENTS_AUTHORING/PERMISSIONS/SETTINGS rosters + counts), `gen-command-docs.php`, `gen-settings-doc.php`; new env vars must be tabulated in `docs/ENVIRONMENT.md` (EnvRosterDriftTest asserts both directions); new tests must be added to `scripts/parallel-tests-durations.tsv` and `tests/Config/Support/suite-figure.json` refreshed (CI shards fail-closed).

### 6.2 Slash command + key binding + popup

`builtin-commands/4000-generate.php` returning `BuiltInCommand::new(CommandSpec::new('generate', Lang::t('cmd.generate.description'), Lang::t('cmd.category.media'), paletteAction: PaletteAction::OpenMediaForm, paletteLabel:…, argumentHint:…))->withHandler('handleGenerateCommand', CommandArguments::Parsed)->withHostCommand(GenerateHostCommand::class)` — the HostCommand is what gives headless/serve-mode parity. Handler opens the popup: nullable `?MediaFormState $mediaForm` on Chat (immutable, `with*()`), keys routed in `Chat::update()` before chat keys while up, `Renderer` arm composites via Veil, fields driven SettingsEditor-style, **action objects** (`GenerateAction(prompt, params…)`) come back out and Chat turns them into a tool call / direct backend run. One `KeyBinding` row (unused Ctrl rune — check `chatCtrlRunes()`) + `keys.<id>` in `lang/en.php` + `KeyBindingDriftTest` will drive it.

Mid-turn model→user interaction (e.g. the agent asking for generation params) already crosses both UIs via `AskUserTool` + `DelegatesToEngine/RelaysPermissionAsks` and the permission-ask relay — reuse that channel for the web form rather than inventing one.

### 6.3 Config

New category file `src/Config/Settings/Definitions/MediaSettings.php` (`SettingCategory` gains a case): `sd.baseUrl` (String, `RiskClass::Egress`, `withLayered()`, env `SUGARCRUSH_SD_BASE_URL`, UrlValidator, reader symbol → `GenerateImage::fromCatalog`), `sd.apiKey` (Security, user-config-only, `${VAR}` placeholder in config.json is the sanctioned secret channel), `sd.timeoutSeconds` (Int, `ApplyMode::NextTurn` via ToolLimits rebind), `sd.defaultModel/size/sampler…`, optionally an `sdBackends` Map (name→{baseUrl,apiKey,kind}) mirroring the `providers` map — needed once multiple dialects exist. Plus `ui.imageRenderMode` (enum-backed; allowed values = `Mosaic::supportedProtocols()` + `fromModeString` aliases; today `ToolResult::probeMosaic()` hardcodes `Mosaic::auto()` — replace with `fromModeString($setting)`; render cache already keys by protocol so mode switches invalidate for free). Regenerate `docs/SETTINGS.md`; `ConfigWriteProducerDocumentationDriftTest` catches CLI-written keys missing docs.

### 6.4 Server / web (phase 2 shape — design for it now)

Transport: `sugarcrush serve` → `Ws/Upgrade` (`GET /ws`, strict subprotocol `sugarcrush.v1`, auth tickets/tokens) → `Protocol/Dispatcher` JSON-RPC 2.0. Methods register via `MethodSpec::new(name, Scope, desc, handler)` in `src/Protocol/Methods/*`; events via `EventType::CATALOGUE` + `EventEnvelope` (durable ones journaled + replayed); payloads schema'd in `Protocol/Schema/EventSchemas.php`; `src-web/protocol/generated.ts` is **generated from the PHP schema** (`scripts/gen-protocol.mjs`) — extend PHP, regenerate, never hand-edit. **Today `TranscriptProjector::toolFinishedEvent()` drops `imageBytes` — no image crosses the wire.** Phase 2 = add media path/id to `tool.finished` (or a new `media.ready` event) + `Methods/MediaMethods.php` (`generate.submit`, `generate.status`, `media.fetch` — big payloads follow `tool.output`'s sliced-read pattern or a guarded HTTP route; `Http/ApiController` is auth+health only today) + `<img>`/`MediaCard` arm in `ToolCard.vue` (web renders text/diff only; markdown goes through DOMPurify which already allows img). **Design consequence for phase 1:** keep the generation request/response, the form spec (field list + values), and the artifact reference (file path + id) as plain serializable DTOs from day one so the same objects ride the wire later; store artifacts at a path, never only in memory.

### 6.5 HTTP client & storage

Providers use Guzzle via `Providers/Concerns/HttpClientDefaults::guzzleClient()` (bounded connect, no total timeout — long reads); tools that self-dial use plain PHP streams (`WebFetch`) or Guzzle with explicit short timeouts (MCP). For A1111: bound connect, read-idle timeout, generous total for the poll wait, poll `/progress` inside `executeWithHeartbeat`. Storage: generated artifacts → `~/.sugar-crush/media/<session>/` (or workspace `.sugar-crush/media/`) following `Server/StateDir`/`PrivateRetainedDir` discipline (0700 dirs, 0600 files, `.partial`+rename atomic writes); `ToolOutputSpill`/`ClipboardImage` temp dirs have the wrong lifetime (7-day sweep). Return absolute path in `ToolResult::$content` + bytes in `imageBytes` (TUI shows now) + `imagePath` (web fetches later). Video references = path + `🎞` notice row until a player surface exists. **MCP: nothing to do** — sugar-crush is an MCP *client*; built-in tools aren't re-exposed (and if an A1111 server were ever wrapped in MCP, `McpToolBridge` picks it up for free).

---

## 7. Video beyond A1111 (LTX-Video / Wan 2.2 / LTX-2.5)

**A1111 core does not do video, explicitly** — no endpoints, no frame/fps/motion fields anywhere in its API models (`grep` verified; the SVD extension that briefly shipped is removed). Motion/video in A1111-land is third-party (Deforum, Animator, Vid2Vid…). So the user's LTX-Video / Wan 2.2 endpoints are a **separate dialect** to model behind its own namespace, with:

- request fields beyond A1111: frame count / duration, fps, motion conditioning (motion-bucket ids), image conditioning (init frame / first+last frame for image-to-video), audio conditioning (LTX has audio), resolution/aspect, guidance, solver/steps as applicable;
- response = mp4 (server path / download URL / base64 blob) or frame sequence, not `images:[b64 png]`;
- **async job semantics**: A1111's synchronous `/prompt` would time out on minutes-long renders — task-id + submit/status/fetch polling (generalize `force_task_id` + `/progress`);
- the exact contract must be read off the live server (`/model_info` and friends) once reachable — it is not A1111-standard.

---

## 8. Proposed architecture & build plan

### 8.1 New code map

| Piece | Location | Pattern source |
|---|---|---|
| A1111/dialect client `SdClient` (probe, txt2img/img2img/extras/progress/interrupt/skip/options/samplers/…, infotext emit+parse, DTOs) | `src/Support/Sd/` (new namespace: `Client`, `Request`, `Response`, `Infotext`, `Capabilities`) | `WebFetch` dialing + `HttpClientDefaults` lessons |
| Backend capability probe (A1111 vs custom gateway vs video-only; which models loaded; image vs video support) | `Sd\Capabilities::detect()` on connect + `/generate` picker | §7; live re-probe pending |
| `GenerateImage` tool (position 33, Ask, `AcceptsHeartbeat`, `ParallelSafe`, `TruncatesOutput`; result `okWithImage`) | `src/Tools/BuiltIn/GenerateImage.php` | `Doctor` + `WebFetch` + `Bash` |
| `GenerateVideo` tool (submit/poll/fetch; saves mp4; result path + `🎞` row; inline playback phase 1.5) | `src/Tools/BuiltIn/GenerateVideo.php` | same + §7 |
| Media popup form `MediaForm` (accordion/tab pages: Prompt · Dimensions · Sampler · Seed · Hires · img2img/Inpaint · Advanced/override; tabs `Bits\Tabs`, fields `Forms\Field\*`, frame `Veil`, regions `FocusRing`, action objects out) | `src/Tui/MediaForm/…` | `Tui/SessionPicker.php` + `SettingsEditor` idiom |
| New widgets: zone-draggable `Slider` (or `MediaSlider` decorator), `NumberField`, (later) radio-pill row, tooltip glue | `candy-forms` PRs (canonical home) or crush-local first | §4.2 |
| Render-mode setting + wiring | `InterfaceSettings`/`MediaSettings` + `ToolResult::probeMosaic()` → `fromModeString` | phlix `MosaicFactory` |
| Video player surface (full-screen pushed screen with `graphicsChrome`, `Teardownable` stop on Esc+Ctrl-C, mode/cellSize propagation, `Scrubber`, key override-then-forward) | `src/Tui/VideoPlayerScreen.php` + require `sugarcraft/sugar-reel` | phlix `PlayerScreen` §5.3 |
| `/generate` (+ `/generate video`, `/rendermode`, `/sendto`) commands, key binding, lang keys | `builtin-commands/40NN-*.php`, `lang/en.php` | `0300-model.php` |
| Config `sd.*` keys + env roster docs | `Definitions/MediaSettings.php`, `docs/ENVIRONMENT.md` | `ModelProviderSettings` |
| Storage `~/.sugar-crush/media/<session>/` | `Support/MediaStore.php` | `StateDir`/`PrivateRetainedDir` |
| Phase 2 web: `tool.finished` media field / `media.ready`, `MediaMethods` (`generate.submit/status`, `media.fetch`), regenerate `protocol/generated.ts`, `MediaCard.vue`, form over the permission/AskUser relay | `src/Protocol/…` + sugar-crush-web | §6.4 |

### 8.2 Phasing

1. **Phase 0 — live contract:** re-probe skynet2:30001 from a permitted network; capture `/model_info`, whether `/sdapi/v1/*` exists, video job shapes. (Blocked from this host: connection refused.)
2. **Phase 1 — txt2img end-to-end:** `SdClient` + capability probe + `GenerateImage` tool + minimal prompt/steps/size/cfg/seed/sampler form popup + `sd.*` settings + `/generate` + docs regen + tests (ProgramSimulator + golden ANSI + byte-assert protocols + `LoopPin`).
3. **Phase 2 — full knob parity:** hires fix, variation/seed extras, styles multi-select, img2img (init upload via FilePicker + mask modes), inpaint controls, extras/upscaling, dynamic `script-info` renderer, infotext round-trip (paste params / PNG-info import), save-to-disk with filename tokens.
4. **Phase 3 — video:** `GenerateVideo` + `sugar-reel` dep + `VideoPlayerScreen` + LTX/Wan job dialect + async job polling UI (progress, cancel, ETA).
5. **Phase 4 — render-mode UX polish:** `ui.imageRenderMode` setting + `/rendermode` quick toggle + mode ladder display in the gallery pane + poster-grid half-block defaulting if a gallery is added.
6. **Phase 5 — web parity:** protocol events + media fetch + `MediaCard.vue` + form-over-ask-relay.

### 8.3 Hazards (all found by the research passes)

- 120 s idle turn-watchdog kills silent generations → `AcceptsHeartbeat` is mandatory, not optional.
- WebFetch's SSRF blocklist would refuse LAN backends → dedicated dialing with its own gate story.
- `inputSchema` empty properties must be `new \stdClass()`.
- sugar-reel's inline graphics blob corrupts crush's line-diff → `ImageLayer`/`ImageOverlay` only; `ImageOverlay::MAX_IMAGES=6398`; untrusted text is sanitized so forged markers can't paint.
- ffmpeg/ffplay child leaks → `Teardownable` + `stop()` on every exit path; `tools/check-child-lifetimes.php` is repo-wide fail-closed.
- candy-vt can't verify graphics protocols → byte-assert or fake `Detect::setProbeStdin()`.
- Adding `sugar-bits`/`candy-zone`/`sugar-reel`/`sugar-gallery` = `require` line only, never `repositories[]` in a lib manifest (`tools/check-path-repos.php --no-lib-path-repos`).
- Every roster is CI-red if hand-edited: tool docs, command docs, settings docs, env tables, key bindings, suite figure + durations TSV.
- Mode ladder asymmetry: mosaic image auto = kitty>iterm2>sixel>chafa>half; reel video auto = sixel>kitty>iterm2>… — respect each lib's ordering; grids/overlays force half-block when tiling.
- Flux/Qwen-Image/SD3.5/LTX/Wan are **not** stock-A1111 models — treat backend dialect as unknown until Phase 0.

### 8.4 Open questions for the user

1. skynet2:30001 refused us — is it an IP allowlist? Can you paste `/model_info` output (or run it from here once allowed)? It decides the Phase-1 dialect (stock A1111? ComfyUI-backed gateway? custom?).
2. Is a mask *editor* (drawing inpaint masks in the terminal with mouse) in scope, or is upload-a-mask-file sufficient for v1?
3. Multiple named backends (`sdBackends` map) vs one active backend + `/model`-style switcher?
4. Should generated media auto-save to a workspace dir (`.sugar-crush/media/`) or only to `~/.sugar-crush/media/`?

---

*End of combined report. Per-unit verbatim findings are reflected in §§1–6; the A1111 source checkout remains at `/tmp/stable-diffusion-webui` for follow-up greps.*
