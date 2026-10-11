<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Events\MediaProgress;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\MediaJob;
use SugarCraft\Crush\Media\MediaJobStatus;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransport;
use SugarCraft\Crush\Media\Video\FrameLadder;
use SugarCraft\Crush\Media\Video\JobClient;
use SugarCraft\Crush\Media\Video\JobPollLoop;
use SugarCraft\Crush\Media\Video\JobPollSummary;
use SugarCraft\Crush\Media\Video\JobSnapshot;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Support\MediaStore;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\TakesToolCallId;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Render a short video from a text prompt against a job-capable media server
 * — the video sibling of {@see GenerateImage} (plan_crush_media W5.3).
 *
 * SHAPE. The prompt and the video knobs of plan §6.2 arrive as tool args,
 * `num_frames` is snapped UP the {@see FrameLadder} before anything leaves
 * the process (the §6.2 snap-to-ladder law; the informational diff rides the
 * result text), and the bundle goes to {@see JobClient::submit()} as opaque
 * params — the client forwards what it is given, per the mystage §7 field
 * rows. The wait is the async job topology the image tool could only dream
 * of: {@see JobPollLoop} beats the idle watchdog once per status poll at the
 * 1 s video cadence (fact (a) generalized; no wall-clock bound, E646), the
 * per-poll snapshot lands in the turn's event channel as a previewless
 * {@see MediaProgress} frame (progress/eta readout; video contracts record no
 * preview image), and a finished job's result reference becomes a file via
 * {@see JobClient::fetch()} — base64 bytes hit {@see MediaStore} first and
 * only the PATH continues downstream (fact (f); nothing here ever holds a
 * video blob for the renderer). A JSON sidecar (`<name>.json`, decision C-6)
 * is written next to the artifact through the same store, carrying the
 * request params, the ladder note and the {@see MediaJob} ledger — and no
 * secrets: credentials never enter the params map, and
 * {@see MediaArtifact::toArray()} omits bytes by construction.
 *
 * CAPABILITY GATE FIRST (C-1, video reading). Before any dial: a configured
 * `mediaFamily` whose dialect has NO recorded video job contract
 * ({@see JobClient::videoContract()}) is refused by name — that is the video
 * truth of "implemented", and it refuses `sdapi` for video even though sdapi
 * is implemented for images; an absent declaration takes the client's
 * fixture-era default (the one contract recorded off a live server, W0.1);
 * a `mediaKinds` roster naming kinds without `video` is refused; an
 * unresolvable base URL is refused by {@see JobClient::configured()} before
 * it dials. All produce plain error results and ZERO requests. The W5-era
 * LOUD RESIDUAL — this client posting JSON while the live sglang front takes
 * a multipart form — is CLOSED: the W7 adapter posts form fields
 * ({@see JobClient::submit}), and the param names arrive in the contract's
 * own vocabulary (num_inference_steps, guidance_scale(_2), input_reference,
 * size), so the wire shape matches the W0 openapi record.
 *
 * CANCELLATION. Like the image sibling, a call cancelled while queued never
 * dials. Unlike its synchronous sibling, this wire IS interruptible
 * mid-render: every poll checks {@see ToolCancelRequests}, and an abandoned
 * wait asks the server to cancel the job — best effort, with the failure
 * reported honestly (a dead transport usually eats the cancel too, and the
 * render keeps burning GPU on the server until it finishes there).
 *
 * PLAYBACK IS NOT HERE. A tool result cannot host a Player (§5.2 hazard): the
 * result is a path row plus a `🎞` notice; the full-screen player arrives
 * with the W5.4 command surface.
 *
 * PERMISSION CLASS: ask. Same reasoning as the image tool, with longer,
 * pricier renders on the host's GPU.
 */
