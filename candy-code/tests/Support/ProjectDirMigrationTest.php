<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\PolicySnapshot;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Support\ProjectRoot;

/**
 * The project-level sibling of the home migration (plan_rename.md 8A item 4): // @rename-keep
 * a checkout whose state still lives in `<root>/.sugar-crush` moves to // @rename-keep
 * `<root>/.candy-code` on the first root resolution, and the move changes
 * nothing the trust machinery looks at.
 *
 * THE TRUST CLAIM IS KEYED BY ROOT, and that is the whole reason this is a
 * move rather than a re-approval flow: `trustedProjectHooks`,
 * `trustedProjectMcp` and `trustedProjectCommands` store the project ROOT's
 * path, never the dot-directory inside it, so the very same root that was
 * trusted before the rename resolves identically after it. The hooks test
 * measures the second half — hook identities name|event|command survive a
 * `rename()` byte for byte, so the PolicySnapshot comparison sees no change
 * and quarantines nothing.
 */
final class ProjectDirMigrationTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = sys_get_temp_dir() . '/candy_code_projdir_' . uniqid((string) getmypid(), true);
        mkdir($this->project, 0700, true);

        ProjectRoot::forget();
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        ProjectRoot::forget();
        $this->purgeProjectTree();

        parent::tearDown();
    }

    public function testResolveMovesTheProjectDirAndRecordsTheAdviceNotice(): void
    {
        $this->seedLegacyProjectDir();
        $oldBytes = file_get_contents($this->project . '/.sugar-crush/settings.json'); // @rename-keep

        self::assertSame($this->project, ProjectRoot::resolve($this->project));

        self::assertDirectoryDoesNotExist($this->project . '/.sugar-crush'); // @rename-keep
        self::assertSame($oldBytes, file_get_contents($this->project . '/.candy-code/settings.json'));
        self::assertContains(
            'moved .sugar-crush -> .candy-code (if this project tracks it in git, commit the move)', // @rename-keep
            RuntimeNoticeSink::drain(),
        );
    }

    public function testSettingsReadFromTheMovedDirWithTheRootTrustUnchanged(): void
    {
        $this->seedLegacyProjectDir();
        ProjectRoot::resolve($this->project);

        // Trust arrives as "this root is trusted" — the same verdict the
        // pre-move launch computed, because the lists key on the root path.
        $layer = LayeredSettings::projectLayer($this->project, trusted: true);
        self::assertSame(['lanef-probe-skill'], $layer['disabledSkills'] ?? null);
    }

    public function testProjectHooksStillLoadFromTheMovedFile(): void
    {
        $this->seedLegacyProjectDir();
        $fingerprintBefore = PolicySnapshot::fingerprint($this->project . '/.sugar-crush/hooks.yaml'); // @rename-keep

        ProjectRoot::resolve($this->project);

        $moved = $this->project . '/.candy-code/hooks.yaml';
        self::assertSame(
            $fingerprintBefore,
            PolicySnapshot::fingerprint($moved),
            'a move that changed a byte would read as a policy change and quarantine',
        );

        $manager = new HookManager(new HookRegistry());
        $manager->loadFromFile($moved);
        self::assertNotNull($manager->hook('PreToolUse', 'lanef-probe'), 'the project hooks still load after the move');
    }

    private function seedLegacyProjectDir(): void
    {
        $old = $this->project . '/.sugar-crush'; // @rename-keep
        mkdir($old, 0700, true); // @rename-keep
        file_put_contents($old . '/settings.json', '{"disabledSkills":["lanef-probe-skill"]}'); // @rename-keep
        file_put_contents($old . '/hooks.yaml', <<<'YAML'
            hooks:
              PreToolUse:
                - name: lanef-probe
                  matcher: '^Test$'
                  command: 'printf "lanef"'
                  description: 'f1 migration probe'
            YAML); // @rename-keep
    }

    private function purgeProjectTree(): void
    {
        if (!is_dir($this->project)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->project);
    }
}
