<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Capability;

use InvalidArgumentException;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Crush\Media\MediaKind;

/**
 * What one SD endpoint is known/able to do — value object, plan W1.2.
 *
 * Two provenance flags carry the whole policy (crush_media §10.1-7): declared
 * (a human told us via config — authority) and probed (a discovery GET
 * observed it). Callers gate features on flags/declares(), never on which
 * path produced the object. The merge rule (config authority > probe) lives
 * in mergedFrom() below and is the reason both flags exist.
 *
 * Secrets law (mystage §6.3 via plan): apiKeyRef stores a '${VAR}' NAME,
 * never a credential value — capability objects get logged and checkpointed.
 */
final readonly class MediaCapability
{
    use Mutable;

    public const SUPPORT_TXT2IMG = 'txt2img';
    public const SUPPORT_IMG2IMG = 'img2img';
    public const SUPPORT_INPAINT = 'inpaint';
    public const SUPPORT_UPSCALE = 'upscale';
    public const SUPPORT_VIDEO = 'video';
    public const SUPPORT_SCRIPT_INFO = 'script-info';
    public const SUPPORT_PROGRESS = 'progress';

    /** The closed support vocabulary — withSupport() refuses anything else. */
    public const SUPPORTS = [
        self::SUPPORT_TXT2IMG,
        self::SUPPORT_IMG2IMG,
        self::SUPPORT_INPAINT,
        self::SUPPORT_UPSCALE,
        self::SUPPORT_VIDEO,
        self::SUPPORT_SCRIPT_INFO,
        self::SUPPORT_PROGRESS,
    ];

    /** Numeric envelope keys (min/max pairs); Wave-3 form sliders bind to these. */
    public const BOUNDS = [
        'width_min', 'width_max',
        'height_min', 'height_max',
        'steps_min', 'steps_max',
        'duration_min', 'duration_max',
    ];

    private function __construct(
        private readonly EndpointFamily $family = EndpointFamily::Unknown,
        private readonly array $kinds = [],
        private readonly string $baseUrl = '',
        private readonly ?string $apiKeyRef = null,
        private readonly bool $apiKeyRefSet = false,
        private readonly array $bounds = [],
        private readonly array $supports = [],
        private readonly bool $declared = false,
        private readonly bool $probed = false,
    ) {
    }

    public static function new(EndpointFamily $family = EndpointFamily::Unknown): self
    {
        return new self(family: $family);
    }

    public function family(): EndpointFamily
    {
        return $this->family;
    }

    /**
     * Modalities this endpoint serves.
     *
     * @return list<MediaKind>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKeyRef(): ?string
    {
        return $this->apiKeyRef;
    }

    public function apiKeyRefIsSet(): bool
    {
        return $this->apiKeyRefSet;
    }

    /**
     * @return array<string, int> subset of self::BOUNDS
     */
    public function bounds(): array
    {
        return $this->bounds;
    }

    /**
     * @return array<string, bool> subset of self::SUPPORTS
     */
    public function supports(): array
    {
        return $this->supports;
    }

    public function isDeclared(): bool
    {
        return $this->declared;
    }

    public function isProbed(): bool
    {
        return $this->probed;
    }

    /** Affirmative support only — an absent key is "not known to support". */
    public function declares(string $support): bool
    {
        if (!in_array($support, self::SUPPORTS, true)) {
            throw new InvalidArgumentException("MediaCapability::declares(): '{$support}' is not a modelled support flag");
        }

        return ($this->supports[$support] ?? null) === true;
    }

    public function withFamily(EndpointFamily $family): self
    {
        return $this->mutate(['family' => $family]);
    }

    /**
     * @param list<MediaKind> $kinds deduplicated, insertion order kept
     */
    public function withKinds(array $kinds): self
    {
        foreach ($kinds as $kind) {
            if (!$kind instanceof MediaKind) {
                throw new InvalidArgumentException('MediaCapability::withKinds(): every entry must be a MediaKind, got ' . get_debug_type($kind));
            }
        }

        return $this->mutate(['kinds' => array_values(array_unique($kinds, SORT_REGULAR))]);
    }

    public function withBaseUrl(string $baseUrl): self
    {
        return $this->mutate(['baseUrl' => $baseUrl]);
    }

    /** A '${VAR}' reference name — never a secret value (class docblock). */
    public function withApiKeyRef(?string $apiKeyRef): self
    {
        return $this->mutate(['apiKeyRef' => $apiKeyRef, 'apiKeyRefSet' => true]);
    }

    public function withBound(string $key, int $value): self
    {
        if (!in_array($key, self::BOUNDS, true)) {
            throw new InvalidArgumentException("MediaCapability::withBound(): '{$key}' is not a modelled bound");
        }

        return $this->mutate(['bounds' => $this->bounds + [$key => $value]]);
    }

    public function withSupport(string $key, bool $value): self
    {
        if (!in_array($key, self::SUPPORTS, true)) {
            throw new InvalidArgumentException("MediaCapability::withSupport(): '{$key}' is not a modelled support flag");
        }

        return $this->mutate(['supports' => $this->supports + [$key => $value]]);
    }

    public function markDeclared(): self
    {
        return $this->mutate(['declared' => true]);
    }

    public function markProbed(): self
    {
        return $this->mutate(['probed' => true]);
    }

    /**
     * Config-authority merge (crush_media §10.1-7, plan W1.2): `$this` is the
     * declared capability, $probe is what discovery saw. Identity (family,
     * kinds, baseUrl) always stays with the declaration; the probe may only
     * FILL support flags and bounds the declaration left blank — it can never
     * overwrite, negate or invent a modality a human spelled out. The result
     * carries isProbed() when the probe contributed anything.
     */
    public function mergedFrom(MediaCapability $probe): self
    {
        $supports = $this->supports;
        $bounds = $this->bounds;
        $contributed = false;
        foreach ($probe->supports() as $key => $value) {
            if (!array_key_exists($key, $supports)) {
                $supports[$key] = $value;
                $contributed = true;
            }
        }
        foreach ($probe->bounds() as $key => $value) {
            if (!array_key_exists($key, $bounds)) {
                $bounds[$key] = $value;
                $contributed = true;
            }
        }

        $merged = $this->mutate([
            'supports' => $supports,
            'bounds' => $bounds,
            'probed' => $this->probed || $contributed,
        ]);
        if (!$this->apiKeyRefSet && $probe->apiKeyRefSet) {
            $merged = $merged->withApiKeyRef($probe->apiKeyRef);
        }

        return $merged;
    }
}
