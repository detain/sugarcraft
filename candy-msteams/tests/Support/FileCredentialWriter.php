<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use SugarCraft\Plugins\Channel\CredentialWriter;

/**
 * A test double for the host's single credential writer (plan O9): it applies
 * the change to the record on disk and writes it back 0600, the way candy-code's
 * ChannelsStore does. Bundle tests build their CredentialStore with one.
 */
final class FileCredentialWriter implements CredentialWriter
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function mutate(\Closure $change): array
    {
        $data = [];
        if (\is_file($this->path)) {
            $decoded = \json_decode((string) \file_get_contents($this->path), true, 64, \JSON_THROW_ON_ERROR);
            $data = \is_array($decoded) ? $decoded : [];
        }
        $next = $change($data);
        $dir = \dirname($this->path);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o700, true);
        }
        \file_put_contents($this->path, \json_encode($next, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
        \chmod($this->path, 0o600);

        return $next;
    }
}
