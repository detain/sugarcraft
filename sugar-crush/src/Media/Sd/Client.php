<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use SugarCraft\Crush\Media\MediaRequest;

/**
 * Typed sdapi client (plan W1.3) plus the sglang-diffusion IMAGE dialect
 * (plan W7, flipping Appendix C-1's detectable-unimplemented ruling): the only
 * door through which the media layer speaks to a Stable-Diffusion webui, and
 * the only place its error shape is decided. The sglang methods share the
 * guard, transport, judge and envelope laws above; they speak the OpenAI-ish
 * /v1/images wire, never the sdapi body builder, and their refusals are the
 * named-key list on SGLANG_REFUSED_FIELDS.
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
     * `${VAR}` placeholders resolve FIRST (W7 security MED-2): this is the
     * single point where a configured media key materialises into a wire
     * header — every reader (GenerateImage, GenerateVideo via JobClient,
     * ImportPng, choice feeds) funnels through here — so the expansion the
     * `sd.apiKey` settings doc advertises is enforced exactly once. The law
     * mirrors Providers/ProviderFactory.php resolveEnv() (:403): `${VAR}` and
     * `${VAR:-default}` read the environment, an unset placeholder without a
     * default resolves to the empty string — which, trimmed, means NO header
     * is sent. A literal `${SD_API_KEY}` can therefore never ride out as a
     * Bearer value.
     *
     * @return array<string, string>
     */
    public static function authHeaders(?string $apiKey): array
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return [];
        }

        $key = trim(self::expandEnvPlaceholders($apiKey));

        if ($key === '') {
            return [];
        }

        if (str_contains($key, ':')) {
            return ['Authorization' => 'Basic ' . base64_encode($key)];
        }

        return ['Authorization' => 'Bearer ' . $key];
    }

    /**
     * Substitutes every `${VAR}` / `${VAR:-default}` in the value with its
     * environment, unset-and-defaultless collapsing to ''. Semantics and
     * pattern are the deliberate twin of ProviderFactory::resolveEnv() — the
     * two paths must keep resolving placeholders identically.
     */
    private static function expandEnvPlaceholders(string $value): string
    {
        $expanded = preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/',
            static function (array $matches): string {
                $envValue = getenv($matches[1]);

                if ($envValue === false || $envValue === '') {
                    return $matches[2] ?? '';
                }

                return $envValue;
            },
            $value,
        );

        return $expanded ?? $value;
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

    // ------------------------------------------- sglang image dialect (plan W7, C-1 flip)

    /**
     * sdapi-only knobs with no field in either sglang-diffusion image schema
     * (ImageGenerationsRequest / Body_edits, both openapi-verified at W0). A
     * draft that SETS any of them is refused pre-dial, by name — the
     * detectable-unimplemented law's sibling: the adapter accepts the honest
     * subset and never silently drops a parameter the caller meant.
     * init_images/mask are deliberately absent here: they select the EDITS
     * route (multipart), they are not a refusal.
     *
     * @var list<string>
     */
    public const SGLANG_REFUSED_FIELDS = [
        'styles', 'subseed', 'subseed_strength', 'seed_resize_from_h', 'seed_resize_from_w',
        'sampler_name', 'scheduler', 'n_iter', 'denoising_strength',
        'enable_hr', 'firstphase_width', 'firstphase_height', 'hr_scale', 'hr_upscaler',
        'hr_second_pass_steps', 'hr_resize_x', 'hr_resize_y', 'hr_checkpoint_name',
        'hr_sampler_name', 'hr_scheduler', 'hr_prompt', 'hr_negative_prompt',
        'restore_faces', 'tiling', 'eta', 's_churn', 's_tmax', 's_tmin', 's_noise',
        's_min_uncond', 'token_merging_ratio', 'token_merging_ratio_hr',
        'refiner_checkpoint', 'refiner_switch_at',
        'override_settings', 'override_settings_restore_afterwards',
        'disable_extra_networks', 'comments', 'script_name', 'script_args', 'alwayson_scripts',
        'force_task_id', 'infotext', 'resize_mode',
        'mask_blur', 'mask_blur_x', 'mask_blur_y', 'inpainting_fill', 'inpaint_full_res',
        'inpaint_full_res_padding', 'inpainting_mask_invert', 'initial_noise_multiplier',
        'image_cfg_scale', 'include_init_images', 'mask_round',
        'do_not_save_samples', 'do_not_save_grid',
    ];

    /**
     * Names of the sdapi-only knobs present on a draft, plus the two shape
     * conflicts: a half-stated width/height pair cannot compose the sglang
     * `size` string, and an explicit `size` riding alongside BOTH dimensions
     * would double-specify it. save_images is refused only when TRUE —
     * false is a no-op that drops. Pure: never dials, never throws.
     *
     * @param MediaRequest|array<string, mixed> $request
     * @return list<string> empty when the draft is sglang-speakable
     */
    public static function sglangImageRefusals(MediaRequest|array $request): array
    {
        $wire = $request instanceof MediaRequest ? $request->toArray() : $request;

        $names = [];
        foreach (self::SGLANG_REFUSED_FIELDS as $field) {
            if (\array_key_exists($field, $wire)) {
                $names[] = $field;
            }
        }
        if (($wire['save_images'] ?? null) === true) {
            $names[] = 'save_images';
        }
        $width = \array_key_exists('width', $wire);
        $height = \array_key_exists('height', $wire);
        if ($width !== $height) {
            $names[] = 'width/height';
        } elseif ($width && \array_key_exists('size', $wire)) {
            $names[] = 'size + width/height';
        }

        return $names;
    }

    /**
     * MediaRequest -> the sglang wire body (Q-10, openapi-verified at W0):
     * cfg_scale rides `guidance_scale` — NOT cfg_scale, which this dialect
     * does not know; steps ride `num_inference_steps`; width x height compose
     * the `size` string; batch_size becomes `n`; the adapter requests
     * `response_format=b64_json` unless the draft stated one itself.
     * Schema-shaped unknowns (model, true_cfg_scale, task_type, …) ride
     * verbatim — MediaRequest's tolerated-extra path is declared intent —
     * but a mapped field always wins over an identically-named unknown, and
     * the sdapi output-routing/binary knobs drop (they are not scalars a form
     * field could carry, or they were mapped away by name).
     *
     * @param MediaRequest|array<string, mixed> $request
     * @return array<string, mixed>
     * @throws SdException naming any refused knob (defense-in-depth: the tool
     *         door consults sglangImageRefusals first and words the refusal)
     */
    public static function sglangImageBody(MediaRequest|array $request): array
    {
        $refused = self::sglangImageRefusals($request);
        if ($refused !== []) {
            throw SdException::protocol(
                'sglang image body',
                'the sdapi-only knobs ' . implode(', ', $refused) . ' have no sglang-diffusion field',
            );
        }

        $wire = $request instanceof MediaRequest ? $request->toArray() : $request;

        $body = [];
        foreach (['prompt', 'negative_prompt', 'seed'] as $carry) {
            if (\array_key_exists($carry, $wire)) {
                $body[$carry] = $wire[$carry];
            }
        }
        if (\array_key_exists('batch_size', $wire)) {
            $body['n'] = $wire['batch_size'];
        }
        if (\array_key_exists('steps', $wire)) {
            $body['num_inference_steps'] = $wire['steps'];
        }
        if (\array_key_exists('cfg_scale', $wire)) {
            $body['guidance_scale'] = $wire['cfg_scale'];
        }
        if (\array_key_exists('width', $wire) && \array_key_exists('height', $wire)) {
            $body['size'] = $wire['width'] . 'x' . $wire['height'];
        }

        foreach ($wire as $key => $value) {
            if (!\is_string($key) || \array_key_exists($key, $body) || \in_array($key, self::SGLANG_DROPPED_FIELDS, true)) {
                continue;
            }
            $body[$key] = $value;
        }
        $body['response_format'] ??= 'b64_json';

        return self::stripExplicitNulls($body);
    }

    /**
     * The JSON generations dial (ImageGenerationsRequest). No ProgressLoop
     * beating rides this route from the tool side: /sdapi/v1/progress is
     * absent on sglang and a first-poll 404 would kill the render — the
     * waiting-room law is documented at the GenerateImage branch.
     *
     * @param MediaRequest|array<string, mixed> $request
     */
    public function sglangImageGenerate(MediaRequest|array $request): SdTransportResult
    {
        $path = Endpoints::resolveSglangImage('generations');

        return $this->judge(
            $this->ask(fn (): SdTransportResult => $this->transport->request('POST', $path, self::sglangImageBody($request)), 'sglang-txt2img'),
            'sglang-txt2img',
        );
    }

    /**
     * The multipart edits dial (Body_edits, W0 openapi): init_images decode
     * strict into `image[]` file parts, the mask rides its single form field
     * (data-URI prefix stripped at this edge like every other b64 in the
     * layer), and every scalar from sglangImageBody rides as a form field —
     * the boolean stringification and non-scalar refusal are the transport's
     * law, stated on MultipartSdTransport.
     *
     * @param MediaRequest|array<string, mixed> $request
     */
    public function sglangImageEdit(MediaRequest|array $request): SdTransportResult
    {
        if (!$this->transport instanceof MultipartSdTransport) {
            throw SdException::protocol(
                'sglang-img2img',
                'the edits route posts multipart form data (W0 openapi) and needs a multipart-capable '
                    . 'transport (GuzzleTransport implements it), got ' . $this->transport::class,
            );
        }

        $fields = self::sglangImageBody($request);
        $files = [];
        $init = $request instanceof MediaRequest
            ? (array) ($request->initImages() ?? [])
            : (array) ($request['init_images'] ?? []);
        foreach (array_values($init) as $index => $image) {
            $bytes = base64_decode(\is_string($image) ? trim($this->stripDataUri($image)) : '', true);
            if ($bytes === false || $bytes === '') {
                throw SdException::protocol('sglang-img2img', "init_images[{$index}] is not strict base64 image bytes");
            }
            $files[] = [
                'name' => 'image[]',
                'filename' => 'init-' . ($index + 1) . '.png',
                'contents' => $bytes,
            ];
        }
        $mask = $request instanceof MediaRequest
            ? $request->mask()
            : (\is_string($request['mask'] ?? null) ? $request['mask'] : null);
        if (\is_string($mask) && trim($mask) !== '') {
            $fields['mask'] = trim($this->stripDataUri($mask));
        }

        $path = Endpoints::resolveSglangImage('edits');

        return $this->judge(
            $this->ask(fn (): SdTransportResult => $this->transport->requestMultipart('POST', $path, $fields, $files), 'sglang-img2img'),
            'sglang-img2img',
        );
    }

    /**
     * GET the composed /v1/images/{id}/content carry route. The path is
     * registry-composed — Endpoints::sglangImageContent rawurlencodes the id,
     * so the map-never-guess law holds over the one dynamic segment.
     */
    public function sglangImageContentBytes(string $imageId): SdTransportResult
    {
        $path = Endpoints::sglangImageContent($imageId);

        return $this->judge(
            $this->ask(fn (): SdTransportResult => $this->transport->request('GET', $path, [], []), 'sglang-image-content'),
            'sglang-image-content',
        );
    }

    /**
     * ImageResponse envelope -> ['id' => non-empty string, 'items' => list of
     * single-key rows: ['bytes'] when b64_json carried inline bytes (strict
     * decode, whitespace tolerated), ['url'] / ['file_path'] as carry markers
     * the caller must resolve — the adapter requests b64_json, so a carry row
     * means the draft overrode response_format.
     *
     * @return array{id: string, items: list<array<string, string>>}
     */
    public function sglangImagesDecode(SdTransportResult $result): array
    {
        $decoded = $this->decodedArray($result, 'sglang-images');
        $id = $decoded['id'] ?? null;
        if (!\is_string($id) || trim($id) === '') {
            throw SdException::protocol('sglang-images', 'the response carries no usable image id');
        }
        $data = $decoded['data'] ?? null;
        if (!\is_array($data) || $data === []) {
            throw SdException::protocol('sglang-images', 'the response carries no images');
        }

        $items = [];
        foreach (array_values($data) as $index => $entry) {
            if (!\is_array($entry)) {
                throw SdException::protocol('sglang-images', "data[{$index}] is not an object");
            }
            $b64 = $entry['b64_json'] ?? null;
            if (\is_string($b64) && trim($b64) !== '') {
                $bytes = base64_decode(trim($b64), true);
                if ($bytes === false) {
                    throw SdException::protocol('sglang-images', "data[{$index}].b64_json is not strict base64");
                }
                $items[] = ['bytes' => $bytes];

                continue;
            }
            foreach (['url', 'file_path'] as $carry) {
                $value = $entry[$carry] ?? null;
                if (\is_string($value) && trim($value) !== '') {
                    $items[] = [$carry => trim($value)];

                    continue 2;
                }
            }
            throw SdException::protocol('sglang-images', "data[{$index}] carries no b64_json, url or file_path");
        }

        return ['id' => $id, 'items' => $items];
    }

    /**
     * Names sglangImageBody maps away by translation or drops as sdapi-only
     * binaries/routing — the ride loop must not smuggle them back.
     *
     * @var list<string>
     */
    private const SGLANG_DROPPED_FIELDS = [
        'batch_size', 'steps', 'cfg_scale', 'width', 'height',
        'save_images', 'send_images', 'init_images', 'mask',
    ];

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
     * Project MediaRequest's wire array through the three edge laws: data-URI
     * strip on the b64 fields, the save_images derivation — the A1111
     * handler OVERWRITES do_not_save_samples/do_not_save_grid from save_images
     * (§4.2 note), so we set them ourselves the same way unless the caller
     * already stated them (an explicit field still wins, per-request) — and
     * the explicit-null drop of {@see stripExplicitNulls}().
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

        return self::stripExplicitNulls($wire);
    }

    /**
     * Drop keys whose value is an explicit null from any dialled body
     * (R8-ADVISORY-1, carried into W5 as the egress law).
     *
     * The DTO keeps set-to-null distinct from unset — MediaRequest::toArray()
     * emits nulls for XSet fields on purpose, so fromArray(toArray())
     * round-trips exactly (class docblock, plan W1.1). The WIRE, however,
     * cannot tell "clear to default" from null: A1111's request models are
     * generated dynamically with EVERY field typed `Optional[T]` whose default
     * is the processing `__init__` default (plan_crush_media.md Appendix F,
     * PydanticModelGenerator row) — so a key sent as null VALIDATES (no 422)
     * and then pushes Python `None` into processing arithmetic, where an
     * ABSENT key is filled from the server's own defaults table
     * (`set_defaults`). Omit therefore equals default on sdapi, and a stale-
     * clear row collapsed by the W3.5 disclosure machinery ("enable_hr":null,
     * "hr_scale":null) means exactly "revert to defaults" — which is what
     * dropping the key says. The strip is applied HERE, at the transport
     * boundary, never in the DTO, so both laws hold: bookkeeping round-trips,
     * egress carries intent.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function stripExplicitNulls(array $body): array
    {
        foreach ($body as $key => $value) {
            if ($value === null) {
                unset($body[$key]);
            }
        }

        return $body;
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
