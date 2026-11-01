<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Media\MediaJob;
use SugarCraft\Code\Media\MediaJobStatus;
use SugarCraft\Code\Media\MediaKind;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Voice\AudioRecording;
use SugarCraft\Code\Media\Voice\Transcriber;
use SugarCraft\Code\Media\Voice\VoiceConfig;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\GenerateImage;
use SugarCraft\Code\Tools\BuiltIn\GenerateVideo;
use SugarCraft\Code\Tools\Tool;

/**
 * `generate.submit`, `generate.status` and `media.fetch` (plan W6.2): the
 * serve-mode door a stateless client drives a whole generation through —
 * submit answers with a job id, status polls the on-disk job ledger, and
 * fetch slices the stored bytes. No new protocol event rides this lane (the
 * plan's v1 decision); progress is polled, exactly like `tool.output`
 * recovers a truncated body instead of a new push channel.
 *
 * The execution channel must leave the loop thread (§10.1-1), so submit
 * forks the render through {@see TurnController::forkPayload()} — the same
 * fork-and-poll shape the turn machinery uses — after writing the ledger in
 * its `running` state, and returns `{jobId}` without waiting. A build
 * without pcntl falls back to the one synchronous render TurnController's
 * `$inline` door already prescribes (mirroring the `command.exec` precedent
 * of `/generate`, where the render always blocked its own turn).
 *
 * Consent mirrors the tool's own door: both `GenerateImage` and
 * `GenerateVideo` are Ask-class tools, and submit runs the SAME
 * {@see PermissionGate} the in-turn tool path consults, evaluated against
 * the kind's own tool name. A Deny refuses; an unanswered Ask cannot be
 * relayed out of turn (the ask-relay surface was descoped in W6.6), so
 * submit refuses loudly with `permission_ask_unanswered` rather than bypass
 * it — clients drive generations under a mode or grant that answers Ask
 * affirmatively.
 *
 * The ledger is one JSON file per job at
 * `<session media folder>/jobs/<jobId>.json`, written 0600 with the store's
 * own temp-then-rename publish shape. MediaStore's listings scandir with an
 * `is_file` filter, so that `jobs/` directory is invisible to artifact ids —
 * job records never masquerade as fetchable artifacts.
 *
 * The `audio.*` pair (C-TUI-6.n) rides the same fork-and-poll shape for a
 * different medium: a client-uploaded take is capped on its DECODED bytes,
 * filed 0600 as a hidden sibling of the render ledgers under its own
 * `audio-` name prefix, and handed to the voice transport — the endpoint
 * comes from server settings only, never from the wire, and while voice is
 * unconfigured BOTH doors answer `voice_unconfigured`. A transcription
 * ledger row is `{state, mime, started_at, finished_at}` plus `text` when
 * done or `error` when failed; `audio.status` projects exactly the wire
 * shape `{state, text?, error?}` from it.
 */
final class MediaMethods
{
    /** Default slice for `media.fetch` when the client names no limit. */
    public const FETCH_DEFAULT_BYTES = 524_288;

    /** Hard ceiling on one `media.fetch` slice, matching the schema bound. */
    public const FETCH_MAX_BYTES = 4_194_304;

    /** Ledger directory inside the session's media folder. */
    public const LEDGER_DIR = 'jobs';

    /** MediaJob mints 16 lowercase hex chars ({@see MediaJob::new()}). */
    public const JOB_ID_PATTERN = '/^[0-9a-f]{16}$/';

    /**
     * The `generate.submit` video door's key set (C-WEB-3.f), in
     * GenerateVideo's own inputSchema order: the seven names only the video
     * tool speaks plus the six shared with the image vocabulary. Used as an
     * intersect map, so `kind` and the transport fields never reach the
     * tool, exactly as the image path intersects paramSchema().
     *
     * @var array<string, true>
     */
    private const VIDEO_ARG_KEYS = [
        'prompt' => true,
        'negative_prompt' => true,
        'num_frames' => true,
        'duration_seconds' => true,
        'fps' => true,
        'width' => true,
        'height' => true,
        'steps' => true,
        'cfg' => true,
        'cfg2' => true,
        'seed' => true,
        'motion' => true,
        'first_frame_image' => true,
    ];

