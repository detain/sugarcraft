<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * The on-disk record for plugin channels — the file ruling 4b named.
 *
 * `~/.candy-code/channels.json`, written only through
 * {@see SugarCraft\Core\Util\AtomicJsonFile} with permissions `0600`: the store
 * holds channel credentials and policy, never world-readable by construction.
 * The settings file carries exactly one channel concern — the
 * `trustedBundleChannels` name list — because a setting is something the doc
 * surface and the schema generator both render; secrets have no business
 * there. Everything else about channels lives here.
 *
 * Scope law (ruling D6): per-channel enablement, per-account configuration and
 * per-session rules all grow INTO this file. Nothing in it assumes one global
 * policy; the first shape minted is deliberately the smallest — the
 * quarantine ledger `BundleChannelLoader` consults, a set of entrypoint
 * paths already seen by an earlier launch, so a channel file new to this
 * machine is listed and held for one launch before any of its code executes.
 */
final class ChannelsStore
{
    public const FILE_NAME = 'channels.json';

    /** @var \Closure():string resolves the store path without touching HOME mid-run */
    private \Closure $configDir;

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param (callable():string)|null $configDir overrides the directory the
     *                                            record lives in (tests point it at a temp tree)
     */
    private function __construct(?callable $configDir)
    {
        $this->configDir = null === $configDir
            ? static fn (): string => Bootstrap::trustedConfigDirPath()
            : \Closure::fromCallable($configDir);
        $this->data = $this->read();
    }

    public static function create(?callable $configDir = null): self
    {
        return new self($configDir);
    }

    /** Has an earlier launch already seen this entrypoint file? */
    public function seenEntrypoint(string $path): bool
    {
        $seen = $this->data['seenEntrypoints'] ?? null;

        return \is_array($seen) && true === ($seen[$path] ?? false);
    }

    /**
     * Record the entrypoint as seen. Called when it is FIRST noticed — the
     * quarantine holds it this launch and the next launch finds it here.
     */
    public function recordEntrypoint(string $path): void
    {
        $seen = \is_array($this->data['seenEntrypoints'] ?? null) ? $this->data['seenEntrypoints'] : [];
        $seen[$path] = true;
        $this->data['seenEntrypoints'] = $seen;
        $this->write();
    }

    /** @return array<string, mixed> the whole record, for the registry to grow later */
    public function all(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->path();
        if (!\is_string($path) || !file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function write(): void
    {
        $path = $this->path();
        if (!\is_string($path)) {
            return;
        }

        AtomicJsonFile::new($path)->withPermissions(0600)->write($this->data);
    }

    private function path(): ?string
    {
        $dir = ($this->configDir)();

        return '' === $dir ? null : $dir . '/' . self::FILE_NAME;
    }
}
