# Media generation

CandyCode can generate images and video against a diffusion server you
configure — a Stable Diffusion WebUI (A1111) fork, an SD.Next box, or an
sglang-diffusion deployment. Nothing about the feature is on by default: it
speaks only to the single origin `sd.baseUrl` names, it refuses requests that
origin cannot serve *before* dialing, and every artifact it stores carries the
server's parameters with it, so a saved PNG can be fed back into the form and
regenerated.

There are three doors into the same pipeline: the TUI (`/generate`, the
`Ctrl+D` form, `/play`, `/rendermode`, `/sendto`), the model's own tools
(`GenerateImage`, `GenerateVideo`), and the protocol server
(`generate.submit`, `generate.status`, `media.fetch`) that `candy-code serve`
and the web UI ride.

---

## The dialect model

Every server belongs to an endpoint family, and CandyCode implements the
wire shape for some of them. A request whose family has no adapter — or whose
kind a speakable family cannot serve — is refused at the door with a named
reason, never sent and never silently downgraded.

| Family | Wire string | Status |
|---|---|---|
| A1111 / SD.Next | `sdapi` | implemented — images (txt2img, img2img, inpaint, hires, refiner, scripts) and nothing else |
| sglang-diffusion | `sglang-diffusion` | implemented — images (JSON generations, multipart edits) and video (`/v1/videos`) |
| ComfyUI | `comfyui` | detectable, deliberately unimplemented |
| OpenAI-style images | `openai-images` | detectable, deliberately unimplemented |
| anything else | `custom` / `unknown` | detectable at best; no adapter |

So today: images speak both implemented dialects; video speaks only
`sglang-diffusion` — core A1111 has no video route, and asking an `sdapi`
server for video is a named refusal, not a dial. The `openai-images`-style
"just an images endpoint" servers are detected so the error can name them,
not so they work.

## Capability discovery

`CapabilityDiscoverer` probes the configured origin on a fail-open ladder of
cheap reads — command flags first, then `object_info`, `model_info`, `/v1/models`
— under a short per-request ceiling, and a probe that errors simply reports
less. The result merges into a declared capability from config with one law:
**configuration outranks the probe** (the merged record keeps a `mergedFrom`
marker saying which authority won). If you set `mediaFamily` or `mediaKinds`
yourself, the probe cannot contradict you.

## Configuration