    /** Longest error text a ledger keeps from a failed render. */
    private const ERROR_CLIP_BYTES = 2048;

    /**
     * Decoded-bytes ceiling for one `audio.transcribe` take (C-TUI-6.n).
     * Eight MiB of compressed speech is hours of dictation, so the cap is
     * flood-headroom, not a plausible-take limit; the inbound WS frame
     * ceiling ({@see \SugarCraft\Code\Server\ServerConfig::MAX_CLIENT_FRAME_BYTES},
     * 16 MiB) bounds the whole body before this handler ever runs.
     */
    public const TRANSCRIBE_MAX_AUDIO_BYTES = 8_388_608;

    /**
     * The base64 form of {@see TRANSCRIBE_MAX_AUDIO_BYTES} — ceil(bytes / 3)
     * times 4. The param is refused over this length at param-reading time,
     * so an oversized body never allocates a decode.
     */
    public const TRANSCRIBE_MAX_BASE64_BYTES = 11_184_812;

    /**
     * The declared mime allowlist for `audio.transcribe`, mapped to the
     * extension a take is filed under: the web recorder's ladder (opus in
     * webm first, then ogg, then mp4) and nothing else. The check runs
     * BEFORE the take touches the filesystem.
     *
     * @var array<string, string>
     */
    public const TRANSCRIBE_MIME_EXTENSIONS = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'mp4',
    ];

    /** A transcription job whose transport has not answered yet. */
    public const AUDIO_STATE_RUNNING = 'running';

    /** A transcription job whose ledger carries the text. */
    public const AUDIO_STATE_DONE = 'done';

    /** A transcription job whose ledger carries the failure sentence. */
    public const AUDIO_STATE_FAILED = 'failed';

    /**
     * The `audio.status` state enum, derived from the constants above so
     * schema and ledger states cannot drift.
     *
     * @var list<string>
     */
    public const AUDIO_STATES = [self::AUDIO_STATE_RUNNING, self::AUDIO_STATE_DONE, self::AUDIO_STATE_FAILED];

    /**
     * Ledger name prefix separating transcription rows from render rows in
     * the shared `jobs/` directory.
     */
    private const AUDIO_LEDGER_PREFIX = 'audio-';

    /**
     * Test seams only (see {@see self::setToolFactoryForTesting()}): the
     * render tool, the consent gate override, and a switch that runs the
     * render inline so a suite can pin ledger shapes deterministically.
     */
    private static ?\Closure $toolFactory = null;

    private static ?PermissionGate $gateOverride = null;

    private static bool $directRun = false;

    /**
     * Transcriber builder seam (C-TUI-6.n): production builds a bare
     * {@see Transcriber}; a suite injects a stub answering without dialling,
     * or a real one over a canned handler.
     */
    private static ?\Closure $transcriberFactory = null;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(
            'generate.submit',
            Scope::Write,
            'Start an image or video generation; answers with its job id.',
            self::submit(...),
            true,
        ));
        $registry->add(MethodSpec::new(
            'generate.status',
            Scope::Read,
            'A generation job\'s state, progress and artifact refs, from its ledger.',
            self::status(...),
        ));
        $registry->add(MethodSpec::new(
            'media.fetch',
            Scope::Read,
            'A stored media artifact\'s bytes, in base64 slices.',
            self::fetch(...),
        ));
        $registry->add(MethodSpec::new(
            'audio.transcribe',
            Scope::Write,
            'Transcribe a base64 audio take through the configured voice endpoint; answers with its job id.',
            self::transcribe(...),
            true,
        ));
        $registry->add(MethodSpec::new(
            'audio.status',
            Scope::Read,
            'A transcription job\'s state and text, from its ledger.',
            self::transcribeStatus(...),
        ));
    }

    // ── test seams ─────────────────────────────────────────────────────

    /**
     * Swap the render tool builder. Production builds
     * `GenerateImage::new()->withStoreSession($sessionId)` for kind
     * `image` and `GenerateVideo::new()->withStoreSession($sessionId)` for
     * kind `video` (C-WEB-3.f); a suite injects a stub that writes bytes
     * through MediaStore without dialing a server.
     */
    public static function setToolFactoryForTesting(?\Closure $factory): void
    {
        self::$toolFactory = $factory;
    }

    /**
     * Override the consent gate. Null falls back to
     * {@see SessionHost::permissionGate()} — the session-EFFECTIVE gate, the
     * same one the in-turn tool path forks — and a session whose gate
     * resolves to null is treated as a perpetual unanswered Ask, never as
     * consent.
     */
    public static function setGateForTesting(?PermissionGate $gate): void
    {
        self::$gateOverride = $gate;
    }

    /** Run renders in-handler instead of forking, for deterministic pins. */
    public static function setDirectRunForTesting(bool $direct): void
    {
        self::$directRun = $direct;
    }

    /** Swap the transcriber builder; null falls back to the bare {@see Transcriber}. */
    public static function setTranscriberFactoryForTesting(?\Closure $factory): void
    {
        self::$transcriberFactory = $factory;
    }

    public static function resetForTesting(): void
    {
        self::$toolFactory = null;
        self::$gateOverride = null;
        self::$directRun = false;
        self::$transcriberFactory = null;
    }

    // ── methods ────────────────────────────────────────────────────────

    /**
     * `generate.submit`: consent, open the ledger, launch, answer `{jobId}`.
     *
     * Params arrive as the MediaRequest v1 surface (plan W6.2) extended by
     * C-WEB-3.f's `kind` dial and the GenerateVideo door's key set; the
     * handler forwards only keys the kind's own schema models, so the
     * transport fields (`sessionId`, `idempotencyKey`) and `kind` itself
     * never reach the tool, and `idempotencyKey` replay never renders twice
     * — the Dispatcher's
     * idempotency cache (`sideEffects: true`) answers the replay from
     * memory with the same `{jobId}`.
     *
     * @param array<string, mixed> $_
     *
     * @return array{jobId: string}
     */
    private static function submit(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }

        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);
        // C-WEB-3.f: `kind` selects which tool door this job rides. It is a
        // wire dial, not a tool arg, so it is read from the raw params and
        // never forwarded — the per-kind intersection below drops it.
        $kindRaw = $call->request->params['kind'] ?? 'image';
        $kind = \is_string($kindRaw) ? MediaKind::tryFrom($kindRaw) : null;
        if ($kind === null) {
            throw RpcError::invalidParams('invalid params: kind must be image or video');
        }
        /** @var array<string, mixed> $args */
        $args = $kind === MediaKind::Video
            // Video forwards the GenerateVideo door's own key set: its
            // seven exclusive names plus the shared six, each still only
            // when the client named it.
            ? \array_intersect_key($call->request->params, self::VIDEO_ARG_KEYS)
            : \array_intersect_key($call->request->params, MediaRequest::paramSchema());

        if (\trim((string) ($args['prompt'] ?? '')) === '') {
            throw RpcError::invalidParams('invalid params: prompt is empty');
        }

        self::consent($host, $sessionId, $args, $kind);

        $job = MediaJob::new($kind)->withStatus(MediaJobStatus::Running);
        $store = MediaStore::forSession($sessionId);
        $ledgerPath = self::ledgerPath($store, $job->jobId());
        $startedAt = \time();
        self::writeLedger($ledgerPath, self::record($job, $args, $startedAt, null));

        // Snapshot before launching: whatever the render adds to this
        // session's artifact listing is what the job produced. Concurrent
        // submits into one session may each claim the other's files — a v1
        // limitation of listing-diff attribution, honest because both
        // records still name real, fetchable artifacts.
        $before = \array_column($store->list(), 'id');

        $run = static function () use ($host, $sessionId, $args, $job, $kind, $store, $ledgerPath, $startedAt, $before): string {
            $tool = (self::$toolFactory ?? static function () use ($sessionId, $kind): Tool {
                // C-WEB-3.f: each kind rides its own tool door, both bound
                // to this session's media folder through their store seam.
                return ($kind === MediaKind::Video ? GenerateVideo::new() : GenerateImage::new())
                    ->withStoreSession($sessionId);
            })($sessionId, $host->workspace());
            $result = $tool->execute($args);

            if ($result->isError()) {
                $settled = $job
                    ->withStatus(MediaJobStatus::Failed)
                    ->withError(self::clipError($result->content()));
            } else {
                $settled = $job;
                foreach ($store->list() as $entry) {
                    if (!\in_array($entry['id'], $before, true)) {
                        $settled = $settled->withArtifactPath((string) $entry['path']);
                    }
                }
                $settled = $settled->withStatus(MediaJobStatus::Done);
            }
            self::writeLedger($ledgerPath, self::record($settled, $args, $startedAt, \time()));

            return (string) \json_encode(['job_id' => $settled->jobId(), 'status' => $settled->status()->value]);
        };

        if (!self::$directRun && TurnController::canFork()) {
            TurnController::forkPayload(
                $run,
                static fn (string $file): string|false => TurnController::takePayload($file),
                $run,
                new CancellationToken(),
            )->then(static function (): void {
            });
        } else {
            // No pcntl (or a pinned suite): the fork door's own $inline
            // semantics, run where the refusal cannot pretend otherwise.
            $run();
        }

        return ['jobId' => $job->jobId()];
    }

    /**
     * `generate.status`: poll one ledger. v1 answers `{state, progress01}`
     * plus `artifacts` (MediaRef rows, W6.1's single formatter) once the job
     * is done and `error` when a render named one; `etaSeconds` stays
     * declared-and-unsent until a progress source writes it (W7 seam).
     *
     * @param array<string, mixed> $_
     *
     * @return array<string, mixed>
     */
    private static function status(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);

        $jobId = $params->string('jobId', 64);
        if (\preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
            throw RpcError::invalidParams('invalid params: jobId must be a 16-character hex job id');
        }

        $record = self::readLedger(MediaStore::forSession($sessionId), $jobId);
        /** @var array<string, mixed> $job */
        $job = $record['job'] ?? [];

        $out = [
            'state' => (string) ($job['status'] ?? MediaJobStatus::Queued->value),
            'progress01' => self::progress01($job),
        ];

        $paths = $job['artifact_paths'] ?? [];
        if (\is_array($paths) && $paths !== []) {
            $refs = TranscriptProjector::mediaRefsForPaths($paths, null, $truncated);
            if ($refs !== null) {
                $out['artifacts'] = $refs;
            }
        }

        if (isset($job['error']) && \is_string($job['error']) && $job['error'] !== '') {
            $out['error'] = $job['error'];
        }

        return $out;
    }

    /**
     * `media.fetch`: one base64 slice of a stored artifact. The payload is
     * seek-read from the file descriptor, so a multi-megabyte image is
     * never slurped whole into the handler, and the session's media folder
     * is the only place a path may resolve to — realpath containment, with
     * a symlink that escapes it treated exactly like any other absent file.
     *
     * @param array<string, mixed> $_
     *
     * @return array<string, mixed>
     */
    private static function fetch(CallContext $call, Params $params): array
    {
        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);

        $artifactId = $params->optionalString('artifactId', 128);
        $path = $params->optionalString('path', 4096);
        if (($artifactId === null) === ($path === null)) {
            throw RpcError::invalidParams('media.fetch takes exactly one of artifactId or path');
        }

        $store = MediaStore::forSession($sessionId);

        if ($artifactId !== null) {
            $resolved = self::pathForArtifact($store, $artifactId);
        } else {
            $resolved = self::containSessionPath($store, (string) $path);
        }

        $offset = $params->int('offset', 0, 0);
        $limit = $params->int('limit', self::FETCH_DEFAULT_BYTES, 1, self::FETCH_MAX_BYTES);

        $handle = @\fopen($resolved, 'rb');
        if ($handle === false) {
            throw RpcError::notFound('the artifact could not be opened', 'media_artifact_not_found');
        }
        $totalBytes = (int) (\fstat($handle)['size'] ?? 0);
        $chunk = '';
        if ($offset < $totalBytes) {
            if (\fseek($handle, $offset) !== 0) {
                \fclose($handle);

                throw RpcError::of(ErrorCode::Internal, 'the artifact could not be read', 'artifact_unreadable');
            }
            $remaining = \min($limit, $totalBytes - $offset);
            while ($remaining > 0) {
                $read = \fread($handle, $remaining);
                if ($read === false || $read === '') {
                    break;
                }
                $chunk .= $read;
                $remaining -= \strlen($read);
            }
        }
        \fclose($handle);

        $ref = TranscriptProjector::mediaRef($resolved, null);

        $out = [
            'artifactId' => (string) $ref['artifactId'],
            'offset' => $offset,
            'bytes' => \base64_encode($chunk),
            'totalBytes' => $totalBytes,
            'eof' => $offset + \strlen($chunk) >= $totalBytes,
        ];
        if ($offset === 0) {
            // The plan's shape: mime rides the first slice row only; later
            // offsets re-read the same file, not a new identity.
            $out['mime'] = (string) $ref['mime'];
        }

        return $out;
    }

    // ── audio ──────────────────────────────────────────────────────────

    /**
     * `audio.transcribe` (C-TUI-6.n): file a client-uploaded take and run
     * the voice transport against it — the same fork-and-poll shape submit
     * renders, answering `{jobId}` without waiting; `audio.status` polls.
     *
     * Egress law: the bytes reach ONLY `voice.baseUrl` through the welded
     * {@see Transcriber} — the wire names no endpoint, this lane adds no
     * dialer, and the mime is checked against the declared allowlist before
     * a take is written anywhere. Size bounds ride the base64 param ceiling
     * (refused at param-reading) and the decoded-bytes ceiling (refused
     * BEFORE the take lands); no blanket wall-clock kill exists on either
     * half — the transport's connect- and stall-bounds are the timers
     * (E646), so a slow-but-live endpoint is allowed to answer.
     *
     * @param array<string, mixed> $_
     *
     * @return array{jobId: string}
     */
    private static function transcribe(CallContext $call, Params $params): array
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }

        $voice = self::configuredVoice();

        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);

        $encoded = $params->string('audioBase64', self::TRANSCRIBE_MAX_BASE64_BYTES);
        $mime = $params->string('mime', 64);
        if (!isset(self::TRANSCRIBE_MIME_EXTENSIONS[$mime])) {
            throw RpcError::invalidParams(\sprintf(
                'invalid params: mime must be one of %s',
                \implode(', ', \array_keys(self::TRANSCRIBE_MIME_EXTENSIONS)),
            ));
        }
        $language = $params->optionalString('language', 64);

        $audio = \base64_decode($encoded, true);
        if ($audio === false) {
            throw RpcError::invalidParams('invalid params: audioBase64 is not valid base64');
        }
        if ($audio === '') {
            throw RpcError::invalidParams('invalid params: audioBase64 decodes to no bytes');
        }
        if (\strlen($audio) > self::TRANSCRIBE_MAX_AUDIO_BYTES) {
            throw RpcError::invalidParams(\sprintf(
                'invalid params: audioBase64 decodes to %d bytes; the limit is %d',
                \strlen($audio),
                self::TRANSCRIBE_MAX_AUDIO_BYTES,
            ));
        }

        // A wire language overrides only the request's `language` field; the
        // endpoint, key and model stay the server's own settings — rebuild
        // the snapshot through the same fromSettings() authority rather than
        // reading the live config a second time.
        $engine = $language === null ? $voice : VoiceConfig::fromSettings([
            'voice.baseUrl' => $voice->baseUrl,
            'voice.apiKey' => $voice->apiKey,
            'voice.model' => $voice->model,
            'voice.language' => $language,
            'voice.maxSeconds' => $voice->maxSeconds,
        ]);

        $jobId = \bin2hex(\random_bytes(8));
        $store = MediaStore::forSession($sessionId);
        $jobsDir = PrivateDir::ensure($store->directoryPath() . '/' . self::LEDGER_DIR, 'media job ledger');
        $takePath = $jobsDir . '/.' . $jobId . '.audio.' . self::TRANSCRIBE_MIME_EXTENSIONS[$mime];
        $ledgerPath = $jobsDir . '/' . self::AUDIO_LEDGER_PREFIX . $jobId . '.json';
        $startedAt = \time();

        self::writeTake($takePath, $audio);
        self::writeLedger($ledgerPath, [
            'state' => self::AUDIO_STATE_RUNNING,
            'mime' => $mime,
            'started_at' => $startedAt,
            'finished_at' => null,
        ]);

        $run = static function () use ($engine, $takePath, $jobId, $ledgerPath, $mime, $startedAt): string {
            $recording = new AudioRecording($takePath, 'web-mediarecorder', $engine->maxSeconds, (float) $startedAt);
            try {
                $text = (self::$transcriberFactory ?? static fn (): Transcriber => new Transcriber())()
                    ->transcribe($recording, $engine);
                $record = [
                    'state' => self::AUDIO_STATE_DONE,
                    'mime' => $mime,
                    'started_at' => $startedAt,
                    'finished_at' => \time(),
                    'text' => $text,
                ];
                $status = self::AUDIO_STATE_DONE;
            } catch (\Throwable $e) {
                // The real transport unlinks the take on every exit path; a
                // replaced one may not, and an orphan take must never
                // outlive its job either way.
                @\unlink($takePath);
                $record = [
                    'state' => self::AUDIO_STATE_FAILED,
                    'mime' => $mime,
                    'started_at' => $startedAt,
                    'finished_at' => \time(),
                    'error' => self::clipError($e->getMessage()),
                ];
                $status = self::AUDIO_STATE_FAILED;
            }
            self::writeLedger($ledgerPath, $record);

            return (string) \json_encode(['job_id' => $jobId, 'status' => $status]);
        };

        if (!self::$directRun && TurnController::canFork()) {
            TurnController::forkPayload(
                $run,
                static fn (string $file): string|false => TurnController::takePayload($file),
                $run,
                new CancellationToken(),
            )->then(static function (): void {
            });
        } else {
            // No pcntl (or a pinned suite): the fork door's own $inline
            // semantics, run where the refusal cannot pretend otherwise.
            $run();
        }

        return ['jobId' => $jobId];
    }

    /**
     * `audio.status`: poll one transcription ledger, projecting exactly
     * `{state, text?, error?}` onto the wire. The honest-silence law of the
     * submit door holds here too: while voice is unconfigured the poll
     * answers `voice_unconfigured` as well, so a client never reads a
     * stranded ledger as a live capability. Read scope: draining answers.
     *
     * @param array<string, mixed> $_
     *
     * @return array<string, mixed>
     */
    private static function transcribeStatus(CallContext $call, Params $params): array
    {
        self::configuredVoice();

        $sessionId = SessionMethods::sessionId($params);
        $host = $call->host($sessionId);

        $jobId = $params->string('jobId', 64);
        if (\preg_match(self::JOB_ID_PATTERN, $jobId) !== 1) {
            throw RpcError::invalidParams('invalid params: jobId must be a 16-character hex job id');
        }

        $path = MediaStore::forSession($sessionId)->directoryPath()
            . '/' . self::LEDGER_DIR . '/' . self::AUDIO_LEDGER_PREFIX . $jobId . '.json';
        if (!\is_file($path)) {
            throw RpcError::notFound(\sprintf('no transcription job %s in this session', $jobId), 'audio_job_not_found');
        }
        $raw = @\file_get_contents($path);
        $decoded = $raw === false ? null : \json_decode($raw, true);
        if (!\is_array($decoded) || !\is_string($decoded['state'] ?? null)) {
            throw RpcError::of(ErrorCode::Internal, 'the job ledger could not be read', 'ledger_corrupt');
        }

        $out = ['state' => $decoded['state']];
        if (isset($decoded['text']) && \is_string($decoded['text']) && $decoded['text'] !== '') {
            $out['text'] = $decoded['text'];
        }
        if (isset($decoded['error']) && \is_string($decoded['error']) && $decoded['error'] !== '') {
            $out['error'] = $decoded['error'];
        }

        return $out;
    }

    /**
     * The voice door's single gate: the transport settings must name an
     * endpoint (the same predicate `MediaAffordance` answers `transcribe`
     * from) or both audio doors refuse. Refusal sentences name the setting,
     * never a key.
     */
    private static function configuredVoice(): VoiceConfig
    {
        $voice = VoiceConfig::fromSettings();
        if (!$voice->enabled()) {
            throw RpcError::of(
                ErrorCode::Forbidden,
                'audio needs a voice endpoint; set voice.baseUrl in user settings',
                'voice_unconfigured',
            );
        }

        return $voice;
    }

    /**
     * File one decoded take at 0600 before the ledger names it: the same
     * exclusive-create publish law the ledger follows, so a planted path is
     * refused rather than overwritten, and a short write leaves no partial
     * take behind. The name is server-minted (job id plus the allowlist's
     * own extension), never client-chosen.
     */
    private static function writeTake(string $path, string $bytes): void
    {
        $umask = \umask(0o077);
        $handle = @\fopen($path, 'xb');
        \umask($umask);
        if ($handle === false) {
            throw RpcError::of(ErrorCode::Internal, 'the audio take could not be opened for writing', 'take_unwritable');
        }
        @\chmod($path, MediaStore::FILE_MODE);
        $written = \fwrite($handle, $bytes);
        \fclose($handle);
        if ($written !== \strlen($bytes)) {
            @\unlink($path);

            throw RpcError::of(ErrorCode::Internal, 'the audio take could not be written whole', 'take_unwritable');
        }
    }

    // ── consent ────────────────────────────────────────────────────────

    /**
     * The same gate the in-turn tool path consults —
     * {@see SessionHost::permissionGate()}, whose session-mode clone
     * {@see backend()} forks onto the turn's EngineBackend — asked about the
     * call the kind's own tool would carry (`GenerateImage` or, since
     * C-WEB-3.f, `GenerateVideo`). Allow runs; Deny refuses; Ask is answered
     * only by this session's memo of an earlier always-allow — the identical
     * door {@see SessionHost} opens for a re-ask inside a turn — and
     * otherwise refuses, because out-of-turn ask relay is descoped (W6.6)
     * and silence is not consent.
     *
     * @param array<string, mixed> $args
     */
    private static function consent(SessionHost $host, string $sessionId, array $args, MediaKind $kind): void
    {
        $toolName = $kind === MediaKind::Video ? GenerateVideo::NAME : GenerateImage::NAME;
        $gate = self::$gateOverride ?? $host->permissionGate();
        $root = $host->workspace()->root;
        $decision = $gate?->evaluate(new ToolCall($toolName, $args), $root, $sessionId)
            ?? PermissionDecision::Ask;

        if ($decision === PermissionDecision::Allow) {
            return;
        }

        if ($decision === PermissionDecision::Deny) {
            throw RpcError::of(ErrorCode::Forbidden, 'generate.submit was denied by the permission policy', 'permission_denied');
        }

        if ($gate !== null && $host->grants()->allows($toolName, $args, $root)) {
            return;
        }

        throw RpcError::of(
            ErrorCode::Forbidden,
            'generate.submit needs an approval the out-of-turn protocol cannot carry yet; answer the ask from a live turn or grant the tool first',
            'permission_ask_unanswered',
        );
    }

    // ── ledger ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function record(MediaJob $job, array $args, int $startedAt, ?int $finishedAt): array
    {
        return [
            'job' => $job->toArray(),
            'request' => $args,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ];
    }

    private static function ledgerPath(MediaStore $store, string $jobId): string
    {
        return PrivateDir::ensure($store->directoryPath() . '/' . self::LEDGER_DIR, 'media job ledger')
            . '/' . $jobId . '.json';
    }

    /**
     * Publish atomically at 0600 (the store's own file mode): a reader
     * polling the ledger either sees the previous whole record or the new
     * whole record, never a half-written one.
     *
     * @param array<string, mixed> $record
     */
    private static function writeLedger(string $path, array $record): void
    {
        $temp = $path . '.' . \getmypid() . '.partial';
        $umask = \umask(0o077);
        $handle = @\fopen($temp, 'xb');
        \umask($umask);
        if ($handle === false) {
            throw RpcError::of(ErrorCode::Internal, 'the job ledger could not be opened for writing', 'ledger_unwritable');
        }
        @\chmod($temp, MediaStore::FILE_MODE);
        \fwrite($handle, (string) \json_encode(
            $record,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        \fclose($handle);
        if (!@\rename($temp, $path)) {
            @\unlink($temp);

            throw RpcError::of(ErrorCode::Internal, 'the job ledger could not be published', 'ledger_unwritable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readLedger(MediaStore $store, string $jobId): array
    {
        $path = $store->directoryPath() . '/' . self::LEDGER_DIR . '/' . $jobId . '.json';
        if (!\is_file($path)) {
            throw RpcError::notFound(
                \sprintf('no generation job %s in this session', $jobId),
                'job_not_found',
            );
        }
        $raw = @\file_get_contents($path);
        $decoded = $raw === false ? null : \json_decode($raw, true);
        if (!\is_array($decoded) || !\is_array($decoded['job'] ?? null)) {
            throw RpcError::of(ErrorCode::Internal, 'the job ledger could not be read', 'ledger_corrupt');
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Progress the ledger can honestly claim: a done job is whole; anything
     * else rides the last recorded progress frame's ratio when one exists
     * (the async transports write them), else zero.
     *
     * @param array<string, mixed> $job
     */
    private static function progress01(array $job): float
    {
        if (($job['status'] ?? null) === MediaJobStatus::Done->value) {
            return 1.0;
        }

        $frames = $job['progress_frames'] ?? [];
        if (\is_array($frames) && $frames !== []) {
            $last = $frames[\count($frames) - 1] ?? null;
            if (\is_array($last) && isset($last['ratio']) && \is_numeric($last['ratio'])) {
                return \max(0.0, \min(1.0, (float) $last['ratio']));
            }
        }

        return 0.0;
    }

    // ── fetch containment ──────────────────────────────────────────────

    /**
     * An artifactId resolves through the store's listing rule only —
     * `pathFor` answers the contained path for a real id and nothing else.
     */
    private static function pathForArtifact(MediaStore $store, string $artifactId): string
    {
        try {
            $resolved = $store->pathFor($artifactId);
        } catch (\RuntimeException) {
            throw RpcError::invalidParams('invalid params: artifactId must be a stored name without separators');
        }

        if ($resolved === null) {
            throw RpcError::notFound('no stored artifact with that id in this session', 'media_artifact_not_found');
        }

        return $resolved;
    }

    /**
     * A raw path is accepted only as a file inside this session's media
     * folder after realpath resolves it — parent-directory tokens are
     * refused outright, and a symlink whose target escapes the folder fails
     * the containment compare like any other outside file. Nothing in a
     * refusal names what was probed.
     */
    private static function containSessionPath(MediaStore $store, string $path): string
    {
        if (\str_contains($path, '..')) {
            throw RpcError::invalidParams('invalid params: path must not contain a parent-directory reference');
        }

        $anchor = \realpath($store->directoryPath());
        $real = \realpath($path);
        if ($anchor === false || $real === false || !\is_file($real) || !\str_starts_with($real, $anchor . '/')) {
            throw RpcError::notFound('no artifact of this session answers that path', 'media_artifact_not_found');
        }

        return $real;
    }

    private static function clipError(string $message): string
    {
        if (\strlen($message) <= self::ERROR_CLIP_BYTES) {
            return $message;
        }

        return \mb_strcut($message, 0, self::ERROR_CLIP_BYTES, 'UTF-8') . ' …';
    }
}
