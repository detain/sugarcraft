<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\TestCase;

/**
 * Proves the W5.1 sugar-reel require is wired end-to-end: the manifest line,
 * the CI path-repo injection, and the autoload closure all resolve.
 *
 * Mirrors plan_crush_media.md W5.1. The dependency is a `require` bump ONLY —
 * no `repositories[]` block ever lands in the lib manifest (path-repo closure
 * law, AGENTS.md), because a published lib's `../sugar-reel` path repo is a
 * hard fatal for anyone cloning the split repository. CI re-injects the
 * closure before each install; this test fails loudly if that wiring rots.
 */
final class ReelDependencyTest extends TestCase
{
    /**
     * The player surface later Wave-5 lanes (W5.4 VideoPlayerScreen) and the
     * render-mode vocabulary (W5.7 RenderModeCommand) are built on.
     */
    public function testTheReelPlayerSurfaceAutoloads(): void
    {
        self::assertTrue(class_exists('SugarCraft\Reel\Player'));
        self::assertTrue(class_exists('SugarCraft\Reel\Render\RendererFactory'));
    }

    public function testTheReelRenderModeVocabularyAutoloads(): void
    {
        self::assertTrue(class_exists('SugarCraft\Reel\Render\Mode') || enum_exists('SugarCraft\Reel\Render\Mode'));
    }

    /**
     * The lib ships standalone: the require line must exist in the manifest
     * and NO path-repo entry may accompany it.
     */
    public function testTheManifestCarriesTheRequireWithoutAPathRepo(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest);
        self::assertArrayHasKey('sugarcraft/sugar-reel', $manifest['require']);

        $repositories = $manifest['repositories'] ?? [];
        foreach ($repositories as $repository) {
            self::assertNotSame(
                '../sugar-reel',
                $repository['url'] ?? null,
                'a per-lib path repo for sugar-reel must never be committed',
            );
        }
    }
}
