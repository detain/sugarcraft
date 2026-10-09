<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One produced media output — the unit a MediaResponse carries.
 *
 * Part of plan W1.1 (plan_crush_media.md). A1111 returns base64 blobs inline
 * (sdapi §4.2 `images[]`), the save_images path returns only file paths on
 * server disk, and SGLang-Diffusion's async video jobs hand back a downloadable
 * file (W0.1 probe: /v1/videos contract) — three arrival shapes, one artifact.
 * Which of `path`/`bytes` is populated tells the consumer nothing else; the
 * renderer lane (W6) decides display, the persistence lane (W5) decides where
 * bytes land.
 *
 * Artifact-bytes-never-cross-wire law (crush_media §10.1, W6.4): toArray()
 * deliberately OMITS `bytes` — the serialisable form is for transcripts,
 * checkpoints and the web/ACP media channel where large payloads must ride a
 * file reference, not the JSON echo. fromArray() therefore round-trips every
 * field EXCEPT bytes, and bytes is refused as an input key rather than ignored,
 * so a forged serialized artifact cannot smuggle inline payload past the door.
 */
final readonly class MediaArtifact
{
    use Mutable;

    /** Display-protocol hints the renderer may advertise (candy-mosaic ladder; crush_media §2 vocabulary). */
    public const PROTOCOL_HINTS = ['sixel', 'kitty', 'iterm2', 'halfblock'];

    private function __construct(
        private readonly MediaKind $kind,
        private readonly ?string $path = null,
        private readonly bool $pathSet = false,
        private readonly ?string $bytes = null,
        private readonly bool $bytesSet = false,
        private readonly ?string $protocolHint = null,
        private readonly bool $protocolHintSet = false,
        private readonly ?array $infotext = null,
        private readonly bool $infotextSet = false,
        private readonly array $tokenValues = [],
    ) {
    }

    public static function new(MediaKind $kind): self
    {
        return new self(kind: $kind);
    }

    public function kind(): MediaKind
    {
        return $this->kind;
    }

    /** Server-side or local file path (save_images mode / downloaded job). */
    public function path(): ?string
    {
        return $this->path;
    }

    /** Raw media bytes; never serialized (see class docblock). */
    public function bytes(): ?string
    {
        return $this->bytes;
    }

    public function protocolHint(): ?string
    {
        return $this->protocolHint;
    }

    /**
     * Parsed A1111 infotext of this image (the §4.2 `info` JSON's infotexts[]
     * row): parameters plus free-form extras. Kept as a bag — its schema is
     * server-version-owned, not ours.
     *
     * @return array<string, mixed>|null
     */
    public function infotext(): ?array
    {
        return $this->infotext;
    }

    /**
     * Filename-token values for the W1.9 token-templated save names; the
     * per-batch all_seeds list rides here too (plan W4.5), which is why they
     * serialize but bytes do not.
     *
     * @return array<string, mixed>
     */
    public function tokenValues(): array
    {
        return $this->tokenValues;
    }

    public function pathIsSet(): bool
    {
        return $this->pathSet;
    }

    public function bytesIsSet(): bool
    {
        return $this->bytesSet;
    }

    public function withPath(?string $path): self
    {
        return $this->mutate(['path' => $path, 'pathSet' => true]);
    }

    public function withBytes(?string $bytes): self
    {
        return $this->mutate(['bytes' => $bytes, 'bytesSet' => true]);
    }

    /** Free-string hint; PROTOCOL_HINTS is the current vocabulary, unenforced. */
    public function withProtocolHint(?string $hint): self
    {
        return $this->mutate(['protocolHint' => $hint, 'protocolHintSet' => true]);
    }

    /** @param array<string, mixed>|null $infotext */
    public function withInfotext(?array $infotext): self
    {
        return $this->mutate(['infotext' => $infotext, 'infotextSet' => true]);
    }

    public function withTokenValue(string $token, mixed $value): self
    {
        return $this->mutate(['tokenValues' => $this->tokenValues + [$token => $value]]);
    }

    /** @param array<string, mixed> $tokenValues */
    public function withTokenValues(array $tokenValues): self
    {
        return $this->mutate(['tokenValues' => $tokenValues]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind->value];
        if ($this->pathSet) {
            $out['path'] = $this->path;
        }
        if ($this->protocolHintSet) {
            $out['protocol_hint'] = $this->protocolHint;
        }
        if ($this->infotextSet) {
            $out['infotext'] = $this->infotext;
        }
        if ($this->tokenValues !== []) {
            $out['token_values'] = $this->tokenValues;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kindRaw = $data['kind'] ?? null;
        $kind = is_string($kindRaw) ? MediaKind::tryFrom($kindRaw) : null;
        if ($kind === null) {
            throw new \InvalidArgumentException(
                'MediaArtifact::fromArray(): "kind" must be a known MediaKind value, got '
                . (is_scalar($kindRaw) ? var_export($kindRaw, true) : get_debug_type($kindRaw))
            );
        }
        if (array_key_exists('bytes', $data)) {
            throw new \InvalidArgumentException('MediaArtifact::fromArray(): "bytes" is never serialized (artifact-bytes-never-cross-wire, crush_media §10.1)');
        }
        $artifact = self::new($kind);
        foreach (['path' => 'withPath', 'protocol_hint' => 'withProtocolHint'] as $key => $method) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value !== null && !is_string($value)) {
                    throw new \InvalidArgumentException("MediaArtifact::fromArray(): '{$key}' expects string or null, got " . get_debug_type($value));
                }
                $artifact = $artifact->{$method}($value);
            }
        }
        if (array_key_exists('infotext', $data)) {
            $infotext = $data['infotext'];
            if ($infotext !== null && !is_array($infotext)) {
                throw new \InvalidArgumentException('MediaArtifact::fromArray(): "infotext" expects array or null, got ' . get_debug_type($infotext));
            }
            $artifact = $artifact->withInfotext($infotext);
        }
        $tokens = $data['token_values'] ?? [];
        if (!is_array($tokens)) {
            throw new \InvalidArgumentException('MediaArtifact::fromArray(): "token_values" expects an object/map, got ' . get_debug_type($tokens));
        }
        if ($tokens !== []) {
            $artifact = $artifact->withTokenValues($tokens);
        }
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('MediaArtifact::fromArray(): keys must be strings');
            }
            if (!in_array($key, ['kind', 'path', 'protocol_hint', 'infotext', 'token_values'], true)) {
                throw new \InvalidArgumentException("MediaArtifact::fromArray(): unknown artifact field '{$key}'");
            }
        }

        return $artifact;
    }
}
