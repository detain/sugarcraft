<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use SugarCraft\Crush\Media\MediaRequest;

/**
 * Typed sdapi client (plan W1.3): the only door through which the media layer
 * speaks to a Stable-Diffusion webui, and the only place its error shape is
 * decided.
 *
 * LAWS ENFORCED HERE
 * ------------------
 * - Every URL is configured-base + server-relative path, composed and pinned
 *   by {@see UrlGuard}; no caller-supplied absolute URL ever reaches the
 *   transport (a path containing a scheme is REFUSED, not dialled).
 * - Non-2xx becomes an {@see SdException} carrying the sdapi
 *   {error, detail, body, errors} envelope (or the raw text when the server —
 *   often a proxy — answered without JSON); transports that never got an
 *   HTTP answer become one with status 0. Nothing raw is ever rethrown.
 * - `init_images`, `mask` and pngInfo's `image` accept base64 with OR without
 *   a `data:` prefix; the prefix is stripped at this edge (mystage §5.4
 *   ingestion row 1), because the A1111 validator only takes bare b64 while
 *   every clipboard around it carries data-URIs.
 * - Zero ReactPHP, zero timers, zero wall-clock ceilings in this file: the
 *   connect bound lives in GuzzleTransport, liveness of long renders lives in
 *   W1.5's ProgressLoop beating the tool-level idle ceiling (E646 law).
 *
 * Base-URL resolution precedence (W1.8's config rows, lane e's EnvRoster
 * obligation): explicit constructor override > the SUGARCRUSH_SD_BASE_URL
 * environment variable (read literally in {@see resolveBaseUrl()} — the
 * literal is what the env-roster guard requires) > the layered `sd.baseUrl`
 * setting, with the provider-shaped `mediaBaseUrl` key as its sibling
 * fallback > refused with a pointed message.
 */
final class Client
{
    /** Env override for the SD base URL (tabulated in ENVIRONMENT.md; read LITERALLY below per the env-roster guard). */
    public const BASE_URL_ENV = 'SUGARCRUSH_SD_BASE_URL';

    /** Layered-settings row carrying the base URL (lane e, MediaSettings). */
    public const BASE_URL_CONFIG_KEY = 'sd.baseUrl';

    /** Provider-config sibling key (lane e's TYPE_SCHEMAS optionals). */
    public const BASE_URL_MEDIA_CONFIG_KEY = 'mediaBaseUrl';

    private function __construct(
        private readonly UrlGuard $guard,
        private readonly SdTransport $transport,
    ) {
    }

    /**
     * Wire the production stack: resolve the base, pin the guard, build the
     * Guzzle transport with the auth header the configured key implies.
     *
     * @param array<string, mixed> $clientOptions  passthrough Guzzle client options ('handler' is the test seam)
     */
    public static function configured(
        ?SdTransport $transport = null,
        ?string $baseUrlOverride = null,
        ?string $apiKey = null,
        array $clientOptions = [],
    ): self {
        $base = self::resolveBaseUrl($baseUrlOverride);

        if ($base === null) {
            throw SdException::protocol(
                'sd client',
                'no SD base URL configured — set the ' . self::BASE_URL_CONFIG_KEY
                    . ' setting, its env override, or pass an explicit base',
            );
        }

        return self::withBase($base, $transport, $apiKey, $clientOptions);
    }

    /**
     * Build on an explicit base (tests, and any caller that resolved config
     * itself — W1.8's fromConfig seam is this method's natural caller).
     */
    public static function withBase(
        string $base,
        ?SdTransport $transport = null,
        ?string $apiKey = null,
        array $clientOptions = [],
    ): self {
        $guard = UrlGuard::forBase($base);

        return new self(
            $guard,
            $transport ?? new GuzzleTransport($guard, self::authHeaders($apiKey), $clientOptions),
        );
    }