The schema keys live in the *Media* category of
[`SETTINGS.md`](SETTINGS.md#every-key); this is their behaviour, not their
registry rows:

| Key | What it does |
|---|---|
| `sd.baseUrl` | The one origin the feature may ever contact. Unset means the whole feature refuses. |
| `sd.apiKey` | Sent as `Authorization` on every media request. Supports `${VAR}` and `${VAR:-default}` expansion at request time, so the secret can live in the environment instead of the settings file. A value containing a colon goes out as HTTP Basic (`user:pass`), otherwise as a Bearer token. |
| `sd.timeoutSeconds` | The per-request ceiling for image calls, and the poll budget's companion for video. |
| `sd.defaultModel` | Fills the form's model slot when the server reports none you picked; unset means "whatever the server has loaded". |
| `sd.presets` | Named parameter bundles (see below). |
| `sd.savePattern` | The artifact filename template — bracketed tokens taken from the server's parameters (seed, steps, cfg, sampler, model, dimensions…), never from your prompt text. |
| `ui.imageRenderMode` | Which terminal graphics protocol renders previews: `auto` or one of the words the mosaic ladder knows (kitty, iterm2, sixel, halfblock, quarterblock, ascii, chafa, symbols and their aliases). |
| `ui.mediaDisplayOverrides` | Per-field size caps and fallbacks for the form, keyed by element id (see below). |

Beyond the schema, three advanced keys are read raw from the provider-config
layers and deliberately have no settings row: `mediaFamily` (force a dialect),
`mediaKinds` (declare which kinds this server serves — it is what the video
tab's fail-closed gate reads), and `mediaBaseUrl` (an origin for the declared
kinds alone, falling back to `sd.baseUrl`).

The environment can set the origin and the render word; the live-smoke gate
variable for the real-server test is described in
[`ENVIRONMENT.md`](ENVIRONMENT.md).

## The TUI surface

**`/generate <prompt> [--steps N] [--cfg N] [--size WxH] [--seed N]
[--sampler NAME] [--scheduler NAME] [--negative TEXT] [--batch N]
[--n-iter N] [--save]`** runs one image from the keyboard. The flags map
onto the same input schema the tool and the RPC use — one dialect, no
command-only parameters.

**`Ctrl+D`** — and bare `/generate`, and the palette row, and the `F10`
**Tools ▸ Generate image…** menu row — open the
interactive generation form; the door is dead while another modal is up.
The form's tabs are gated fail-closed by the server's declared kinds: the
video tab appears only when `mediaKinds` names `video`. Engagement law: a
visible tab you never touched still contributes its draft identity to the
collect, while a closed tab contributes nothing at all.

Settling a run prints the artifact's stored path; images additionally paint a
preview row through the mosaic renderer. Previews are display-only — they
replace in place, clear on settle, and their bytes never enter the journal.
Video artifacts get a 🎞 notice row and a path row instead of an inline
frame.

**`/play <name|path>`** opens the full-screen player for a video
artifact or a local clip. It is a read-only command: nothing it does writes
state. Inside the player `m` cycles the graphics ladder live.

**`/rendermode [word]`** shows or switches the render word mid-session. It
persists through the settings writer, images repaint from the new word, and
the next `/play` starts from it; `CANDY_CODE_MEDIA_RENDER_MODE` still
re-asserts at launch and outranks the merged setting.

**`/sendto <file.png> [--to img2img|inpaint] [--mask FILE]`** imports a
saved PNG's parameters back into the form — optionally as an img2img or
inpaint starting point with a mask. It reads the file's own embedded
infotext first and only asks the server's `png-info` route when the local
chunk is absent (that route is an `sdapi` shape; a file from another dialect
needs its chunk). Remote URLs and data URIs are refused at the door: the
importer never fetches. The paste-into-form channel accepts raw infotext
text the same way, and the form's image slots take clipboard pastes and file
paths alike.

## Presets and display overrides

`sd.presets` is a map of named parameter bundles written atomically as a
whole map — the form's presets tab saves the current field values under a
name and hydrates them back in one motion. Hydration is all-or-nothing: a
bundle referencing a field that no longer exists is refused rather than
partially applied.

`ui.mediaDisplayOverrides` tunes how individual form fields render, keyed by
element id (`<section>/<Label>/<prop>`) with `value`, `minimum`, `maximum`,
`step` and `visible` props. Setting `visible: false` drops the row from the
render *and* from the collect, and ids that match nothing are ignored
silently — overrides outlive field renames without breaking anything.

## The infotext round-trip law

Every image the pipeline saves carries the server's infotext for it — prompt,
negative, seed, steps, CFG, sampler, model, size, and the denoising and mask
fields for conditional runs. Batch runs get per-artifact infotext: the server
prepends a grid image when enabled, its infotext sits first and each sample's
follows in order; the grid file takes your save pattern with a `-grid`
suffix. Saved output round-trips: `/sendto` (or the paste channel) lifts the
parameters out of the PNG's own chunk, so regeneration starts from exactly
what produced the picture. One exception rides that round-trip: a
server-sourced key shaped like a credential (`/api|key|token|secret/i`, modelled
fields like `Token merging ratio` excepted) is dropped — its row never reaches a
saved PNG, a hydrated request, or your screen.

## Video

Video is asynchronous. A submission mints a job, writes a `0600` ledger file
under the session's media root (`jobs/<jobId>.json`, mirrored as a JSON
sidecar beside the finished artifact), launches the render off the event
loop when process control allows, and answers with the job id. A poll loop
bounded by heartbeats follows the server's task, artifacts land in the media
store, and the ledger settles into `done`, `failed` or `cancelled`.

The frame-count law: total frames must be one more than a multiple of the
temporal stride, and a family with a recorded ladder snaps your request *up*
to the nearest rung — with a notice saying so — and refuses requests above
its top rung rather than clamping them. A family with no recorded ladder
gets the bare stride law and no ceiling.

