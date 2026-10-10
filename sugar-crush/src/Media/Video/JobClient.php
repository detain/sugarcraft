<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Video;

use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\GuzzleTransport;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransport;
use SugarCraft\Crush\Media\Sd\SdTransportResult;
use SugarCraft\Crush\Media\Sd\UrlGuard;
use SugarCraft\Crush\Support\MediaStore;

/**
 * The async video-job transport: submit → status poll → fetch
 * (plan_crush_media.md W5.2).
 *
 * Video is NOT A1111-standard (mystage §7 / crush_media §7): the client
 * speaks whatever the live server proved (W0.1 recorded the sglang-Diffusion
 * contract at plan :1090: POST /v1/videos → VideoResponse
 * {id,status,progress,url,file_path,seconds,fps,num_frames,error}; GET
 * /v1/videos[/{id}][/{id}/content]; DELETE /v1/videos/{id}) — else the
 * config-declared {@see EndpointFamily} default with fixture-tested shapes.
 * A1111 core has no video route at all, so an sdapi-family submit refuses
 * honestly instead of inventing one; the job-level CANCEL fallback for that
 * family is A1111's /interrupt (§4.6 — GLOBAL server state, a co-tenant
 * hazard: it interrupts whatever the shared instance is running, not only
 * our task).
 *
 * Egress laws inherited from W1.3: every dialled path is server-relative and
 * resolved by the transport's own {@see UrlGuard} pin to the configured
 * origin; a server-returned URL is validated same-origin before it is ever
 * fetched (response content gets no more freedom than request routing);
 * params ride OPAQUE (§7 law — frame counts and fps are the tool's schema's
 * business, the client forwards the array verbatim); and fact (f) is
 * enforced HERE — fetch lands bytes in MediaStore and hands back the PATH,
 * nothing downstream ever sees a b64 string.
 */
final class JobClient
{
    /** Video cadence: 1 s (the 0.5 s image cadence is wrong here — plan W5.2 step 4). */
    public const DEFAULT_POLL_INTERVAL_SECONDS = 1.0;

    /** The one extension this client writes; artifact typing is the store's business from here on. */
    public const ARTIFACT_EXTENSION = 'mp4';

    /**
     * The family assumed when the launch declares none: the one contract
     * recorded off a live server (W0.1). The GenerateVideo tool resolves the
     * effective family through this same constant, so the ladder and the
     * transport never disagree about who gets dialed.
     */
    public const DEFAULT_FAMILY = EndpointFamily::SglangDiffusion;

    private function __construct(
        private readonly UrlGuard $guard,
        private readonly SdTransport $transport,
        private readonly EndpointFamily $family,
        private readonly Client $sdapi,
    ) {
    }

    /**
     * Production wiring: same base-URL precedence as the image client
     * (override > env > settings.mediaFamily siblings) via
     * {@see Client::resolveBaseUrl}.
     *
     * @param  array<string, mixed>  $clientOptions  passthrough Guzzle client options ('handler' is the test seam)
     */
    public static function configured(
        ?SdTransport $transport = null,
        ?string $baseUrlOverride = null,
        ?string $apiKey = null,
        ?EndpointFamily $family = null,
        array $clientOptions = [],
    ): self {
        $base = Client::resolveBaseUrl($baseUrlOverride);

        if ($base === null) {
            throw SdException::protocol(
                'video job client',
                'no media base URL configured — set the ' . Client::BASE_URL_CONFIG_KEY
                    . ' setting, its env override, or pass an explicit base',
            );
        }

        return self::withBase($base, $transport, $apiKey, $family, $clientOptions);
    }

    /**
     * Build on an explicit base (tests, and any caller that resolved config
     * itself). With no family declared the client defaults to the ONLY video
     * job contract recorded off a live server (W0.1) — an honest fixture-era
     * default, not a guess about somebody else's fork; the capability door in
     * the tool layer keeps refusal authority either way.
     */
    public static function withBase(
        string $base,
        ?SdTransport $transport = null,
        ?string $apiKey = null,
        ?EndpointFamily $family = null,
        array $clientOptions = [],
    ): self {
        $guard = UrlGuard::forBase($base);
        $transport ??= new GuzzleTransport($guard, Client::authHeaders($apiKey), $clientOptions);

        return new self(
            $guard,
            $transport,
            $family ?? self::DEFAULT_FAMILY,
            Client::withBase($base, $transport),
        );
    }

    public function family(): EndpointFamily
    {
        return $this->family;
    }

