<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Capability;

use InvalidArgumentException;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\Sd\SdTransport;
use Throwable;

/**
 * Resolves "what can this endpoint do" without ever being the reason crush
 * fails to start (plan W1.2).
 *
 * Resolution order is the plan's dialect law (§10.1-7, W0 findings):
 * CONFIG AUTHORITY first — an explicit mediaKinds/mediaFamily setting is
 * truth, no network needed; PROBE second — a bounded, fail-open GET ladder
 * against the transport when nothing (or only a base URL) is declared;
 * NONE-default last — null means "treat as no media capability", and callers
 * must not gate the feature's existence on it (a down LAN must not hide the
 * tools).
 *
 * Fail-open is total on the probe side: every transport throw, timeout,
 * non-2xx, non-JSON or un-decodable answer is absorbed and simply means that
 * rung did not match — probe() and forProvider() never propagate a network
 * Throwable. (Invalid DECLARED config is different and does throw: a typo'd
 * mediaKinds list is a human error worth surfacing, not a server condition.)
 *
 * DISCOVERY_TIMEOUT_SECONDS governs the GETs below only; generation POSTs are
 * long by nature and carry their own E646 idle-bound ceilings in W1.3 — the
 * precedent and its exemption wording mirror SglangServerInfo (chat-side
 * discovery, src/Providers).
 */
