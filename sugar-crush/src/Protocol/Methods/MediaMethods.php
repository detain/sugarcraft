<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Host\TranscriptProjector;
use SugarCraft\Crush\Host\TurnController;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Media\MediaJob;
use SugarCraft\Crush\Media\MediaJobStatus;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Support\MediaStore;
use SugarCraft\Crush\Support\PrivateDir;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;
use SugarCraft\Crush\Tools\Tool;

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
 * Consent mirrors the tool's own door: `GenerateImage` is an Ask-class tool
 * and submit runs the SAME {@see PermissionGate} the in-turn tool path
 * consults. A Deny refuses; an unanswered Ask cannot be relayed out of turn
 * (the ask-relay surface was descoped in W6.6), so submit refuses loudly
 * with `permission_ask_unanswered` rather than bypass it — clients drive
 * generations under a mode or grant that answers Ask affirmatively.
 *
 * The ledger is one JSON file per job at
 * `<session media folder>/jobs/<jobId>.json`, written 0600 with the store's
 * own temp-then-rename publish shape. MediaStore's listings scandir with an
 * `is_file` filter, so that `jobs/` directory is invisible to artifact ids —
 * job records never masquerade as fetchable artifacts.
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

    /** Longest error text a ledger keeps from a failed render. */
    private const ERROR_CLIP_BYTES = 2048;

    /**
     * Test seams only (see {@see self::setToolFactoryForTesting()}): the
     * render tool, the consent gate override, and a switch that runs the
     * render inline so a suite can pin ledger shapes deterministically.
     */
    private static ?\Closure $toolFactory = null;

    private static ?PermissionGate $gateOverride = null;

    private static bool $directRun = false;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(
            'generate.submit',
            Scope::Write,
            'Start an image generation; answers with its job id.',
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
    }

    // ── test seams ─────────────────────────────────────────────────────

    /**
     * Swap the render tool builder. Production builds
     * `GenerateImage::new()->withStoreSession($sessionId)`; a suite injects
     * a stub that writes bytes through MediaStore without dialing a server.
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

    public static function resetForTesting(): void
    {
        self::$toolFactory = null;
        self::$gateOverride = null;
        self::$directRun = false;
    }

    // ── methods ────────────────────────────────────────────────────────

    /**
     * `generate.submit`: consent, open the ledger, launch, answer `{jobId}`.
     *
     * Params arrive as the MediaRequest v1 surface (plan W6.2); the handler
     * forwards only keys {@see MediaRequest::paramSchema()} models, so the
     * transport fields (`sessionId`, `idempotencyKey`) never reach the tool,
     * and `idempotencyKey` replay never renders twice — the Dispatcher's
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
        /** @var array<string, mixed> $args */
        $args = \array_intersect_key($call->request->params, MediaRequest::paramSchema());

        if (\trim((string) ($args['prompt'] ?? '')) === '') {
            throw RpcError::invalidParams('invalid params: prompt is empty');
        }

        self::consent($host, $sessionId, $args);

        $job = MediaJob::new(MediaKind::Image)->withStatus(MediaJobStatus::Running);
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

        $run = static function () use ($host, $sessionId, $args, $job, $store, $ledgerPath, $startedAt, $before): string {
            $tool = (self::$toolFactory ?? static fn (): Tool => GenerateImage::new()->withStoreSession($sessionId))($sessionId, $host->workspace());
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

    // ── consent ────────────────────────────────────────────────────────

    /**
     * The same gate the in-turn tool path consults —
     * {@see SessionHost::permissionGate()}, whose session-mode clone
     * {@see backend()} forks onto the turn's EngineBackend — asked about the
     * same `GenerateImage` call the tool would carry. Allow runs; Deny
     * refuses; Ask is answered only by this session's memo of an earlier
     * always-allow — the identical door {@see SessionHost} opens for a
     * re-ask inside a turn — and otherwise refuses, because out-of-turn ask
     * relay is descoped (W6.6) and silence is not consent.
     *
     * @param array<string, mixed> $args
     */
    private static function consent(SessionHost $host, string $sessionId, array $args): void
    {
        $gate = self::$gateOverride ?? $host->permissionGate();
        $root = $host->workspace()->root;
        $decision = $gate?->evaluate(new ToolCall(GenerateImage::NAME, $args), $root, $sessionId)
            ?? PermissionDecision::Ask;

        if ($decision === PermissionDecision::Allow) {
            return;
        }

        if ($decision === PermissionDecision::Deny) {
            throw RpcError::of(ErrorCode::Forbidden, 'generate.submit was denied by the permission policy', 'permission_denied');
        }

        if ($gate !== null && $host->grants()->allows(GenerateImage::NAME, $args, $root)) {
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
