<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Media\Capability\EndpointFamily;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\ProgressLoop;
use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransport;
use SugarCraft\Crush\Media\Sd\SdTransportResult;
use SugarCraft\Crush\Support\MediaStore;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\ToolResult as BootProbe;
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
 * Render a still image from a text prompt against a configured Stable
 * Diffusion (A1111 sdapi) server — the first production consumer of the
 * Wave-1 media core (plan W2.1).
 *
 * SHAPE. The prompt and the A1111 knob subset arrive as tool args, flow
 * through {@see MediaRequest} (unknown knobs ride `withUnknown` so
 * forward-compatible extras survive), go out as one `POST /sdapi/v1/txt2img`
 * on the {@see Client}, come back through the {@see Response} decoder (which
 * applies the grid-prepend and infotext-shift laws), and EVERY artifact —
 * samples and any batch grid — is saved through {@see MediaStore} under the
 * session's media directory with the save-name pattern from `sd.savePattern`.
 * Each PNG carries its infotext re-embedded verbatim when the server sent one
 * (the replay loop the A1111 UI itself uses), else the canonical
 * {@see Infotext::emit()} rendering of what we actually asked for.
 *
 * CAPABILITY GATE FIRST (binding ruling C-1, sdapi-implemented-first). Before
 * any dial: a configured `mediaFamily` outside the implemented set (e.g.
 * `sglang-diffusion`, which discovery can detect but generation does not yet
 * speak) is refused by name, a `mediaKinds` roster that names kinds but omits
 * `image` is refused, and an unresolvable base URL (no `sd.baseUrl`, no env
 * override, no `mediaBaseUrl`) is refused by {@see Client::configured()}'s
 * protocol error — all three produce a plain error result and ZERO requests.
 *
 * HEARTBEAT TOPOLOGY. The A1111 generation wire is synchronous: the POST
 * returns only when the batch is done, and a built-in tool may not fork (the
 * repo-wide child-lifetime census owns every spawn site). So this tool beats
 * EngineBackend's idle ceiling through {@see ProgressLoop} — the W1.5 shape
 * its own docblock names — with the single blocking POST issued from the
 * done-sentinel's first pass: one poll beats, the render, one poll beats,
 * done. Honest residual: a render LONGER than the idle ceiling still starves
 * between the pre-render and post-render beats; `n_iter`/`batch_size` narrow
 * the window per iteration, and the preview-frame wiring owed by W2.4 (which
 * re-enters this loop with `$onPreviewFrame`) is the designed follow-up that
 * beats mid-render on multi-step jobs.
 *
 * CANCELLATION. The done-sentinel checks {@see ToolCancelRequests} (the turn
 * child's latched view of the user's Esc) BEFORE issuing the POST, so a call
 * cancelled while queued never dials. Once the render itself is in flight the
 * synchronous wire cannot be interrupted from this process; the server-side
 * `POST /sdapi/v1/interrupt` passthrough is W2.4/W5 surface.
 *
 * PERMISSION CLASS: ask. This renders pixels on a machine the model does not
 * own, costs real GPU time, and dials an external host — the user approves
 * each launch of it.
 */