Sound: v1 is silent by ruling. CandyCode wires no audio surface to the
player. Generated videos carry no audio track, so they are inherently quiet;
playing a *user's own* clip that has audio does sound, because the video
library auto-plays audio for sources that carry it when an external player
is present — that is accepted library behaviour, with operator enforcement
left open.

## Voice input (C-TUI-6.l)

Microphone dictation rides the same media module as image and video: a local
recorder child captures a WAV take, and an OpenAI-compatible
`/audio/transcriptions` endpoint turns it into text. This section covers the
core; the TUI chord is documented below, and the web surface still
arrives with its own step.

**Configuration** (`voice.*`, all user tier, all listed in the layered
roster above): `voice.baseUrl` is the endpoint and there is **no default** —
voice stays off until a user-tier setting names one, so audio never leaves
the machine by surprise. `voice.apiKey` follows the `${VAR}` house posture
(`OPENAI_API_KEY` by default spelling; an unset variable sends no header).
`voice.model` defaults to `whisper-1`, `voice.language` is optional (the
endpoint detects otherwise), and `voice.maxSeconds` caps every take at
5–600 seconds (default 120). A project tier can set none of these; that is
the same trust boundary `sd.baseUrl` enforces, guarded by
`LayeredSettingsTest`.

**Recording.** The recorder picks `arecord`, then `sox`, from PATH — and when
neither exists (stock macOS and Windows included) it reports
`no recorder binary was found` rather than faking a take. The cap is passed
into the child's own argv (`-d <max>` / `trim 0 <max>`) so an orphaned
recorder stops itself; stopping sends SIGINT, which finalizes the WAV
header, followed by a bounded TERM/KILL ladder. Takes land at 0600 under
`~/.candy-code/cache/voice/` inside the PrivateDir-verified 0700 root. A
process that exits with a live take cancels it.

**Egress posture.** The upload is one multipart POST — `file`, `model`, and
optional `language` — to `rtrim(voice.baseUrl,'/') . '/audio/transcriptions'`
(a trailing `/v1` is kept, deliberately not Guzzle `base_uri` resolution).
Requests are connect-bound and stall-bound, never total-timeout-bound
(E646: a slow-but-live endpoint is allowed to answer). The WAV is deleted
after the call, on success and on every failure path; audio bytes are never
written to a log, and the recorder's own stdout/stderr go to `/dev/null`.
Failure sentences are built from status codes and sanitized endpoint text
only — the API key is never part of a printable string.

**Push-to-talk.** `Alt+V` (`chat.voice`) toggles one take: the
first press arms the microphone and the status bar shows the static cue
`● rec · Alt+V stop · Esc cancel`; the same chord again stops the take and
uploads it; `Esc` while recording throws the take away (recorder killed, WAV
deleted, nothing transcribed). Terminals deliver no key-up, so push-to-talk
can only be a toggle. The transcript is **inserted at the caret, never
sent** — it is draft material you read and edit before pressing Enter — and
a failed or empty take says so as a transcript notice or a toast rather
than swallowing the keystroke. With `voice.baseUrl` unset the chord answers
with the same "set `voice.baseUrl`" refusal sentence; there is no silent
no-op. `Ctrl+V` stays `chat.paste-image`. On macOS the Option key may
deliver `√` where the chord expects `Alt+V` — re-chord
`chat.voice` through the `keybindings` setting, which the alias funnel
honours for this row like any other.

## Server and web surface

Over `candy-code serve` (see [`SERVER.md`](SERVER.md)):

