<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Core\Concerns\Mutable;

/**
 * The aggregate outcome of one generation request — plan W1.1.
 *
 * Deliberately transport-agnostic (crush_media §4.2/§4.6): an sdapi sync
 * response lands as artifacts + raw `info` + echoed `parameters`; an async
 * video job (SGLang-Diffusion /v1/videos, W0.1) first lands as nothing but a
 * jobRef the MediaJob state machine polls, then fills artifacts on completion.
 * Parsing the actual sdapi JSON into this shape is W1.4's Response builder —
 * this step only fixes the vocabulary both halves speak.
 *
 * Unset != empty holds for `info` and `jobRef` (sentinel-paired); `parameters`
 * is an echo bag that defaults to empty, and artifacts is an ordered append
 * list (batch order == server index order, §4.2 index_of_first_image rides
 * artifact infotext/token values, not this list's shape).
 */
final readonly class MediaResponse
{
    use Mutable;

    private function __construct(
        private readonly array $artifacts = [],
        private readonly ?string $info = null,
        private readonly bool $infoSet = false,
        private readonly array $parameters = [],
        private readonly ?string $jobRef = null,
        private readonly bool $jobRefSet = false,
    ) {
    }

    /**
     * @param  list<MediaArtifact>  $artifacts
     */
    public static function new(array $artifacts = []): self
    {
        return new self(artifacts: $artifacts);
    }

    /**
     * @return list<MediaArtifact>
     */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    public function isEmpty(): bool
    {
        return $this->artifacts === [];
    }

    /** Raw sdapi `info` string (JSON server-side; kept unparsed — W1.4 decodes). */
    public function info(): ?string
    {
        return $this->info;
    }

    public function infoIsSet(): bool
    {
        return $this->infoSet;
    }

    /**
     * The `parameters` echo from the response (sdapi returns the effective
     * request per image) — free-form, server-version-owned.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Opaque async-job handle (§4.6 task ids, /v1/videos job ids). Distinct
     * from MediaJob::$taskId (client-chosen) and MediaArtifact — it names the
     * SERVER's queue entry for the whole request.
     */
    public function jobRef(): ?string
    {
        return $this->jobRef;
    }

    public function jobRefIsSet(): bool
    {
        return $this->jobRefSet;
    }

    public function withArtifact(MediaArtifact $artifact): self
    {
        return $this->mutate(['artifacts' => [...$this->artifacts, $artifact]]);
    }

    public function withInfo(?string $info): self
    {
        return $this->mutate(['info' => $info, 'infoSet' => true]);
    }

    /** @param array<string, mixed> $parameters */
    public function withParameters(array $parameters): self
    {
        return $this->mutate(['parameters' => $parameters]);
    }

    public function withJobRef(?string $jobRef): self
    {
        return $this->mutate(['jobRef' => $jobRef, 'jobRefSet' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['artifacts' => array_map(static fn (MediaArtifact $a): array => $a->toArray(), $this->artifacts)];
        if ($this->infoSet) {
            $out['info'] = $this->info;
        }
        if ($this->parameters !== []) {
            $out['parameters'] = $this->parameters;
        }
        if ($this->jobRefSet) {
            $out['job_ref'] = $this->jobRef;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $raw = $data['artifacts'] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('MediaResponse::fromArray(): "artifacts" expects a list, got ' . get_debug_type($raw));
        }
        $response = self::new();
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('MediaResponse::fromArray(): each artifact must be a map, got ' . get_debug_type($entry));
            }
            $response = $response->withArtifact(MediaArtifact::fromArray($entry));
        }
        foreach (['info' => 'withInfo', 'job_ref' => 'withJobRef'] as $key => $method) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value !== null && !is_string($value)) {
                    throw new \InvalidArgumentException("MediaResponse::fromArray(): '{$key}' expects string or null, got " . get_debug_type($value));
                }
                $response = $response->{$method}($value);
            }
        }
        $parameters = $data['parameters'] ?? [];
        if (!is_array($parameters)) {
            throw new \InvalidArgumentException('MediaResponse::fromArray(): "parameters" expects an object/map, got ' . get_debug_type($parameters));
        }
        if ($parameters !== []) {
            $response = $response->withParameters($parameters);
        }
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, ['artifacts', 'info', 'parameters', 'job_ref'], true)) {
                throw new \InvalidArgumentException('MediaResponse::fromArray(): unknown response field ' . (is_string($key) ? "'{$key}'" : get_debug_type($key)));
            }
        }

        return $response;
    }
}