final readonly class CapabilityDiscoverer
{
    /** Hard ceiling for one discovery GET; the transport owns honouring it. */
    public const DISCOVERY_TIMEOUT_SECONDS = 3.0;

    /** Probe ladder, most specific dialect first (see probe()). */
    public const ROUTES = [
        'sdapi' => '/sdapi/v1/cmd-flags',
        'comfyui' => '/object_info',
        'sglang-diffusion' => '/model_info',
        'openai-images' => '/v1/models',
    ];

    /**
     * @param  array<string, mixed>  $providerConfig  one provider's settings map (W1.8 shape)
     */
    public function forProvider(array $providerConfig, ?SdTransport $transport = null): ?MediaCapability
    {
        $declared = $this->fromConfig($providerConfig);
        if ($declared === null) {
            return $transport === null ? null : $this->probe($transport);
        }
        if ($transport === null) {
            return $declared;
        }
        $probe = $this->probe($transport);

        return $probe === null ? $declared : $declared->mergedFrom($probe);
    }

    /**
     * Pure config-authority read, no transport: mediaKinds (non-empty list of
     * image|video) is what switches a provider from "silent" to "declared".
     * Returns null when the config declares nothing media-related.
     *
     * @param  array<string, mixed>  $providerConfig
     */
    public function fromConfig(array $providerConfig): ?MediaCapability
    {
        $kindsRaw = $providerConfig['mediaKinds'] ?? null;
        if ($kindsRaw === null || (is_array($kindsRaw) && $kindsRaw === [])) {
            return null;
        }
        if (!is_array($kindsRaw)) {
            throw new InvalidArgumentException('CapabilityDiscoverer::fromConfig(): mediaKinds must be a list of "image"/"video", got ' . get_debug_type($kindsRaw));
        }
        $kinds = [];
        foreach ($kindsRaw as $entry) {
            $kind = is_string($entry) ? MediaKind::tryFrom($entry) : null;
            if ($kind === null) {
                throw new InvalidArgumentException(
                    'CapabilityDiscoverer::fromConfig(): mediaKinds entry '
                    . (is_scalar($entry) ? var_export($entry, true) : get_debug_type($entry))
                    . ' is not a known modality'
                );
            }
            $kinds[] = $kind;
        }

        $familyRaw = $providerConfig['mediaFamily'] ?? null;
        if ($familyRaw !== null && !is_string($familyRaw)) {
            throw new InvalidArgumentException('CapabilityDiscoverer::fromConfig(): mediaFamily must be a string, got ' . get_debug_type($familyRaw));
        }
        // A human who declares kinds but no family means "the sdapi we speak" —
        // the implemented default; explicit unknown/custom are honoured verbatim.
        $family = $familyRaw === null
            ? EndpointFamily::SdApi
            : (EndpointFamily::tryFrom($familyRaw) ?? throw new InvalidArgumentException(
                "CapabilityDiscoverer::fromConfig(): mediaFamily '{$familyRaw}' is not a known endpoint family"
            ));

        $baseUrl = $providerConfig['mediaBaseUrl'] ?? $providerConfig['baseUrl'] ?? '';
        if (!is_string($baseUrl)) {
            throw new InvalidArgumentException('CapabilityDiscoverer::fromConfig(): base URL setting must be a string');
        }
        $apiKeyRef = $providerConfig['mediaApiKey'] ?? $providerConfig['apiKey'] ?? null;
        if ($apiKeyRef !== null && !is_string($apiKeyRef)) {
            throw new InvalidArgumentException('CapabilityDiscoverer::fromConfig(): apiKey ref setting must be a string');
        }

        $capability = MediaCapability::new($family)
            ->withKinds($kinds)
            ->withBaseUrl($baseUrl)
            ->markDeclared();
        if (is_string($apiKeyRef) && $apiKeyRef !== '') {
            $capability = $capability->withApiKeyRef($apiKeyRef);
        }

        return $capability;
    }

    /**
     * The bounded GET ladder. Order is deliberate: /sdapi/v1/cmd-flags is
     * unmistakably A1111; /object_info unmistakably ComfyUI; /model_info before
     * /v1/models because SGLang-Diffusion serves BOTH (W0.1 probe) and the
     * plain OpenAI door would otherwise shadow it. First affirmative match
     * wins; every failure mode answers null.
     */
    public function probe(SdTransport $transport): ?MediaCapability
    {
        try {
            $sdapi = $transport->request('GET', self::ROUTES['sdapi']);
            if ($sdapi->is2xx() && $sdapi->decodedJson() !== null) {
                return $this->sdapiCapability();
            }
        } catch (Throwable) {
            // fail-open: a refused/unreachable dial is absence, not an error.
        }

        try {
            $comfy = $transport->request('GET', self::ROUTES['comfyui']);
            if ($comfy->is2xx() && is_array($comfy->decodedJson())) {
                return MediaCapability::new(EndpointFamily::ComfyUi)
                    ->withKinds([MediaKind::Image])
                    ->markProbed();
            }
        } catch (Throwable) {
        }

        try {
            $sglang = $transport->request('GET', self::ROUTES['sglang-diffusion']);
            $models = $sglang->is2xx() ? $sglang->decodedJson() : null;
            if (is_array($models) && $models !== []) {
                return $this->sglangCapability($models);
            }
        } catch (Throwable) {
        }

        try {
            $openai = $transport->request('GET', self::ROUTES['openai-images']);
            if ($openai->is2xx() && is_array($openai->decodedJson())) {
                return MediaCapability::new(EndpointFamily::OpenAiImages)
                    ->withKinds([MediaKind::Image])
                    ->withSupport(MediaCapability::SUPPORT_TXT2IMG, true)
                    ->markProbed();
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Per-route verdicts for a future doctor surface (plan W-step: honest
     * diagnosis without a bespoke error model): 'ok' | 'absent' | 'error' |
     * 'not-probed', plus 'config' => 'declared' | 'none'. Same fail-open law:
     * never throws.
     *
     * @param  array<string, mixed>  $providerConfig
     * @return array<string, string>
     */
    public function diagnose(array $providerConfig, ?SdTransport $transport): array
    {
        $verdicts = [];
        try {
            $verdicts['config'] = $this->fromConfig($providerConfig) !== null ? 'declared' : 'none';
        } catch (InvalidArgumentException $e) {
            $verdicts['config'] = 'invalid: ' . $e->getMessage();
        }
        if ($transport === null) {
            foreach (self::ROUTES as $name => $path) {
                $verdicts[$name] = 'not-probed';
            }

            return $verdicts;
        }
        foreach (self::ROUTES as $name => $path) {
            try {
                $result = $transport->request('GET', $path);
                $verdicts[$name] = ($result->is2xx() && $result->decodedJson() !== null) ? 'ok' : 'absent';
            } catch (Throwable) {
                $verdicts[$name] = 'error';
            }
        }

        return $verdicts;
    }

    /**
     * The implemented dialect's floor knowledge (crush_media §4.2-§4.6):
     * txt2img+img2img are the two API routes, inpaint rides img2img+mask,
     * script-info/alwayson_scripts exist per /sdapi/v1/scripts, progress via
     * /sdapi/v1/progress (§4.6). Upscale is honestly false-v1: the plan's W
     * steps model it through img2img hires paths, not a dedicated route.
     */
    private function sdapiCapability(): MediaCapability
    {
        return MediaCapability::new(EndpointFamily::SdApi)
            ->withKinds([MediaKind::Image])
            ->withSupport(MediaCapability::SUPPORT_TXT2IMG, true)
            ->withSupport(MediaCapability::SUPPORT_IMG2IMG, true)
            ->withSupport(MediaCapability::SUPPORT_INPAINT, true)
            ->withSupport(MediaCapability::SUPPORT_UPSCALE, false)
            ->withSupport(MediaCapability::SUPPORT_VIDEO, false)
            ->withSupport(MediaCapability::SUPPORT_SCRIPT_INFO, true)
            ->withSupport(MediaCapability::SUPPORT_PROGRESS, true)
            ->markProbed();
    }

    /**
     * Map the W0.1 /model_info shape (per-model task_type / supported_task_types
     * / is_image_gen) onto kinds and flags. Wan2.2-T2V answers is_image_gen
     * false + text-to-video task types — exactly the "detectable but
     * unimplemented" case EndpointFamily::isImplemented() refuses downstream.
     *
     * @param  array<array-key, mixed>  $models
     */
    private function sglangCapability(array $models): MediaCapability
    {
        $capability = MediaCapability::new(EndpointFamily::SglangDiffusion)->markProbed();
        $kinds = [];
        foreach ($models as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tasks = [];
            foreach (['task_type', 'supported_task_types'] as $field) {
                $raw = $entry[$field] ?? null;
                foreach (is_array($raw) ? $raw : (is_string($raw) ? [$raw] : []) as $task) {
                    if (is_string($task)) {
                        $tasks[] = strtolower($task);
                    }
                }
            }
            if (($entry['is_image_gen'] ?? null) === true) {
                $kinds[] = MediaKind::Image;
            }
            foreach ($tasks as $task) {
                if (str_contains($task, 'image')) {
                    $kinds[] = MediaKind::Image;
                }
                if (str_contains($task, 'video')) {
                    $kinds[] = MediaKind::Video;
                    $capability = $capability->withSupport(MediaCapability::SUPPORT_VIDEO, true);
                }
            }
        }

        return $capability->withKinds($kinds === [] ? [MediaKind::Image] : $kinds);
    }
}