| Method | Shape |
|---|---|
| `generate.submit` | Starts an **image** or **video** generation — `kind` selects the tool door (default `image`; the video half was the TUI-only side until the web Generate panel wired it here). Each kind forwards only its own tool's key set, the image door still naming the twelve shared-request fields; the answer is `{jobId}`. Replays are idempotent: a repeated submission returns the same job. |
| `generate.status` | A job's `{state, progress01}`, plus artifact refs when done and an error when failed. The schema declares an ETA field that v1 never sends. |
| `media.fetch` | A stored artifact's bytes as base64 slices — by artifact id or by path, never both, default slice with a hard ceiling, and an `eof` flag. |
| `audio.transcribe` | Starts a **voice** transcription from a recorded take — `audioBase64`, a `mime` from the declared recording shapes (`audio/webm`, `audio/ogg`, `audio/mp4`), and an optional `language`. The decoded bytes are checked against a ceiling before the take is filed, and the dial goes to the configured voice endpoint; the request itself cannot name a URL. The answer is `{jobId}`. Replays are idempotent: a repeated submission returns the same job. |
| `audio.status` | A transcription job's `{state}`, with `text` when done and an `error` when failed. Error sentences ride the transport's key-scrub, so a refused upstream answer never carries the API key back. |

Consent is the session's effective permission gate, evaluated at submit
time: a Deny answers `permission_denied`, and an Ask that nobody can answer
out-of-turn answers `permission_ask_unanswered` rather than treating silence
as consent — web clients normally run under a granted mode, so this is the
honest edge, not the common path.

`tool.finished` events carry a `media` array of refs — identity and metadata,
never a copy of the bytes in the journal — and the web UI fetches slices
through `media.fetch` to paint media cards. Every ref names the session
media store: a finished result that carries an image's bytes while naming a
path outside the store — a `Read` of a workspace image — has that copy
adopted into it under a deterministic `read-<sha256(id|path) prefix>.<ext>`
name (idempotent, so re-projecting the same row reuses the one copy), and a
path with no bytes to adopt projects no ref at all — no card renders that
cannot load. `hello` advertises `features.media` (`image`, `video`,
`transcribe`, and a truncated SHA-256 of the configured origin's host,
never the hostname) computed fail-open at boot; `transcribe` mirrors the
voice door — a configured `voice.baseUrl` — independent of the SD server.

## Attachment limits

The `@`-mention attachments the chat carries to the model have ceilings, and
each breach says so rather than silently halving what you named:

| Kind | Ceiling | On breach |
|---|---|---|
| Text file | 256 KiB | truncated at a line boundary, with a notice — a reader takes partial text |
| Image | 5 MiB | refused — half a bitmap decodes to nothing |
| PDF | 20 MiB | refused — half a PDF is unparseable |
| PDF pages | 100, estimated | refused; the estimate counts `/Type /Page` objects visible in the file's plain bytes, so a PDF that hides them in compressed object streams passes as unknown — the cap refuses on a positive count, never on silence |
| Documents per request | 30 MiB base64, summed across the session | the newest documents ride; older ones that no longer fit are withheld — their transcript row carries a one-line note for the model, and one notice per turn names them so you can re-attach what you need |

The last row is a request law, not a file law: every still-visible document
re-encodes on every later request, so a 15 MiB PDF paired with a 10 MiB one
would push *every* subsequent turn over the wire's 32 MiB per-request ceiling —
a refusal each send from then on. The budget converts that dead session into a
named, re-attachable withholding; providers that take no documents at all are
governed by the capability notice instead, and spend no budget.

### PDFs Read through the tool (C-TOOL-2)

Read has a second document door. When it opens a PDF it first asks the host
for a text extractor (`pdftotext -layout`, run through the same captured
subprocess helper the search tools use — no second spawn machinery), and when
the extractor answers, the result is a page of numbered extracted text under
the 262,144-byte text-page contract, paged with the ordinary `offset`/`limit`
arguments. That road carries no bytes at all: one road per read, never a text
page and a duplicate document in the same result.

When no extractor answers, the PDF itself rides back inside the tool result
— but only within `AnthropicMessagesWire::TOOL_RESULT_DOCUMENT_MAX_BYTES`
(1,048,576 bytes), an order of magnitude below the 20,971,520-byte
@-mention cap above. The asymmetry is a re-send law: a document-capable wire
replays the result block on every follow-up call of its turn and base64
inflates the bytes by a third on the way out, while a user attachment is a
one-shot named by the human and budgeted separately by the session's 30 MiB
base64 sum. Past the result budget the answer is the notice only — never
truncated bytes, because half a PDF is unparseable here exactly as it is for
attachments.

