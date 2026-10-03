<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Validator;

use SugarCraft\Crush\Config\Settings\SettingValidator;

/**
 * An absolute path to an existing, executable regular file — the same shape
 * {@see \SugarCraft\Crush\MCP\ClaudeCodeMcpServer::fromGrant()} insists on for
 * `claudeMcpBinary`, checked before the write instead of at the next launch.
 */
final class ExecutableValidator implements SettingValidator
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value) || !str_starts_with($value, '/')) {
            return 'must be an absolute path';
        }

        if (!is_file($value) || !is_executable($value)) {
            return 'must name an existing executable file';
        }

        return null;
    }
}