#[BuiltInTool(name: 'GenerateImage', permission: ToolPermissionClass::Ask, position: 33, gloss: 'render a still image from a text prompt on the configured Stable Diffusion server (sdapi)')]
final readonly class GenerateImage implements Tool, BuildsFromCatalog, DelegatesToEngine, AcceptsHeartbeat, TakesToolCallId
{
    use TruncatesOutput;

    public const NAME = 'GenerateImage';

    /** Tool arg -> sdapi wire key, when the two spellings differ. */
    private const ARG_WIRE_ALIASES = ['save_to_disk' => 'save_images'];

    /** Session folder for MediaStore when the bound engine has no session id. */
    private const FALLBACK_SESSION = 'main';

    /**
     * Every non-null field below is a test seam overriding live behaviour:
     * `$transport`/`$baseUrl`/`$settings` replace the configured dial, the
     * env/config base-URL resolution, and the `Bootstrap::readUserConfig()`
     * read; `$mediaRoot` relocates the artifact tree; the pacing pair keeps
     * the progress loop off real time. Production builds carry only nulls.
     */
    private function __construct(
        private ?EngineBackend $engine = null,
        private ?SdTransport $transport = null,
        private ?string $baseUrl = null,
        private ?array $settings = null,
        private ?string $mediaRoot = null,
        private ?float $pollIntervalSeconds = null,
        private ?\Closure $pollSleeper = null,
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
        );
    }

    /** The same tool pacing its progress loop without real sleeps. */
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
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Render a still image from a text prompt using the Stable Diffusion server this launch is'
            . ' configured for (the sdapi endpoint family). `prompt` is required; the server knobs'
            . ' `negative_prompt`, `width`, `height`, `steps`, `cfg_scale`, `seed` (pass -1 for a random'
            . ' draw), `sampler_name`, `scheduler`, `batch_size` and `n_iter` are optional and forwarded'
            . ' when set — any other knob you pass rides through to the server untouched. Every finished'
            . ' image, and the batch grid when the server sends one, is saved to the session media folder'
            . ' with its infotext embedded, and the paths come back in the result; `save_to_disk` also'
            . ' asks the server to keep its own copy. Long renders beat the watchdog through the progress'
            . ' route and stop early if the user cancels the call before the render starts.';
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
                    'description' => 'What to render, self-contained: style, subject, framing. Required,'
                        . ' non-empty',
                ],
                'negative_prompt' => [
                    'type' => 'string',
                    'description' => 'What to steer away from, in the same comma-clause style',
                ],
                'width' => ['type' => 'integer', 'description' => 'Image width in pixels (server default applies)'],
                'height' => ['type' => 'integer', 'description' => 'Image height in pixels (server default applies)'],
                'steps' => ['type' => 'integer', 'description' => 'Sampling steps — more is slower, past a point indistinguishable'],
                'cfg_scale' => ['type' => 'number', 'description' => 'Prompt adherence; high values burn in artefacts'],
                'seed' => ['type' => 'integer', 'description' => 'Exact seed to reproduce a prior image, or -1 for a fresh draw'],
                'sampler_name' => ['type' => 'string', 'description' => 'Sampler as the server names it (Euler a, DPM++ 2M Karras, ...)'],
                'scheduler' => ['type' => 'string', 'description' => 'Scheduler as the server names it'],
                'batch_size' => ['type' => 'integer', 'description' => 'Images per request'],
                'n_iter' => ['type' => 'integer', 'description' => 'Repeat requests; narrows the watchdog window on long renders'],
                'save_to_disk' => [
                    'type' => 'boolean',
                    'description' => 'Also ask the server to write its own copy into its output directory;'
                        . ' the tool always saves its copy regardless',
                ],
            ],
            'required' => ['prompt'],
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
            return new ToolResult($callId, 'image generation failed: ' . $e->getMessage(), true);
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
            return new ToolResult($callId, 'GenerateImage needs a non-empty `prompt`', true);
        }

        $request = $this->requestFrom($args, $prompt);
        if ($request instanceof ToolResult) {
            return $request; // DTO refusal (api-owned or v1-unsupported key) surfaced verbatim
        }

        $apiKey = \is_string($settings['sd.apiKey'] ?? null) && $settings['sd.apiKey'] !== ''
            ? $settings['sd.apiKey']
            : null;
        try {
            $client = Client::configured($this->transport, $this->baseUrl, $apiKey);
        } catch (SdException $e) {
            // resolveBaseUrl answers from settings/env alone: this branch is
            // the unconfigured-host refusal and it has dialed nothing.
            return new ToolResult($callId, 'image generation is not available on this launch: ' . $e->getMessage(), true);
        }

        $rendered = $this->awaitRender($client, $request, $heartbeat, $callId);
        if ($rendered instanceof ToolResult) {
            return $rendered; // cancelled, or died waiting, before the bytes arrived
        }

        return $this->collect($rendered, $request, $settings, $callId);
    }

    /** @param array<array-key, mixed> $settings */
    private function capabilityRefusal(array $settings): ?string
    {
        // C-1: the sdapi family is the only one generation speaks. A family
        // that merely fails to parse is not this gate's business (discovery
        // diagnoses it); one that parses to a DETECTABLE-BUT-UNIMPLEMENTATED
        // family is refused here, by name, so no dial is ever attempted.
        $familyRaw = $settings['mediaFamily'] ?? null;
        if (\is_string($familyRaw) && trim($familyRaw) !== '') {
            $family = EndpointFamily::tryFrom(trim($familyRaw));
            if ($family !== null && !$family->isImplemented()) {
                return 'the media endpoint family "' . $familyRaw . '" is recognised but generation over it'
                    . ' is not implemented yet; only the A1111 sdapi family is, so no request was sent';
            }
        }

        // An absent mediaKinds roster means "everything the host can do" —
        // the config-authority fail-open from W1.8. A present roster that
        // names kinds without naming image means image generation is off.
        if (\array_key_exists('mediaKinds', $settings)) {
            $kinds = ProviderFactory::configuredMediaKinds($settings['mediaKinds']);
            if ($kinds !== [] && !\in_array('image', $kinds, true)) {
                return 'this launch is configured for media kinds (' . implode(', ', $kinds)
                    . ') that do not include image, so no request was sent';
            }
        }

        return null;
    }

    /**
     * Args -> wire map (machinery key `id` never rides; aliases apply) -> DTO.
     * Unknown knobs are MediaRequest's tolerated-extra path by construction.
     *
     * @return MediaRequest|ToolResult  ToolResult on a DTO refusal
     */
    private function requestFrom(array $args, string $prompt): MediaRequest|ToolResult
    {
        $wire = [];
        foreach ($args as $key => $value) {
            if (!\is_string($key) || $key === 'id' || $key === 'prompt') {
                continue;
            }
            $wire[self::ARG_WIRE_ALIASES[$key] ?? $key] = $value;
        }
        $wire['prompt'] = $prompt;

        try {
            return MediaRequest::fromArray($wire);
        } catch (\InvalidArgumentException $e) {
            return new ToolResult((string) ($args['id'] ?? ''), 'the generation request was refused: ' . $e->getMessage(), true);
        }
    }

    /**
     * The heartbeat-bracketed wait (see class docblock topology): the loop
     * beats per progress poll, the sentinel issues the one blocking POST on
     * its first pass and reports done once the bytes are back. A POST failure
     * propagates untouched; a readout-only failure after a completed render
     * still returns the bytes.
     */
    private function awaitRender(Client $client, MediaRequest $request, ?\Closure $heartbeat, string $callId): SdTransportResult|ToolResult
    {
        $awaited = null;
        $sentinel = function () use (&$awaited, $client, $request, $callId): bool {
            if ($awaited !== null) {
                return true;
            }
            if ($callId !== '' && ToolCancelRequests::isRequested($callId)) {
                return true;
            }
            $awaited = $client->txt2img($request);

            return false;
        };

        $beat = $heartbeat ?? static function (): void {
        };
        // Queued-then-cancelled calls never reach the loop's first poll: the
        // pre-check is what keeps the zero-dial promise of the class docblock.
        if ($callId !== '' && ToolCancelRequests::isRequested($callId)) {
            return new ToolResult($callId, ToolCancelRequests::CANCELLED, true);
        }
        try {
            ProgressLoop::run(
                $client,
                $beat,
                $sentinel,
                null,
                $this->pollIntervalSeconds ?? ProgressLoop::DEFAULT_INTERVAL_SECONDS,
                $this->pollSleeper,
            );
        } catch (SdException $e) {
            if (!$awaited instanceof SdTransportResult) {
                throw $e; // the render itself never completed: honest failure
            }
            // The readout route gave up after the render returned; the bytes
            // are real, the loop's job (beats) is done. Fall through.
        }

        if (!$awaited instanceof SdTransportResult) {
            return new ToolResult($callId, ToolCancelRequests::CANCELLED, true);
        }

        return $awaited;
    }

    /**
     * Decode (grid law), re-embed infotext, save every artifact through
     * MediaStore, report. imageBytes/imagePath ride the first SAMPLE (the
     * grid is listed, never headlined), matching the display contract.
     *
     * @param array<array-key, mixed> $settings
     */
    private function collect(SdTransportResult $rendered, MediaRequest $request, array $settings, string $callId): ToolResult
    {
        $decoded = Response::generation($rendered);
        $samples = $decoded->response->artifacts();
        $grid = $decoded->grid;
        if ($samples === [] && $grid === null) {
            return new ToolResult($callId, 'the server returned a generation response with no images in it', true);
        }

        $pattern = \is_string($settings['sd.savePattern'] ?? null) && trim($settings['sd.savePattern']) !== ''
            ? $settings['sd.savePattern']
            : null;
        $store = MediaStore::forSession(
            $this->engine?->sessionId() ?? self::FALLBACK_SESSION,
            $this->mediaRoot,
            $pattern,
        );

        $infotexts = isset($decoded->info['infotexts']) && \is_array($decoded->info['infotexts'])
            ? array_values($decoded->info['infotexts'])
            : [];
        $shift = $grid !== null ? 1 : 0;

        /** @var list<string> $lines */
        $lines = [];
        $headBytes = null;
        $headPath = null;

        foreach ($samples as $slot => $artifact) {
            $raw = $infotexts[$slot + $shift] ?? null;
            $bytes = $this->embed($artifact, $request, \is_string($raw) ? $raw : null);
            $saved = $this->save($store, $artifact, $bytes, $request);
            if ($slot === 0) {
                $headBytes = $bytes;
                $headPath = $saved->path();
            }
            $lines[] = 'image ' . ($slot + 1) . ': ' . (string) $saved->path();
        }
        if ($grid !== null) {
            $gridRaw = $infotexts[0] ?? null;
            $gridBytes = $this->embed($grid, $request, \is_string($gridRaw) ? $gridRaw : null);
            $savedGrid = $this->save($store, $grid, $gridBytes, $request);
            array_unshift($lines, 'batch grid: ' . (string) $savedGrid->path());
            if ($headBytes === null) {
                $headBytes = $gridBytes;
                $headPath = $savedGrid->path();
            }
        }

        $content = 'Generated ' . \count($samples) . ' image' . (\count($samples) === 1 ? '' : 's')
            . ($grid !== null ? ' plus the batch grid' : '') . " on the SD server:\n"
            . implode("\n", $lines);

        return new ToolResult(
            toolCallId: $callId,
            content: $content,
            imageBytes: $headBytes,
            imagePath: $headPath,
            imageProtocol: BootProbe::mosaic()->protocol(),
        );
    }

    /** Re-embed verbatim server infotext, else emit ours; non-PNG rides raw. */
    private function embed(MediaArtifact $artifact, MediaRequest $request, ?string $rawText): string
    {
        $bytes = (string) $artifact->bytes();
        if (!Png::isPng($bytes)) {
            return $bytes;
        }
        $text = $rawText;
        if ($text === null || trim($text) === '') {
            $parsed = $artifact->infotext();
            $text = \is_array($parsed) && $parsed !== []
                ? Infotext::emit($request, $parsed)
                : Infotext::emit($request, [], $request->prompt());
        }

        return trim($text) === '' ? $bytes : Png::setInfotext($bytes, $text);
    }

    /**
     * Save one artifact with palette-only filename tokens: the Response
     * companion bag can carry `subseed`, which the save-name palette does not
     * model, so it is filtered (MediaStore refuses off-palette keys by name).
     */
    private function save(MediaStore $store, MediaArtifact $artifact, string $bytes, MediaRequest $request): MediaArtifact
    {
        $tokens = array_intersect_key($artifact->tokenValues(), array_flip(MediaStore::TOKENS));
        $fromRequest = [
            'steps' => $request->isSet('steps') ? $request->steps() : null,
            'cfg' => $request->isSet('cfg_scale') ? $request->cfgScale() : null,
            'sampler' => $request->isSet('sampler_name') ? $request->samplerName() : null,
            'width' => $request->isSet('width') ? $request->width() : null,
            'height' => $request->isSet('height') ? $request->height() : null,
        ];
        foreach ($fromRequest as $token => $value) {
            if (!isset($tokens[$token]) && $value !== null) {
                $tokens[$token] = $value;
            }
        }

        return $store->put($bytes, 'png', $tokens);
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