On the wire the carried bytes reach Anthropic-family models as a real
`document` block inside the `tool_result` row, re-sniffed for the `%PDF-`
magic before emission and spelled by the one shared `anthropicDocument()`
encoder, so a PDF entering through either door presents identically. The
OpenAI-style chat `role: tool` string stays the documented decline, the
Responses-API sibling carries the same bytes as an `input_file` part on its
tool row (C-TOOL-2.g), and Gemini/Bedrock carry no tool calls
to attach to at all.

### Canvas artifacts (C-TOOL-6.f)

The `Canvas` tool writes a model-authored, self-contained HTML page into the
same session store the generated images land in — `~/.candy-code/media/` under
the bound session's folder (the `main` folder when nothing is bound), mode
`0600`, temp-then-rename through `MediaStore::putDocument()` — and answers
with the artifact id, the absolute path and the byte count. The cap is 512
KiB and a breach refuses rather than truncating. The store door is the only
writer, so nothing lands outside the session directory, and the age sweep of
session directories retires the artifacts exactly as it retires generated
media. The bytes are not sanitised at the store door: the TUI never renders
the page (the result carries no media legs, so nothing projects). The web
client does **not** show these artifacts yet — the projection arm, the
sandboxed `/canvas/…` page and the card are the later web/protocol half of
C-TOOL-6; until then the artifact is visible only as the path in the result.
A `media.fetch` of such an id answers today with the projector's default arm
(`kind: image`, `application/octet-stream`) — harmless while no event
advertises the id, corrected by that later half.

## Security posture

Media egress is pinned to the configured origin rather than run through the
WebFetch SSRF blocklist: a home-lab SD server on a LAN address is the
point of the feature, and supplying `sd.baseUrl` *is* the trust decision —
the residual (a DNS rebinding of that hostname would be dialed at request
time) is accepted and documented for the same reason. Artifacts live under
`~/.candy-code/media/` with `0700` directories and `0600` files, written
temp-then-rename; filenames derive from server parameter tokens, never from
prompt text. Secret headers are scrubbed from anything that reaches logs or
transcripts, and raw media bytes never enter the journal. Connected RPC
clients receive absolute home paths in media refs — accepted for the
single-owner server model. Starting images given as URLs are fetched by the
*SD server*, not by CandyCode, relocating that SSRF surface to the machine
you already trust to generate; and because the origin is trusted, response
bodies are read fully into memory.

The serve-mode audio pair is pinned the same way: `audio.transcribe` dials
the voice endpoint named in user settings and nothing else — the wire
carries audio, a format, and an optional language, never a URL. The mime
is checked against the declared allowlist, and a take that decodes over
eight MiB is refused before a single byte is filed (its base64 form is
itself bounded under the frame ceiling the transport verifies first). The
sentence that lands in the transcription ledger passes the transport's
key-scrub, so a failed job cannot carry the API key back onto the wire.
With `voice.baseUrl` unset both audio doors answer `voice_unconfigured`:
nothing is dialed, nothing is filed, and no ledger exists to poll.

## See also

- [`SETTINGS.md`](SETTINGS.md) — the *Media* rows in the key registry.
- [`ENVIRONMENT.md`](ENVIRONMENT.md) — `CANDY_CODE_SD_BASE_URL`,
  `CANDY_CODE_MEDIA_RENDER_MODE`, the live-smoke gate variable.
- [`PERMISSIONS.md`](PERMISSIONS.md) — how the `Ask` on the generate tools
  and the out-of-turn consent edge behave.
- [`COMMANDS.md`](COMMANDS.md) — `/generate`, `/generate-form`, `/sendto`,
  `/play`, `/rendermode`.
- [`SERVER.md`](SERVER.md) — the RPC method roster and the web UI.
- [`ARCHITECTURE.md`](ARCHITECTURE.md) — where the renderers, form and job
  clients sit in the program.
- The [README](../README.md#documentation-index) — every other page.