    /**
     * The env-over-settings precedence hop. Returns null when NOTHING
     * configures a base — `configured()` turns that refusal into a message.
     */
    public static function resolveBaseUrl(?string $override = null): ?string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }

        $env = getenv(self::BASE_URL_ENV);

        if (\is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        foreach ([self::BASE_URL_CONFIG_KEY, self::BASE_URL_MEDIA_CONFIG_KEY] as $key) {
            $value = self::settings()[$key] ?? null;

            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Auth header for a configured key (never logged — only its PRESENCE is
     * ever asserted). A1111's `--api-auth user:pass` is Basic, so a key
     * carrying a colon is split as credentials; a bare token travels as
     * Bearer, the shape fronting proxies use.
     *
     * @return array<string, string>
     */
    public static function authHeaders(?string $apiKey): array
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return [];
        }

        $key = trim($apiKey);

        if (str_contains($key, ':')) {
            return ['Authorization' => 'Basic ' . base64_encode($key)];
        }

        return ['Authorization' => 'Bearer ' . $key];
    }

    // ---------------------------------------------------------------- generate

    /** @param MediaRequest|array<string, mixed> $request */
    public function txt2img(MediaRequest|array $request): SdTransportResult
    {
        return $this->post(Endpoints::TXT2IMG, $this->generationBody($request), 'txt2img');
    }

    /** @param MediaRequest|array<string, mixed> $request */
    public function img2img(MediaRequest|array $request): SdTransportResult
    {
        return $this->post(Endpoints::IMG2IMG, $this->generationBody($request), 'img2img');
    }

    /** @param array<string, mixed> $request */
    public function extraSingleImage(array $request): SdTransportResult
    {
        return $this->post(Endpoints::EXTRA_SINGLE_IMAGE, $request, 'extras-single');
    }

    /** @param array<string, mixed> $request */
    public function extraBatchImages(array $request): SdTransportResult
    {
        return $this->post(Endpoints::EXTRA_BATCH_IMAGES, $request, 'extras-batch');
    }

    /** Bare b64 or data-URI both accepted; returns `{info, items, parameters}`. */
    public function pngInfo(string $imageBase64): SdTransportResult
    {
        return $this->post(Endpoints::PNG_INFO, ['image' => self::stripDataUri($imageBase64)], 'png-info');
    }

    /** @param array<string, mixed> $request  {image: b64, model: 'clip'|'deepbooru'} */
    public function interrogate(array $request): SdTransportResult
    {
        if (isset($request['image']) && \is_string($request['image'])) {
            $request['image'] = self::stripDataUri($request['image']);
        }

        return $this->post(Endpoints::INTERROGATE, $request, 'interrogate');
    }

    // ----------------------------------------------------------------- control

    /**
     * Live progress. `$withPreview` flips `skip_current_image` so the payload
     * carries the sampling preview frame's b64 (§4.7) — off by default, the
     * frame is large.
     *
     * @return array<string, mixed>
     */
    public function progress(bool $withPreview = false): array
    {
        return $this->decodedArray(
            $this->get(Endpoints::PROGRESS, ['skip_current_image' => $withPreview ? 'false' : 'true'], 'progress'),
            'progress',
        );
    }

    /**
     * A1111's two-stage interrupt (§4.7): first press sets
     * `interrupt_after_current`, the next interrupts immediately. Global
     * shared-UI state, NOT per-task — documented per §4.6 co-tenant hazard.
     */
    public function interrupt(bool $afterCurrent = false): SdTransportResult
    {
        if (!$afterCurrent) {
            return $this->post(Endpoints::INTERRUPT, [], 'interrupt');
        }

        if (!$this->transport instanceof HeaderAwareSdTransport) {
            throw SdException::protocol(
                'sd interrupt',
                'interrupt_after_current needs a header-capable transport (GuzzleTransport implements it), got ' . $this->transport::class,
            );
        }

        return $this->judge(
            $this->ask(
                fn (): SdTransportResult => $this->transport->requestWithHeaders(
                    'POST',
                    $this->resolve(Endpoints::INTERRUPT),
                    [],
                    [],
                    ['interrupt_after_current' => 'true'],
                ),
                'interrupt',
            ),
            'interrupt',
        );
    }

    public function skip(): SdTransportResult
    {
        return $this->post(Endpoints::SKIP, [], 'skip');
    }

    // ----------------------------------------------------------------- readout

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->decodedArray($this->get(Endpoints::OPTIONS, [], 'options'), 'options');
    }

    /** @param array<string, mixed> $settings */
    public function setOptions(array $settings): SdTransportResult
    {
        return $this->post(Endpoints::OPTIONS, $settings, 'options-set');
    }

    /** @return array<string, mixed> */
    public function cmdFlags(): array
    {
        return $this->decodedArray($this->get(Endpoints::CMD_FLAGS, [], 'cmd-flags'), 'cmd-flags');
    }

    /** @return list<array<string, mixed>> */
    public function samplers(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::SAMPLERS, [], 'samplers'), 'samplers'));
    }

    /** @return list<array<string, mixed>> */
    public function schedulers(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::SCHEDULERS, [], 'schedulers'), 'schedulers'));
    }

    /** @return list<array<string, mixed>> */
    public function upscalers(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::UPSCALERS, [], 'upscalers'), 'upscalers'));
    }

    /** @return list<array<string, mixed>> */
    public function latentUpscaleModes(): array
    {
        return array_values($this->decodedList(
            $this->get(Endpoints::LATENT_UPSCALE_MODES, [], 'latent-upscale-modes'),
            'latent-upscale-modes',
        ));
    }

    /** @return list<array<string, mixed>> */
    public function sdModels(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::SD_MODELS, [], 'sd-models'), 'sd-models'));
    }

    /** @return list<array<string, mixed>> */
    public function sdVae(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::SD_VAE, [], 'sd-vae'), 'sd-vae'));
    }

    /** @return list<array<string, mixed>> */
    public function promptStyles(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::PROMPT_STYLES, [], 'prompt-styles'), 'prompt-styles'));
    }

    /** @return array<string, mixed> */
    public function scripts(): array
    {
        return $this->decodedArray($this->get(Endpoints::SCRIPTS, [], 'scripts'), 'scripts');
    }

    /** @return list<array<string, mixed>> */
    public function scriptInfo(): array
    {
        return array_values($this->decodedList($this->get(Endpoints::SCRIPT_INFO, [], 'script-info'), 'script-info'));
    }

    /** @return array<string, mixed> */
    public function memory(): array
    {
        return $this->decodedArray($this->get(Endpoints::MEMORY, [], 'memory'), 'memory');
    }

    /**
     * Liveness probe ({@see Endpoints::INTERNAL_PING}); returns the raw
     * server reply — an empty object on a healthy stock server. Also the
     * live target of the legacy `/tick` heartbeat name.
     */
    public function ping(): array
    {
        return $this->decodedArray($this->get(Endpoints::INTERNAL_PING, [], 'ping'), 'ping');
    }

    /** The origin every request from this client is pinned to. */
    public function origin(): string
    {
        return $this->guard->origin();
    }

    // ------------------------------------------------------------------ internals

    /**
     * Project MediaRequest's wire array through the two edge laws: data-URI
     * strip on the b64 fields, and the save_images derivation — the A1111
     * handler OVERWRITES do_not_save_samples/do_not_save_grid from save_images
     * (§4.2 note), so we set them ourselves the same way unless the caller
     * already stated them (an explicit field still wins, per-request).
     *
     * @param MediaRequest|array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function generationBody(MediaRequest|array $request): array
    {
        $wire = $request instanceof MediaRequest ? $request->toArray() : $request;

        foreach (['init_images', 'mask'] as $field) {
            if (!isset($wire[$field])) {
                continue;
            }

            if (\is_string($wire[$field])) {
                $wire[$field] = self::stripDataUri($wire[$field]);
            } elseif (\is_array($wire[$field])) {
                $wire[$field] = array_map(
                    static fn (mixed $v): mixed => \is_string($v) ? self::stripDataUri($v) : $v,
                    $wire[$field],
                );
            }
        }

        if (array_key_exists('save_images', $wire) && \is_bool($wire['save_images'])) {
            foreach (['do_not_save_samples', 'do_not_save_grid'] as $derived) {
                if (!array_key_exists($derived, $wire)) {
                    $wire[$derived] = !$wire['save_images'];
                }
            }
        }

        return $wire;
    }

    /**
     * data: URIs are clipboard-shaped base64; sdapi wants the payload alone.
     * Anything not shaped like a data URI passes through untouched (including
     * the http(s) init-image URLs img2img accepts per §1.3 — those are NOT
     * b64 and the server fetches them).
     */
    private static function stripDataUri(string $value): string
    {
        $trimmed = trim($value);

        if (!str_starts_with($trimmed, 'data:')) {
            return $trimmed;
        }

        $comma = strpos($trimmed, ',');

        if ($comma === false) {
            throw SdException::protocol('sd client', 'data: URI carries no payload comma');
        }

        return substr($trimmed, $comma + 1);
    }

    private function post(string $path, array $json, string $context): SdTransportResult
    {
        return $this->judge(
            $this->ask(fn (): SdTransportResult => $this->transport->request('POST', $this->resolve($path), $json), $context),
            $context,
        );
    }

    /**
     * Every dialled path funnels through the endpoint registry: canonical
     * names pass through, the twelve legacy clauses rewrite or refuse, and
     * nothing unregistered ever reaches the transport (the client maps,
     * never guesses — plan W1.4).
     */
    private function resolve(string $path): string
    {
        return Endpoints::resolve($path);
    }

    private function get(string $path, array $query, string $context): SdTransportResult
    {
        return $this->judge($this->ask(
            fn (): SdTransportResult => $this->transport->request('GET', $this->resolve($path), [], $query),
            $context,
        ), $context);
    }

    /** Transport never threw raw: any Throwable becomes the one error class. */
    private function ask(callable $send, string $context): SdTransportResult
    {
        try {
            return $send();
        } catch (SdException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw SdException::transportFailure('sd ' . $context, $e);
        }
    }

    private function judge(SdTransportResult $result, string $context): SdTransportResult
    {
        if ($result->is2xx()) {
            return $result;
        }

        throw SdException::fromResponse('sd ' . $context, $result->status, $result->decodedJson(), $result->body);
    }

    /** @return array<string, mixed> @throws SdException on a non-object payload */
    private function decodedArray(SdTransportResult $result, string $context): array
    {
        $decoded = $result->decodedJson();

        // [] decodes to an empty LIST in PHP, but an empty OBJECT is a legal
        // sdapi answer ({} from interrupt/ping) — only a NON-empty list is wrong.
        if ($decoded === null || ($decoded !== [] && array_is_list($decoded))) {
            throw SdException::protocol('sd ' . $context, 'expected a JSON object payload');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<mixed> @throws SdException on a non-array payload */
    private function decodedList(SdTransportResult $result, string $context): array
    {
        $decoded = $result->decodedJson();

        if ($decoded === null || !array_is_list($decoded)) {
            throw SdException::protocol('sd ' . $context, 'expected a JSON array payload');
        }

        return $decoded;
    }

    /**
     * The merged layered settings, fail-soft exactly like
     * HttpClientDefaults::settingsConfig() — unreadable settings cost us the
     * env/default chain only, never a hard failure at resolution time.
     *
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        try {
            return \SugarCraft\Crush\Cli\Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }
}
