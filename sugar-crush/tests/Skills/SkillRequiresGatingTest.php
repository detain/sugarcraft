<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillFrontmatter;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillOrigin;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14k: a skill may declare what the host must provide (`requires`
 * bins / anyBins / env, and `os`), and a skill whose requirements are not met
 * is left out with a recorded reason instead of being offered to the model.
 */
final class SkillRequiresGatingTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private const MISSING_BIN = 'sugar-crush-no-such-binary-5-14k';
    private const MISSING_ENV = 'SUGARCRUSH_TEST_NO_SUCH_VAR_5_14K';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/skill_requires_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    // ---------------------------------------------------------------- parsing

    public function testTopLevelRequiresAndOsAreNormalized(): void
    {
        $meta = SkillFrontmatter::fromParsed([
            'requires' => ['bins' => 'sh', 'any-bins' => ['sh', self::MISSING_BIN], 'env' => ['PATH', 'PATH']],
            'os' => ['Linux', 'macos', 'windows', 'freebsd', 'netbsd', 'openbsd', 'sunos', 'darwin', 'win32'],
        ], 'x');

        self::assertSame(['sh'], $meta->requires['bins']);
        self::assertSame(['sh', self::MISSING_BIN], $meta->requires['anyBins']);
        self::assertSame(['PATH'], $meta->requires['env'], 'duplicates collapse');
        self::assertContains('linux', $meta->requires['os']);
        self::assertContains('darwin', $meta->requires['os'], 'macos is darwin');
        self::assertContains('win32', $meta->requires['os'], 'windows is win32');
        self::assertCount(7, $meta->requires['os']);
    }

    public function testASkillWithoutRequirementsHasAnEmptyDeclaration(): void
    {
        self::assertSame([], SkillFrontmatter::fromParsed(['description' => 'x'], 'x')->requires);
        self::assertSame([], SkillFrontmatter::fromParsed(['requires' => []], 'x')->requires);
    }

    public function testTheOpenClawAndNanobotMetadataBagsAreReadWhenTopLevelKeysAreAbsent(): void
    {
        $claw = SkillFrontmatter::fromParsed(['metadata' => ['openclaw' => [
            'requires' => ['bins' => ['sh'], 'anyBins' => ['sh'], 'config' => ['browser.enabled']],
            'os' => ['linux', 'darwin', 'win32', 'freebsd', 'openbsd', 'netbsd', 'sunos'],
        ]]], 'x');
        $nano = SkillFrontmatter::fromParsed(['metadata' => ['nanobot' => ['requires' => ['env' => ['PATH']]]]], 'x');

        self::assertSame(['sh'], $claw->requires['bins']);
        self::assertArrayNotHasKey('config', $claw->requires, 'a vendor-only key is ignored, not refused');
        self::assertSame(['PATH'], $nano->requires['env']);
    }

    public function testTopLevelKeysWinOverTheMetadataBag(): void
    {
        $meta = SkillFrontmatter::fromParsed([
            'os' => ['linux', 'darwin', 'win32', 'freebsd', 'openbsd', 'netbsd', 'sunos'],
            'metadata' => ['openclaw' => ['requires' => ['bins' => [self::MISSING_BIN]]]],
        ], 'x');

        self::assertArrayNotHasKey('bins', $meta->requires);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function mistypedRequirements(): iterable
    {
        yield 'unknown requires key' => [['requires' => ['binz' => ['sh']]], '"binz"'];
        yield 'requires is a list' => [['requires' => ['sh']], '"requires"'];
        yield 'requires is a string' => [['requires' => 'sh'], '"requires"'];
        yield 'bins entry not a string' => [['requires' => ['bins' => ['sh', 7]]], 'requires.bins[1]'];
        yield 'empty env name' => [['requires' => ['env' => ['']]], 'requires.env[0]'];
        yield 'os is a map' => [['os' => ['linux' => true]], '"os"'];
        yield 'unknown platform' => [['os' => ['plan9']], 'plan9'];
        yield 'metadata bag mistyped' => [['metadata' => ['openclaw' => ['requires' => ['bins' => 3]]]], 'metadata.openclaw.requires.bins'];
    }

    /** @param array<mixed> $frontmatter */
    #[DataProvider('mistypedRequirements')]
    public function testAMistypedDeclarationIsRefusedNamingTheField(array $frontmatter, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($named);
        SkillFrontmatter::fromParsed($frontmatter, 'x');
    }

    // ----------------------------------------------------------- the check

    public function testUnmetRequirementsNamesEveryMissingPiece(): void
    {
        $bin = $this->dir . '/bin';
        \mkdir($bin);
        \file_put_contents($bin . '/present-tool', "#!/bin/sh\n");
        \chmod($bin . '/present-tool', 0755);
        $env = static fn (string $name): string|false => ['SET' => 'v', 'EMPTY' => ''][$name] ?? false;

        $requires = [
            'bins' => ['present-tool', 'absent-tool'],
            'anyBins' => ['absent-a', 'absent-b'],
            'env' => ['SET', 'EMPTY', 'UNSET'],
            'os' => ['darwin', 'win32'],
        ];

        self::assertSame([
            'CLI absent-tool',
            'any CLI of absent-a, absent-b',
            'env EMPTY',
            'env UNSET',
            'OS darwin or win32 (this is linux)',
        ], SkillRegistry::unmetRequirements($requires, $bin, $env, 'linux'));

        self::assertSame([], SkillRegistry::unmetRequirements([
            'bins' => ['present-tool'],
            'anyBins' => ['absent-a', 'present-tool'],
            'env' => ['SET'],
            'os' => ['linux'],
        ], $bin, $env, 'linux'));
        self::assertSame([], SkillRegistry::unmetRequirements([]));
    }

    public function testTheReasonAndThePlatformSpelling(): void
    {
        self::assertSame(
            'skill "gh-pr" is unavailable on this host: needs CLI gh; needs env GITHUB_TOKEN',
            SkillRegistry::unavailableReason('gh-pr', ['CLI gh', 'env GITHUB_TOKEN']),
        );
        self::assertContains(SkillRegistry::currentPlatform(), SkillFrontmatter::OS_NAMES);
    }

    // ------------------------------------------------------------- readers

    public function testAnAvailableSkillParsesAndCarriesItsRequirements(): void
    {
        $skill = Skill::parse("---\ndescription: d\nrequires:\n  bins: [sh]\n  env: [PATH]\n---\nBody\n", 'ok');

        self::assertSame(['bins' => ['sh'], 'env' => ['PATH']], $skill->requires);
        self::assertSame($skill->requires, $skill->toArray()['requires']);
        self::assertSame($skill->requires, $skill->withName('renamed')->requires);
        self::assertSame($skill->requires, $skill->withOrigin(SkillOrigin::User)->requires);
    }

    public function testAnUnavailableSkillIsRefusedWithEveryReason(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('skill "gh-pr" is unavailable on this host: needs CLI ' . self::MISSING_BIN . '; needs env ' . self::MISSING_ENV);
        Skill::parse(
            "---\nrequires:\n  bins: [sh, " . self::MISSING_BIN . "]\n  env: [" . self::MISSING_ENV . "]\n---\nBody\n",
            'gh-pr',
        );
    }

    public function testAMistypedSkillReportsTheTypoBeforeAnyMissingBinary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"description"');
        Skill::parse("---\ndescription: 42\nrequires:\n  bins: [" . self::MISSING_BIN . "]\n---\nBody\n", 'both');
    }

    // ------------------------------------------------------------ registry

    public function testTheRegistryRefusesAnUnavailableSkillFromAnyRoute(): void
    {
        $registry = new SkillRegistry();
        $make = static fn (string $name, array $requires): Skill => new Skill(
            name: $name, description: 'd', userInvocable: true, disableModelInvocation: false,
            allowedTools: null, disallowedTools: null, model: null, effort: 'medium',
            context: 'thread', paths: [], content: 'c', sourcePath: '', requires: $requires,
        );

        $registry->register([$make('fine', ['bins' => ['sh']]), $make('gated', ['bins' => [self::MISSING_BIN]])]);
        $registry->registerFromManifest([
            'name' => 'gated-manifest', 'description' => 'd', 'disableModelInvocation' => false,
            'userInvocable' => true, 'context' => 'thread', 'paths' => [], 'sourcePath' => '/x/SKILL.md',
            'requires' => ['env' => [self::MISSING_ENV]],
        ]);

        self::assertNotNull($registry->get('fine'));
        self::assertNull($registry->get('gated'));
        self::assertNull($registry->get('gated-manifest'));
        self::assertSame(['fine'], \array_keys($registry->all()));
        self::assertSame(['gated', 'gated-manifest'], \array_keys($registry->unavailable()));
        self::assertStringContainsString('needs CLI ' . self::MISSING_BIN, $registry->unavailable()['gated']);
        self::assertStringContainsString('needs env ' . self::MISSING_ENV, $registry->unavailable()['gated-manifest']);
    }

    public function testLoadAllLeavesAnUnavailableProjectSkillOutAndRecordsWhy(): void
    {
        $home = $this->useHomeSandbox($this->dir . '/home');
        try {
            $root = $this->dir . '/project';
            foreach ([
                'needs-gh' => "---\ndescription: gated\nrequires:\n  bins: [" . self::MISSING_BIN . "]\n---\nBody\n",
                'wrong-os' => "---\ndescription: gated\nos: [" . (SkillRegistry::currentPlatform() === 'win32' ? 'linux' : 'win32') . "]\n---\nBody\n",
                'has-sh' => "---\ndescription: fine\nrequires:\n  bins: [sh]\n---\nBody\n",
            ] as $name => $body) {
                \mkdir("{$root}/.sugar-crush/skills/{$name}", 0700, true);
                \file_put_contents("{$root}/.sugar-crush/skills/{$name}/SKILL.md", $body);
            }

            $registry = new SkillRegistry();
            $manager = new SkillManager(new SkillLoader(false), $registry);
            $manager->loadAll($root);

            self::assertNotNull($registry->get('has-sh'));
            self::assertNull($registry->get('needs-gh'));
            self::assertNull($registry->get('wrong-os'));

            $reasons = [];
            foreach ($manager->skipped() as $path => $reason) {
                if (\str_contains($path, '/.sugar-crush/skills/')) {
                    $reasons[\basename(\dirname($path))] = $reason;
                }
            }
            \ksort($reasons);
            self::assertSame(['needs-gh', 'wrong-os'], \array_keys($reasons));
            self::assertStringContainsString('unavailable on this host: needs CLI ' . self::MISSING_BIN, $reasons['needs-gh']);
            self::assertStringContainsString('needs OS', $reasons['wrong-os']);
        } finally {
            $this->restoreHomeSandbox();
            unset($home);
        }
    }
}
