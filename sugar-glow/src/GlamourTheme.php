<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

/**
 * Glamour-style theme JSON loader.
 *
 * Mirrors charmbracelet/glow's theme loading behaviour.
 *
 * @see https://github.com/charmbracelet/glow
 */
final class GlamourTheme
{
    /**
     * @param string $blockPrefix Text to prepend to block elements
     * @param string $blockSuffix Text to append to block elements
     * @param string $indentToken Token used for indentation (e.g., "→")
     * @param int $margin Margin around elements
     * @param array<string, string> $chroma Token-type => SGR color mapping
     */
    public function __construct(
        public readonly string $blockPrefix = '',
        public readonly string $blockSuffix = '',
        public readonly string $indentToken = '    ',
        public readonly int $margin = 0,
        public readonly array $chroma = [],
    ) {}

    /**
     * Parse a Glamour theme from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return new self();
        }

        return new self(
            blockPrefix: (string) ($data['block_prefix'] ?? ''),
            blockSuffix: (string) ($data['block_suffix'] ?? ''),
            indentToken: (string) ($data['indent_token'] ?? '    '),
            margin: (int) ($data['margin'] ?? 0),
            chroma: self::parseChroma($data['chroma'] ?? null),
        );
    }

    /**
     * Load a Glamour theme from a JSON file.
     */
    public static function fromFile(string $path): self
    {
        if (!is_readable($path)) {
            return new self();
        }

        $json = file_get_contents($path);
        return $json !== false ? self::fromJson($json) : new self();
    }

    /**
     * Resolve a chroma token to its SGR color.
     *
     * @return string|null SGR color code (e.g., "31" for red) or null if not found
     */
    public function resolve(string $token): ?string
    {
        return $this->chroma[$token] ?? null;
    }

    /**
     * @param mixed $chroma
     * @return array<string, string>
     */
    private static function parseChroma(mixed $chroma): array
    {
        if (!is_array($chroma)) {
            return [];
        }

        $result = [];
        foreach ($chroma as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