    /**
     * Hand the render request to the server and take back its task id.
     *
     * $params ride verbatim (opaque-params law). The live sglang contract
     * POSTs multipart (plan :1090) — the W1.3 transport seam is JSON-only,
     * so the JSON body here is the fixture-tested shape; the multipart
     * widening belongs to the sglang-diffusion implementation step the
     * W7.7 live smoke will need anyway (loud note in the W5 REPORT).
     *
     * @param  array<string, mixed>  $params
     * @throws SdException on a dialect without a video job route, on transport failure, or on an id-less response
     */
    public function submit(array $params): string
    {
        $routes = $this->routes();

        if ($routes === null) {
            throw SdException::protocol(
                'video job submit',
                'the ' . $this->family->value . ' dialect exposes no video job route — '
                    . 'A1111 core is image-only (mystage §7); video renders need a job-capable '
                    . 'family such as sglang-diffusion',
            );
        }

        $decoded = $this->object(
            $this->send('POST', $routes['submit'], $params, [], 'submit')->decodedJson(),
            'submit',
        );

        $id = $decoded['id'] ?? null;

        if (!\is_string($id) || trim($id) === '') {
            throw SdException::protocol('video job submit', 'accepted response carries no usable task id string');
        }

        return $id;
    }

    /**
     * One read of the job's status. Unknown state words refuse via
     * {@see JobSnapshot::fromArray()} — the transition vocabulary is closed.
     *
     * @throws SdException on transport failure, non-2xx, or an unmappable state
     */
    public function status(string $taskId): JobSnapshot
    {
        $routes = $this->routes();

        if ($routes === null) {
            throw $this->noDialect();
        }

        $decoded = $this->object(
            $this->send('GET', $this->jobPath($routes['job'], $taskId), [], [], 'status')->decodedJson(),
            'status',
        );

        return JobSnapshot::fromArray($decoded);
    }

    /**
     * Ingest the finished job's bytes into the store, returning the artifact
     * with its on-disk path (fact (f): decode → MediaStore.put → path back;
     * the b64 string dies in this method).
     *
     * Priority mirrors §5.4 rows: inline b64 first (nothing to fetch), then
     * a result URL (same-origin-guarded GET), then a server-local path —
     * which is NEVER trusted as a filesystem path but fetched through the
     * dialect's declared download route, or refused honestly when the
     * dialect declares none.
     *
     * @param  array<string, string|int>  $tokens  MediaStore filename tokens
     */
    public function fetch(string $taskId, JobSnapshot $snapshot, MediaStore $store, array $tokens = []): MediaArtifact
    {
        $routes = $this->routes();

        if ($routes === null) {
            throw $this->noDialect();
        }

        $ref = $snapshot->resultRef;

        $b64 = $ref['b64_json'] ?? $ref['b64'] ?? null;

        if (\is_string($b64)) {
            return $store->put($this->decodeStrict($b64), self::ARTIFACT_EXTENSION, $tokens, MediaKind::Video);
        }

        $url = $ref['url'] ?? null;

        if (\is_string($url)) {
            $bytes = $this->send('GET', $this->sameOriginPath($url), [], [], 'fetch')->body;

            return $store->put($bytes, self::ARTIFACT_EXTENSION, $tokens, MediaKind::Video);
        }

        $localPath = $ref['file_path'] ?? $ref['videoPath'] ?? null;

        if (\is_string($localPath)) {
            // No path trust: the string only proves the server thinks the
            // file exists; the bytes come through the declared route.
            if (!isset($routes['content'])) {
                throw SdException::protocol(
                    'video job fetch',
                    'the ' . $this->family->value . ' dialect returned a server-local path with no '
                        . 'declared download route — the artifact is not fetchable from here',
                );
            }

            $bytes = $this->send('GET', $this->jobPath($routes['job'], $taskId) . $routes['content'], [], [], 'fetch')->body;

            return $store->put($bytes, self::ARTIFACT_EXTENSION, $tokens, MediaKind::Video);
        }

        throw SdException::protocol(
            'video job fetch',
            'the finished job carries no fetchable result reference (url / file_path / b64 all absent)',
        );
    }

    /**
     * Cancel the job. Job-level endpoint where the dialect has one (W0.1
     * records DELETE /v1/videos/{id} for sglang-diffusion); the sdapi family
     * has NO per-task route, so the fallback is A1111's POST /interrupt —
     * which is GLOBAL shared-UI state (§4.6 co-tenant hazard: it interrupts
     * whatever the instance is running, not only our task; that is exactly
     * why this is a cancel-fallback and not a silent convenience).
     */
    public function cancel(string $taskId): SdTransportResult
    {
        if ($this->family === EndpointFamily::SdApi) {
            return $this->sdapi->interrupt(afterCurrent: false);
        }

        $routes = $this->routes();

        if ($routes === null) {
            throw $this->noDialect();
        }

        return $this->send('DELETE', $this->jobPath($routes['job'], $taskId), [], [], 'cancel');
    }