#[BuiltInTool(name: 'GenerateVideo', permission: ToolPermissionClass::Ask, position: 34, gloss: 'render a short video from a text prompt on the configured video job server')]
final readonly class GenerateVideo implements Tool, BuildsFromCatalog, DelegatesToEngine, AcceptsHeartbeat, TakesToolCallId
{
    use TruncatesOutput;

    public const NAME = 'GenerateVideo';

    /** Args that are this tool's own machinery, never forwarded to the server. */
    private const MACHINERY_ARGS = ['id', 'prompt', 'duration_seconds'];

    /** Session folder for MediaStore when the bound engine has no session id. */
    private const FALLBACK_SESSION = 'main';

    /**
     * Every non-null field below is a test seam overriding live behaviour,
     * exactly as in {@see GenerateImage}: the transport/base-URL/settings trio
     * replaces the configured dial, `$mediaRoot` relocates the artifact tree,
     * and the pacing pair keeps the job-poll loop off real time. Production
     * builds carry only nulls.
     */
    private function __construct(
        private ?EngineBackend $engine = null,
        private ?SdTransport $transport = null,
        private ?string $baseUrl = null,
        private ?array $settings = null,
        private ?string $mediaRoot = null,
        private ?float $pollIntervalSeconds = null,
        private ?\Closure $pollSleeper = null,
        // Production wiring, not a test seam: the turn's tool-event channel
        // (withEngine's emitter), fed a previewless MediaProgress per poll.
        private ?\Closure $eventEmitter = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self();
    }

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self(
            $engine,
            $this->transport,
            $this->baseUrl,
            $this->settings,
            $this->mediaRoot,
            $this->pollIntervalSeconds,
            $this->pollSleeper,
            $subAgentEmitter,
        );
    }

    /** The same tool dialling through $transport at $base (a test seam). */
    public function withFakeDial(SdTransport $transport, ?string $base = null, array $settings = []): self
    {
        return new self(
            $this->engine,
            $transport,
            $base,
            $settings,
            $this->mediaRoot,
            $this->pollIntervalSeconds,
            $this->pollSleeper,
            $this->eventEmitter,
        );
    }

    /** The same tool saving artifacts under $root instead of the home tree. */
    public function withMediaRoot(?string $root): self
    {
        return new self(
            $this->engine,
            $this->transport,
            $this->baseUrl,
            $this->settings,
            $root,
            $this->pollIntervalSeconds,
            $this->pollSleeper,
            $this->eventEmitter,
        );
    }

    /** The same tool pacing its job-poll loop without real sleeps. */
    public function withLoopPacing(?float $intervalSeconds, ?\Closure $sleeper): self
    {
        return new self(
            $this->engine,
            $this->transport,
            $this->baseUrl,
            $this->settings,
            $this->mediaRoot,
            $intervalSeconds,
            $sleeper,
            $this->eventEmitter,
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Render a short video from a text prompt using the video job server this launch is'
            . ' configured for. `prompt` is required; `num_frames` snaps UP to the model\'s frame'
            . ' ladder (8k+1 rungs such as 81/121/193/257 — the result says when it moved), or pass'
            . ' `duration_seconds` with `fps` and the frames are computed for you. `fps`, `width` and'
            . ' `height` (480p/720p class), `steps`, `cfg` and `cfg2` (dual guidance when the dialect'
            . ' has it), `seed` (-1 for a fresh draw), `motion` (0-1 movement strength) and'
            . ' `first_frame_image` (image-to-video: first frame only in v1) are forwarded when set,'
            . ' and any other knob rides through untouched. The render is an async job polled once per'
            . ' second until the server reports it finished; the mp4 is saved to the session media'
            . ' store with a JSON sidecar of the request, and the paths come back in the result —'
            . ' playback happens in the video player, not here. Cancelling the call stops the wait and'
            . ' asks the server to cancel the job.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'What to render, self-contained: style, subject, motion, framing.'
                        . ' Required, non-empty',
                ],
                'negative_prompt' => [
                    'type' => 'string',
                    'description' => 'What to steer away from, in the same comma-clause style',
                ],
                'num_frames' => [
                    'type' => 'integer',
                    'description' => 'Frames to generate; snapped up to the nearest ladder rung'
                        . ' (81/121/193/257 class) when it is not already one',
                ],
                'duration_seconds' => [
                    'type' => 'number',
                    'description' => 'Sugar for num_frames = round(duration_seconds x fps); needs fps;'
                        . ' pass either this or num_frames, not both',
                ],
                'fps' => [
                    'type' => 'integer',
                    'description' => 'Frames per second (Wan 16/24, LTX 24-30 are the recorded typicals)',
                ],
                'width' => ['type' => 'integer', 'description' => 'Frame width in pixels (480p/720p class values)'],
                'height' => ['type' => 'integer', 'description' => 'Frame height in pixels (480p/720p class values)'],
                'steps' => ['type' => 'integer', 'description' => 'Sampling steps — more is slower, past a point indistinguishable'],
                'cfg' => ['type' => 'number', 'description' => 'Prompt adherence; high values burn in artefacts'],
                'cfg2' => ['type' => 'number', 'description' => 'Second guidance scale for dual-CFG dialects (ignored otherwise)'],
                'seed' => ['type' => 'integer', 'description' => 'Exact seed to reproduce a prior render, or -1 for a fresh draw'],
                'motion' => ['type' => 'number', 'description' => 'Movement strength 0-1; carried as an opaque field — dialects with a motion mechanism map it, sglang Wan exposes none and ignores it'],
                'first_frame_image' => [
                    'type' => 'string',
                    'description' => 'Image-to-video seed: path to the first frame (v1 supports first-frame only)',
                ],
            ],
            'required' => ['prompt'],
            // Unknown knobs ride the submit body verbatim (opaque-params law)
            // — an open object, truthfully declared so.
            'additionalProperties' => new \stdClass(),
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->run($args, null);
    }

    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->run($args, $heartbeat);
    }

    private function run(array $args, ?\Closure $heartbeat): ToolResult
    {
        $callId = (string) ($args['id'] ?? '');
        try {
            return $this->generate($args, $heartbeat, $callId);
        } catch (\Throwable $e) {
            return new ToolResult($callId, 'video generation failed: ' . $e->getMessage(), true);
        }
    }

    private function generate(array $args, ?\Closure $heartbeat, string $callId): ToolResult
    {
        $settings = $this->settings ?? $this->liveSettings();

        $refusal = $this->capabilityRefusal($settings);
        if ($refusal !== null) {
            return new ToolResult($callId, $refusal, true);
        }

        $prompt = \is_string($args['prompt'] ?? null) ? trim($args['prompt']) : '';
        if ($prompt === '') {
            return new ToolResult($callId, 'GenerateVideo needs a non-empty `prompt`', true);
        }

        // Undeclared family = the client's default; resolving once here keeps
        // the ladder and the transport reading the same truth.
        $family = $this->declaredFamily($settings) ?? JobClient::DEFAULT_FAMILY;
        $request = $this->requestFrom($args, $prompt, $family, $callId);
        if ($request instanceof ToolResult) {
            return $request; // arg-shape refusal surfaced verbatim
        }
        [$params, $ladder] = $request;

        $client = $this->client($settings, $family, $callId);
        if ($client instanceof ToolResult) {
            return $client; // unconfigured host — nothing has been dialled
        }

        // Queued-then-cancelled calls never reach the wire (mirror of the
        // image tool's zero-dial promise).
        if ($callId !== '' && ToolCancelRequests::isRequested($callId)) {
            return new ToolResult($callId, ToolCancelRequests::CANCELLED, true);
        }

        $taskId = $client->submit($params);
        $job = MediaJob::new(MediaKind::Video)->withServerTaskId($taskId);

        $waited = $this->awaitJob($client, $taskId, $heartbeat, $callId, $job);
        if ($waited instanceof ToolResult) {
            return $waited; // cancelled, failed on the server, or died waiting
        }
        [$summary, $job] = $waited;

        $snapshot = $summary->last;
        \assert($snapshot instanceof JobSnapshot); // a server-finished wait carries its last readout

        $stored = $this->collect($client, $taskId, $snapshot, $params, $ladder, $job, $callId);
        if ($stored instanceof ToolResult) {
            return $stored; // fetch-side failure (unparseable result, refused URL)
        }

        return $stored;
    }

    /**
     * The heartbeat-bracketed wait. Returns [JobPollSummary, MediaJob] once
     * the server reports a terminal state, or a ToolResult for every way the
     * render can end without an artifact.
     *
     * @return array{0: JobPollSummary, 1: MediaJob}|ToolResult
     */
    private function awaitJob(JobClient $client, string $taskId, ?\Closure $heartbeat, string $callId, MediaJob $job): array|ToolResult
    {
        $beat = $heartbeat ?? static function (): void {
        };
        $isCancelled = static fn (): bool => $callId !== '' && ToolCancelRequests::isRequested($callId);

        $onFrame = function (JobSnapshot $snapshot) use (&$job, $callId): void {
            if ($snapshot->state === MediaJobStatus::Running && $job->status() === MediaJobStatus::Queued) {
                $job = $job->withStatus(MediaJobStatus::Running);
            }
            $job = $job->withProgressFrame([
                'status' => $snapshot->state->value,
                'progress' => $snapshot->progress,
                'eta' => $snapshot->etaSeconds,
            ]);
            // The video contract records no preview image: the frame carries
            // the progress/eta readout only (frameB64 stays null by law).
            if ($this->eventEmitter !== null && $callId !== '') {
                ($this->eventEmitter)(new MediaProgress($callId, null, $snapshot->progress, $snapshot->etaSeconds));
            }
        };

        try {
            $summary = JobPollLoop::run(
                $client,
                $taskId,
                $beat,
                $isCancelled,
                $this->pollIntervalSeconds ?? JobClient::DEFAULT_POLL_INTERVAL_SECONDS,
                $this->pollSleeper,
                $onFrame,
            );
        } catch (SdException $e) {
            // The readout died mid-flight. Best-effort: stop burning GPU on a
            // job we can no longer watch, then fail loudly either way.
            $this->bestEffortCancel($client, $taskId);

            throw $e;
        }

        if ($summary->termination === JobPollSummary::TERMINATION_DONE) {
            // Cancelled between polls: the loop stopped, the server must too.
            $notified = $this->bestEffortCancel($client, $taskId);
            $job = $job->withStatus(MediaJobStatus::Cancelled);

            return new ToolResult(
                $callId,
                'the video render was interrupted before the job finished; '
                    . ($notified
                        ? 'a cancel was sent to the server'
                        : 'the server could not be notified, so the render may still be running there'),
                true,
            );
        }

        $snapshot = $summary->last;
        if (!$snapshot instanceof JobSnapshot) {
            return new ToolResult($callId, 'the video job finished with no status readout at all', true);
        }

        if ($snapshot->state === MediaJobStatus::Failed) {
            $job = $job->withStatus(MediaJobStatus::Failed)->withError((string) ($snapshot->serverMessage ?? 'no server message'));

            return new ToolResult(
                $callId,
                'the video job failed on the server: ' . (string) ($snapshot->serverMessage ?? 'no server message'),
                true,
            );
        }

        if ($snapshot->state === MediaJobStatus::Cancelled) {
            $job = $job->withStatus(MediaJobStatus::Cancelled);

            return new ToolResult($callId, 'the video job was cancelled on the server before it finished', true);
        }

        // A fast server can report Done on the very first readout, so the
        // queued->done edge the state machine forbids has to pass through
        // running here — collect() lands the final Done.
        if ($job->status() === MediaJobStatus::Queued) {
            $job = $job->withStatus(MediaJobStatus::Running);
        }

        return [$summary, $job];
    }

    /**
     * Decode-free ingest: fetch through the W5.2 client (fact (f) — bytes
     * land in MediaStore, only the path continues), then publish the C-6 JSON
     * sidecar next to the artifact under the SAME name body.
     */
    private function collect(JobClient $client, string $taskId, JobSnapshot $snapshot, array $params, ?FrameLadder $ladder, MediaJob $job, string $callId): ToolResult
    {
        $session = $this->engine?->sessionId() ?? self::FALLBACK_SESSION;
        $store = MediaStore::forSession($session, $this->mediaRoot);

        $tokens = [];
        // Only numeric-usable values ride the save-name palette; a junk
        // opaque-param value (say seed:"abc") must not kill a finished render
        // at the naming door — the token is simply omitted then.
        foreach (['seed', 'steps', 'width', 'height', 'cfg'] as $token) {
            $value = $params[$token] ?? null;
            if (\is_int($value) || \is_float($value)) {
                $tokens[$token] = $value;
            }
        }

        $saved = $client->fetch($taskId, $snapshot, $store, $tokens);
        $job = $job->withStatus(MediaJobStatus::Done)->withArtifactPath((string) $saved->path());

        // Adjacency law: re-putting the SAME token values (number included)
        // under a different extension resolves the same name body, and
        // resolveTarget only ticks the collision number onto names that are
        // already taken — so <name>.json lands beside <name>.mp4.
        $sidecarTokens = $tokens;
        $sidecarTokens['number'] = $saved->tokenValues()['number'] ?? 0;
        $sidecar = $store->put(
            (string) json_encode([
                'kind' => 'video',
                'params' => $params,
                'frames_requested' => $ladder?->requested,
                'frames' => $ladder?->frames,
                'ladder_note' => $ladder?->note,
                'job' => $job->toArray(),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'json',
            $sidecarTokens,
            MediaKind::Video,
        );

        $lines = ['video: ' . (string) $saved->path(), 'sidecar: ' . (string) $sidecar->path()];
        $spec = [];
        if ($ladder !== null) {
            $spec[] = $ladder->frames . ' frames';
        }
        if (\array_key_exists('fps', $params)) {
            $spec[] = $params['fps'] . ' fps';
        }
        if ($spec !== []) {
            $lines[] = 'spec: ' . implode(' @ ', $spec);
        }
        if ($ladder?->note !== null) {
            $lines[] = $ladder->note;
        }

        return new ToolResult(
            toolCallId: $callId,
            content: "Generated a video on the media server:\n" . implode("\n", $lines)
                . "\n\u{1F39E} the artifact is saved in the session media store — open it with the video player",
        );
    }

    /**
     * C-1, video reading: the family gate is the recorded VIDEO CONTRACT
     * ({@see JobClient::videoContract()}), not the image-era
     * `isImplemented()` — sdapi is an implemented IMAGE family that must be
     * refused for video, and the one family with a video contract is not in
     * the image set. An absent declaration takes the client's default; a
     * family that merely fails to parse is not this gate's business
     * (discovery diagnoses it). Zero dials on every refusal path.
     *
     * @param array<array-key, mixed> $settings
     */
    private function capabilityRefusal(array $settings): ?string
    {
        $familyRaw = $settings['mediaFamily'] ?? null;
        if (\is_string($familyRaw) && trim($familyRaw) !== '') {
            $family = EndpointFamily::tryFrom(trim($familyRaw));
            if ($family !== null && JobClient::videoContract($family) === null) {
                return 'the media endpoint family "' . $familyRaw . '" has no video job contract; video renders'
                    . ' need a job-capable family, so no request was sent';
            }
        }

        // An absent mediaKinds roster means "everything the host can do" —
        // the config-authority fail-open from W1.8. A present roster that
        // names kinds without naming video means video generation is off.
        if (\array_key_exists('mediaKinds', $settings)) {
            $kinds = ProviderFactory::configuredMediaKinds($settings['mediaKinds']);
            if ($kinds !== [] && !\in_array('video', $kinds, true)) {
                return 'this launch is configured for media kinds (' . implode(', ', $kinds)
                    . ') that do not include video, so no request was sent';
            }
        }

        return null;
    }

    /**
     * Args -> opaque submit params, with the §6.2 frame law applied: fps must
     * be a positive integer when named, `duration_seconds` folds into frames
     * via fps, `num_frames` snaps UP the ladder (ceiling refusals included),
     * and every other knob rides through untouched.
     *
     * @param array<string, mixed> $args
     * @return array{0: array<string, mixed>, 1: ?FrameLadder}|ToolResult  ToolResult on an arg-shape refusal
     */
    private function requestFrom(array $args, string $prompt, EndpointFamily $family, string $callId): array|ToolResult
    {
        $params = ['prompt' => $prompt];
        foreach ($args as $key => $value) {
            if (!\is_string($key) || \in_array($key, self::MACHINERY_ARGS, true)) {
                continue;
            }
            $params[$key] = $value;
        }

        if (\array_key_exists('fps', $args) && (!\is_int($args['fps']) || $args['fps'] < 1)) {
            return new ToolResult($callId, 'GenerateVideo needs `fps` to be a positive integer', true);
        }
        if (\array_key_exists('first_frame_image', $args)
            && (!\is_string($args['first_frame_image']) || trim($args['first_frame_image']) === '')) {
            return new ToolResult($callId, 'the video request was refused: `first_frame_image` must be a non-empty path string', true);
        }

        $hasFrames = \array_key_exists('num_frames', $args);
        $hasSeconds = \array_key_exists('duration_seconds', $args);
        if ($hasFrames && $hasSeconds) {
            return new ToolResult(
                $callId,
                'pass either `num_frames` or `duration_seconds`, not both — the seconds sugar computes frames via fps',
                true,
            );
        }

        $ladder = null;
        if ($hasFrames) {
            if (!\is_int($args['num_frames']) || $args['num_frames'] < 1) {
                return new ToolResult($callId, 'GenerateVideo needs `num_frames` to be a positive integer', true);
            }
            $ladder = $this->snapOrRefusal($args['num_frames'], $family, $callId);
        } elseif ($hasSeconds) {
            $seconds = $args['duration_seconds'];
            if ((!\is_int($seconds) && !\is_float($seconds)) || $seconds <= 0) {
                return new ToolResult($callId, 'GenerateVideo needs `duration_seconds` to be a positive number', true);
            }
            if (!\array_key_exists('fps', $args)) {
                return new ToolResult($callId, '`duration_seconds` needs `fps` to turn seconds into frames', true);
            }
            $ladder = $this->snapOrRefusal((int) round($seconds * $args['fps']), $family, $callId);
        }
        if ($ladder instanceof ToolResult) {
            return $ladder;
        }
        if ($ladder !== null) {
            $params['num_frames'] = $ladder->frames;
        }

        return [$params, $ladder];
    }

    private function snapOrRefusal(int $requested, EndpointFamily $family, string $callId): FrameLadder|ToolResult
    {
        try {
            return FrameLadder::snap($requested, $family);
        } catch (\InvalidArgumentException $e) {
            return new ToolResult($callId, 'the video request was refused: ' . $e->getMessage(), true);
        }
    }

    /** @param array<array-key, mixed> $settings */
    private function declaredFamily(array $settings): ?EndpointFamily
    {
        $raw = $settings['mediaFamily'] ?? null;
        if (!\is_string($raw) || trim($raw) === '') {
            return null;
        }

        return EndpointFamily::tryFrom(trim($raw));
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return JobClient|ToolResult  ToolResult on the unconfigured-host refusal
     */
    private function client(array $settings, ?EndpointFamily $family, string $callId): JobClient|ToolResult
    {
        // Video auth: mediaApiKey first (the W1.8 media roster), sd.apiKey as
        // the image-era sibling fallback — a host with one credential uses it
        // for both surfaces.
        $apiKey = null;
        foreach (['mediaApiKey', 'sd.apiKey'] as $key) {
            if (\is_string($settings[$key] ?? null) && $settings[$key] !== '') {
                $apiKey = (string) $settings[$key];
                break;
            }
        }

        try {
            return JobClient::configured($this->transport, $this->baseUrl, $apiKey, $family);
        } catch (SdException $e) {
            // resolveBaseUrl answers from settings/env alone: this branch is
            // the unconfigured-host refusal and it has dialed nothing.
            return new ToolResult($callId, 'video generation is not available on this launch: ' . $e->getMessage(), true);
        }
    }

    /** True when the server took the cancel; false is reported, never hidden. */
    private function bestEffortCancel(JobClient $client, string $taskId): bool
    {
        try {
            $client->cancel($taskId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<array-key, mixed> */
    private function liveSettings(): array
    {
        try {
            return Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }
}
