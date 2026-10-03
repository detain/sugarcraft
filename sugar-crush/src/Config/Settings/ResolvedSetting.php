<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * A key's effective value with its provenance: which source won, which file
 * that was, which lower sources it shadowed, and whether an environment
 * variable or flag locks it against an edit that would not take.
 */
final class ResolvedSetting
{
    /** @param list<SettingSource> $shadowed lower-precedence sources that also set the key, highest first */
    private function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly SettingSource $source,
        public readonly ?string $sourcePath,
        public readonly array $shadowed,
        public readonly bool $locked,
        public readonly ?string $lockReason,
    ) {
    }

    /** @param list<SettingSource> $shadowed */
    public static function new(
        string $key,
        mixed $value,
        SettingSource $source,
        ?string $sourcePath = null,
        array $shadowed = [],
        ?string $lockReason = null,
    ): self {
        return new self($key, $value, $source, $sourcePath, array_values($shadowed), $lockReason !== null, $lockReason);
    }

    /** Whether no source set the key and the schema default is in force. */
    public function isDefault(): bool
    {
        return $this->source === SettingSource::Default;
    }
}
