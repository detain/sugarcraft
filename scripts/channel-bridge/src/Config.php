<?php

declare(strict_types=1);

namespace ChannelBridge;

/**
 * The bridge's config file: enablement and policy ONLY (owner ruling D4 —
 * credentials live in `channels.json`, never here).
 *
 * The reader parses at the boundary: every getter returns a typed value
 * with a documented default, so internal code never re-validates.
 */
final class Config
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly array $data,
        public readonly string $dir,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $dir = '.'): self
    {
        return new self($data, $dir);
    }

    public static function readFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("config not readable: {$path}");
        }
        try {
            $data = \json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("config is not valid JSON ({$path}): {$e->getMessage()}", 0, $e);
        }
        if (!\is_array($data)) {
            throw new \RuntimeException("config must hold a JSON object: {$path}");
        }

        return new self($data, \dirname((string) \realpath($path)));
    }

    /**
     * `~`-expanded, absolute-or-home-anchored path values resolve relative
     * to the config file's directory (so a sample config is drop-in).
     */
    public function path(string $key, string $default): string
    {
        $value = (string) ($this->data[$key] ?? $default);
        $expanded = self::expandHome($value);
        if ($expanded[0] === '/') {
            return $expanded;
        }

        return \dirname($expanded) === '.' ? $this->dir . '/' . $expanded : $expanded;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? self::expandHome($value) : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : (\is_numeric($value) ? (int) $value : $default);
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->data[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }

        return $default;
    }

    /** @return array<string, mixed> */
    public function section(string $key): array
    {
        $value = $this->data[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        return \array_values(\array_filter($value, static fn (mixed $v): bool => \is_string($v)));
    }

    private static function expandHome(string $path): string
    {
        if ($path === '' || $path[0] !== '~') {
            return $path;
        }
        $home = (string) (\getenv('HOME') ?: \sys_get_temp_dir());

        return $home . \substr($path, 1);
    }
}