    /**
     * Per-family route table (server-root-relative). Only the live-recorded
     * sglang-Diffusion video contract is registered; extending the table is
     * the same shape as the W1.4 endpoint-registry law — map, never guess.
     *
     * @return null|array{submit: string, job: string, content?: string}
     */
    private function routes(): ?array
    {
        return self::videoContract($this->family);
    }

    /**
     * The closed route table per dialect — the map-never-guess registry the
     * capability door reads BEFORE any client exists (plan W5.3: a family
     * without a video job contract is refused by name, zero dials). The
     * image-era `EndpointFamily::isImplemented()` stays the IMAGE authority;
     * for video the truthful surface is whether a job contract is recorded
     * here at all (ruling disclosed in the W5.3 lane report).
     *
     * @return null|array{submit: string, job: string, content: string}
     */
    public static function videoContract(EndpointFamily $family): ?array
    {
        return match ($family) {
            EndpointFamily::SglangDiffusion => [
                'submit' => '/v1/videos',
                'job' => '/v1/videos',
                'content' => '/content',
            ],
            default => null,
        };
    }

    private function noDialect(): SdException
    {
        return SdException::protocol(
            'video job client',
            'the ' . $this->family->value . ' dialect has no recorded video job contract to dial',
        );
    }

    /** @param array<string, scalar> $query */
    private function send(string $method, string $path, array $json, array $query, string $context): SdTransportResult
    {
        try {
            $result = $this->transport->request($method, $path, $json, $query);
        } catch (SdException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SdException::transportFailure('video job ' . $context, $e);
        }

        if ($result->is2xx()) {
            return $result;
        }

        throw SdException::fromResponse('video job ' . $context, $result->status, $result->decodedJson(), $result->body);
    }

    private function jobPath(string $base, string $taskId): string
    {
        $trimmed = trim($taskId);

        if ($trimmed === '') {
            throw SdException::protocol('video job client', 'empty task id cannot address a job route');
        }

        return $base . '/' . rawurlencode($trimmed);
    }

    /**
     * Strict result-base64 gate (plan W5.2 coercion). Measurement pinned in
     * the lane REPORT: PHP's `base64_decode($s, true)` SKIPS whitespace
     * rather than refusing it, so the plan's whitespace/newline-laden
     * refusal is implemented as an explicit alphabet+whitespace check in
     * front of the strict decode — lenient paths never open.
     */
    private function decodeStrict(string $payload): string
    {
        if (
            $payload === ''
            || preg_match('/\s/', $payload) === 1
            || preg_match('/\A[A-Za-z0-9+\/=]+\z/', $payload) !== 1
        ) {
            throw SdException::protocol(
                'video job fetch',
                'result payload is not strict base64 — whitespace/newline-laden or out-of-alphabet payloads are refused, not lenient-decoded',
            );
        }

        $bytes = base64_decode($payload, true);

        if ($bytes === false || $bytes === '') {
            throw SdException::protocol('video job fetch', 'strict base64 decode of the result payload failed');
        }

        return $bytes;
    }

    /**
     * A server-returned URL is response content, not configuration: it may
     * only re-dial the configured origin (UrlGuard's redirect law), and it
     * travels back to the transport as the server-relative path so the
     * transport's own guard pin stays load-bearing.
     */
    private function sameOriginPath(string $url): string
    {
        $absolute = $this->guard->redirectTarget($url);
        $parts = parse_url($absolute);
        $path = (string) ($parts['path'] ?? '');
        $prefix = $this->guard->baseUrl() === $this->guard->origin() ? '' : substr($this->guard->baseUrl(), \strlen($this->guard->origin()));

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = substr($path, \strlen($prefix));
        }

        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        if (!str_starts_with($path, '/')) {
            throw SdException::protocol('video job fetch', 'same-origin result URL resolved to a non-absolute path');
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     * @throws SdException on a non-object payload (list rejection mirrors the image client's decodedArray law)
     */
    private function object(?array $decoded, string $context): array
    {
        if ($decoded === null || (array_is_list($decoded) && $decoded !== [])) {
            throw SdException::protocol('video job ' . $context, 'expected a JSON object payload');
        }

        return $decoded;
    }
}
