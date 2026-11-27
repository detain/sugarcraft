<?php

declare(strict_types=1);

namespace SugarCraft\PluginInstaller\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Holds the stand-in table that ComposerIntegrationTest relies on to the real
 * manifests. Kept apart from that class on purpose: ComposerIntegrationTest skips
 * everything when composer is not on PATH, and this check needs no composer, so
 * it must still run on hosts and runners without it.
 */
final class StubClosureDriftTest extends TestCase
{
    /**
     * The requirements each stand-in keeps and drops, against the real manifests.
     * Kept: the stand-in declares it, so Composer resolves it from the scratch
     * tree. Dropped: the offline run cannot fetch it (react/*, psr/http-message,
     * sugar-pty, sugar-ansi-parser, sugar-mcp-client), or it is a host extension
     * the test host may lack (ext-ffi is reached through sugar-pty, ext-intl through
     * sugar-core).
     *
     * testStubsDropExactlyTheDocumentedRequirements holds this table to the real
     * manifests, so a new requirement fails there.
     */
    private const STUBBED = [
        'sugarcraft/sugar-core' => [
            'kept' => [],
            'dropped' => [
                'ext-intl',
                'ext-mbstring',
                'react/event-loop',
                'react/promise',
                'sugarcraft/sugar-ansi-parser',
                'sugarcraft/sugar-pty',
            ],
        ],
        'sugarcraft/sugar-plugins' => [
            'kept' => ['sugarcraft/sugar-core'],
            'dropped' => [
                'ext-tokenizer',
                'psr/http-message',
                'react/event-loop',
                'react/promise',
                'sugarcraft/sugar-mcp-client',
            ],
        ],
    ];

    /**
     * Fails when a real manifest gains or loses a requirement without STUBBED
     * saying whether the stand-in keeps or drops it.
     */
    public function testStubsDropExactlyTheDocumentedRequirements(): void
    {
        $monorepo = dirname(__DIR__, 2);
        foreach (['sugar-core', 'sugar-plugins'] as $lib) {
            if (!is_file($monorepo . '/' . $lib . '/composer.json')) {
                $this->markTestSkipped("$lib manifest is not beside the installer (a split-repo checkout)");
            }
        }

        foreach (self::STUBBED as $package => $split) {
            $lib = substr($package, strlen('sugarcraft/'));
            $manifest = json_decode((string) file_get_contents($monorepo . '/' . $lib . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $real = array_values(array_filter(
                array_keys($manifest['require'] ?? []),
                static fn (string $name): bool => $name !== 'php',
            ));
            $documented = array_merge($split['kept'], $split['dropped']);
            sort($real);
            sort($documented);

            $this->assertSame($real, $documented, "$package gained or lost a requirement: decide whether its stand-in keeps or drops it, then update STUBBED and README.md");
        }
    }
}
