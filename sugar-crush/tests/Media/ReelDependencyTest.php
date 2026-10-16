<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\TestCase;

/**
 * Proves the W5.1 sugar-video require is wired end-to-end: the manifest line,
 * the CI path-repo injection, and the autoload closure all resolve.
 *
 * Mirrors plan_crush_media.md W5.1. The dependency is a `require` bump ONLY —
 * no `repositories[]` block ever lands in the lib manifest (path-repo closure
 * law, AGENTS.md), because a published lib's `../sugar-video` path repo is a
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
        self::assertTrue(class_exists('SugarCraft\Video\Player'));
        self::assertTrue(class_exists('SugarCraft\Video\Render\RendererFactory'));
    }

    public function testTheReelRenderModeVocabularyAutoloads(): void
    {
        self::assertTrue(class_exists('SugarCraft\Video\Render\Mode') || enum_exists('SugarCraft\Video\Render\Mode'));
    }

    /**
     * The lib ships standalone: the require line must exist in the manifest
     * and NO path-repo entry may accompany it.
     *
     * The probe targets the COMMITTED blob via `git show HEAD:`, not the
     * working copy — CI's `check-path-repos.php --fix --strict-closure` step
     * injects the path-repo closure into the working-tree manifest before
     * install, so a file read would invert green/red between CI and local.
     * The require/absence law binds the commit, and HEAD is exactly the
     * commit under test in a CI checkout.
     */
    public function testTheManifestCarriesTheRequireWithoutAPathRepo(): void
    {
        $committed = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' show HEAD:sugar-crush/composer.json 2>&1');
        self::assertNotNull($committed, 'git show HEAD:sugar-crush/composer.json produced no output — is this checkout a git work tree?');

        $manifest = json_decode(
            $committed,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest);
        self::assertArrayHasKey('sugarcraft/sugar-video', $manifest['require']);

        $repositories = $manifest['repositories'] ?? [];
        foreach ($repositories as $repository) {
            self::assertNotSame(
                '../sugar-video',
                $repository['url'] ?? null,
                'a per-lib path repo for sugar-video must never be committed',
            );
        }
    }
}
