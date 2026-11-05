<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Guards OD-14's pinning half (lane G6-P, reviews-1103): the release build's
 * sibling packages must come from the TESTED checkout, not from wherever
 * Packagist's dev-master stream happened to be at the moment of install.
 *
 * WHY THIS FILE EXISTS AT ALL. `scripts/pin-siblings.php` runs between the
 * release workflow's checkout and its `composer install`, and again between
 * install and build. It is the ONLY machinery standing between "the tag
 * pointed at a CI-green SHA" and "the shipped phar's sibling bytes came from
 * that SHA" — without it, a release of candy-code/v1.2.3 vendors whatever
 * sugar-* HEAD was minutes ago, and the phar is untraceable to any tested
 * tree (review.md 6.1 OD-8: pinned to the tested tree's SHAs). A guard that
 * exists to fail-closed must itself be pinned fail-closed: each leg below has
 * the opposite polarity where a naive re-implementation could quietly pass.
 *
 * THE THREE THINGS THE PIN CARRIES, each with its own leg:
 *  - the FULL TRANSITIVE closure of sugarcraft/* requires gets a path repo —
 *    pinning only the direct requires would let a transitive sibling keep
 *    floating (and the closure is exactly what tools/check-path-repos.php
 *    demands of the root manifest, so the two walkers stay the same graph);
 *  - `symlink:false` is LOAD-BEARING, not stylistic: candy-code's
 *    build-phar.php Guard 4 refuses to package a tree containing a symlink,
 *    and the monorepo's own CI doctrine of symlink:true path repos would trip
 *    it on every release build (its words: the phar would freeze working-tree
 *    code). A pin that merely checked "a repositories[] row appears" would
 *    stay green after someone flipped symlink:false → true and broke releases.
 *  - `verify` after install is the float detector: an unsatisfied local
 *    constraint falls back to Packagist silently; this aborts (exit 1) and
 *    names the floaters, and a lock with ZERO siblings is a solver anomaly it
 *    also refuses (exit 2) rather than acquits.
 *
 * THE COMMITTED TREE NEVER MOVES. inject rewrites the manifest in the runner's
 * ephemeral checkout only; the policy check
 * `php tools/check-path-repos.php --no-lib-path-repos` on the committed tree
 * is pinned live below (the real monorepo), so a lane that "helpfully" landed
 * an injected manifest goes red here, not in release night.
 *
 * Deliberately standalone (`--no-configuration tools/tests/`, per
 * CONTRIBUTING.md): it spawns the script as a subprocess over a temp fixture
 * tree — no lib autoloader needed, mints no candy-code `*Test.php`, so no
 * durations.tsv row and no suite-figure change (the tools-guards job runs
 * this directory via the phpunit PHAR, same as its neighbours). exec() over
 * proc_open() on purpose: the tree-wide child-lifetime census pins the number
 * of proc_open sites and a new test must not redden it as an accident.
 */
final class ReleaseSiblingPinsTest extends TestCase
{
    use TmpTree;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('release_sibling_pins_test_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        \file_put_contents($path, \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @return array{exit:int,output:string} */
    private function runScript(array $args): array
    {
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(\dirname(__DIR__, 2) . '/scripts/pin-siblings.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . \escapeshellarg((string) $arg);
        }
        $output = [];
        $exitCode = 0;
        \exec($cmd . ' 2>&1', $output, $exitCode);

        return ['exit' => $exitCode, 'output' => \implode("\n", $output)];
    }

    /**
     * A three-node fixture: candy-demo → sugarcraft/sugar-a → sugarcraft/sugar-b.
     *
     * The nested edge is the whole point of the fixture: an implementation
     * that only walked the app's own requires would inject sugar-a and ship
     * sugar-b as a float, and every byte-level symptom would look correct.
     */
    private function makeChainFixture(): string
    {
        $root = $this->tmpDir . '/monorepo';
        \mkdir($root . '/candy-demo', 0777, true);
        \mkdir($root . '/sugar-a', 0777, true);
        \mkdir($root . '/sugar-b', 0777, true);
        $this->writeJson($root . '/candy-demo/composer.json', [
            'name' => 'candy/candy-demo',
            'require' => ['php' => '^8.3', 'sugarcraft/sugar-a' => '@dev'],
        ]);
        $this->writeJson($root . '/sugar-a/composer.json', [
            'name' => 'sugarcraft/sugar-a',
            'require' => ['sugarcraft/sugar-b' => '@dev'],
        ]);
        $this->writeJson($root . '/sugar-b/composer.json', [
            'name' => 'sugarcraft/sugar-b',
            'require' => ['php' => '^8.3'],
        ]);

        return $root;
    }

    public function testInjectWalksTheTransitiveClosureAndWritesTheSidecar(): void
    {
        $root = $this->makeChainFixture();
        $app = $root . '/candy-demo';

        $run = $this->runScript(['inject', $app, '--root', $root, '--sha', self::SHA, '--ref', 'candy-demo/v1.0.0']);
        $this->assertSame(0, $run['exit'], $run['output']);

        /** @var array<string,mixed> $manifest */
        $manifest = \json_decode((string) \file_get_contents($app . '/composer.json'), true);
        $urls = [];
        foreach ($manifest['repositories'] as $repo) {
            $urls[(string) $repo['url']] = $repo;
        }
        $this->assertSame(['../sugar-a', '../sugar-b'], \array_keys($urls));
        // symlink:false is load-bearing (Guard 4 law): BOTH legs — the flag
        // present AND true must never sneak in as the sole shape.
        foreach ($urls as $url => $repo) {
            $this->assertSame('path', $repo['type'], $url);
            $this->assertSame(false, $repo['options']['symlink'], $url);
        }
        // The @dev constraint documents intent and must survive byte-kept.
        $this->assertSame('@dev', $manifest['require']['sugarcraft/sugar-a']);

        /** @var array<string,mixed> $sidecar */
        $sidecar = \json_decode((string) \file_get_contents($app . '/sibling-pins.json'), true);
        $this->assertSame(self::SHA, $sidecar['built_at_sha']);
        $this->assertSame('candy-demo/v1.0.0', $sidecar['ref']);
        $this->assertSame('direct', $sidecar['pinned']['sugarcraft/sugar-a']['via']);
        $this->assertSame('@dev', $sidecar['pinned']['sugarcraft/sugar-a']['constraint']);
        $this->assertSame('transitive', $sidecar['pinned']['sugarcraft/sugar-b']['via']);
        $this->assertSame('monorepo@' . self::SHA, $sidecar['pinned']['sugarcraft/sugar-b']['source']);
    }

    public function testInjectIsIdempotentAcrossTwoRuns(): void
    {
        $root = $this->makeChainFixture();
        $app = $root . '/candy-demo';

        $first = $this->runScript(['inject', $app, '--root', $root, '--sha', self::SHA]);
        $this->assertSame(0, $first['exit'], $first['output']);
        $manifestAfterOne = (string) \file_get_contents($app . '/composer.json');

        $second = $this->runScript(['inject', $app, '--root', $root, '--sha', self::SHA]);
        $this->assertSame(0, $second['exit'], $second['output']);

        // Double-injection must not stack duplicate repositories[] rows — a
        // rerun in a retry loop is the normal shape of CI.
        $this->assertSame($manifestAfterOne, (string) \file_get_contents($app . '/composer.json'));
        /** @var array<string,mixed> $manifest */
        $manifest = \json_decode($manifestAfterOne, true);
        $this->assertCount(2, $manifest['repositories']);
    }

    public function testInjectPreservesForeignReposAndDedupesPreExistingPathRows(): void
    {
        $root = $this->makeChainFixture();
        $app = $root . '/candy-demo';
        $this->writeJson($app . '/composer.json', [
            'name' => 'candy/candy-demo',
            'require' => ['sugarcraft/sugar-a' => '@dev'],
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://repo.example.test'],
                ['type' => 'path', 'url' => '../sugar-a', 'options' => ['symlink' => true]],
            ],
        ]);

        $run = $this->runScript(['inject', $app, '--root', $root, '--sha', self::SHA]);
        $this->assertSame(0, $run['exit'], $run['output']);

        /** @var array<string,mixed> $manifest */
        $manifest = \json_decode((string) \file_get_contents($app . '/composer.json'), true);
        $this->assertSame(['composer', 'path', 'path'], \array_column($manifest['repositories'], 'type'));
        $this->assertSame('https://repo.example.test', $manifest['repositories'][0]['url']);
        // Dedupe is BY URL BASENAME: sugar-a stays exactly one row (the
        // pre-existing symlink:true one is left alone — not this script's job
        // to rewrite what it did not add), sugar-b is the only addition.
        $this->assertSame('../sugar-a', $manifest['repositories'][1]['url']);
        $this->assertSame('../sugar-b', $manifest['repositories'][2]['url']);
        $this->assertCount(3, $manifest['repositories']);
    }

    public function testInjectRefusesASiblingWithNoDirectoryInTheCheckout(): void
    {
        $root = $this->tmpDir . '/monorepo';
        \mkdir($root . '/candy-demo', 0777, true);
        $this->writeJson($root . '/candy-demo/composer.json', [
            'name' => 'candy/candy-demo',
            'require' => ['sugarcraft/sugar-ghost' => '@dev'],
        ]);

        // Fail-closed: a required sibling the checkout does not carry aborts
        // the release at 2 — falling through would ship exactly the float the
        // injection was supposed to eliminate.
        $run = $this->runScript(['inject', $root . '/candy-demo', '--root', $root, '--sha', self::SHA]);
        $this->assertSame(2, $run['exit'], $run['output']);
        $this->assertStringContainsString('sugarcraft/sugar-ghost', $run['output']);
        $this->assertStringContainsString('has no directory', $run['output']);
    }

    public function testVerifyAbortsWhenALockStillResolvesASiblingFromTheNetwork(): void
    {
        $app = $this->tmpDir . '/candy-demo';
        \mkdir($app, 0777, true);
        $this->writeJson($app . '/composer.json', ['name' => 'candy/candy-demo']);
        $this->writeJson($app . '/composer.lock', [
            'packages' => [
                ['name' => 'sugarcraft/sugar-a', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/sugarcraft/sugar-a/zipball/zz']],
                ['name' => 'sugarcraft/sugar-b', 'dist' => ['type' => 'path', 'url' => '../sugar-b']],
                ['name' => 'react/promise', 'dist' => ['type' => 'zip', 'url' => 'https://api.github.com/repos/reactphp/promise/zipball/qq']],
            ],
        ]);

        $run = $this->runScript(['verify', $app]);
        $this->assertSame(1, $run['exit'], $run['output']);
        $this->assertStringContainsString('sugarcraft/sugar-a', $run['output']);
        // The float list must name ONLY the float: sugar-b is pinned, and the
        // non-sibling network package is nobody's business here.
        $this->assertStringNotContainsString('sugarcraft/sugar-b resolved', $run['output']);
        $this->assertStringNotContainsString('react/promise', $run['output']);
        $this->assertStringContainsString('verified 2', $run['output']);
    }

    public function testVerifyAcceptsAnAllPathResolvedLock(): void
    {
        $app = $this->tmpDir . '/candy-demo';
        \mkdir($app, 0777, true);
        $this->writeJson($app . '/composer.json', ['name' => 'candy/candy-demo']);
        $this->writeJson($app . '/composer.lock', [
            'packages' => [
                ['name' => 'sugarcraft/sugar-a', 'dist' => ['type' => 'path', 'url' => '../sugar-a']],
                ['name' => 'sugarcraft/sugar-b', 'dist' => ['url' => '../sugar-b']],
            ],
        ]);

        // The second entry carries only a `../` URL and no dist.type: the
        // path detector accepts BOTH shapes, or real composer.locks (which
        // spell path dists as type:path) and hand-built fixtures diverge.
        $run = $this->runScript(['verify', $app]);
        $this->assertSame(0, $run['exit'], $run['output']);
        $this->assertStringContainsString('verified 2', $run['output']);
    }

    public function testVerifyRefusesALockThatCarriesNoSiblingsAtAll(): void
    {
        $app = $this->tmpDir . '/candy-demo';
        \mkdir($app, 0777, true);
        $this->writeJson($app . '/composer.json', ['name' => 'candy/candy-demo']);
        $this->writeJson($app . '/composer.lock', [
            'packages' => [['name' => 'react/promise', 'dist' => ['type' => 'zip']]],
        ]);

        // An app whose manifest requires siblings but whose lock contains
        // NONE is a solver anomaly; returning 0 here would make "we forgot to
        // install" indistinguishable from "everything pinned".
        $run = $this->runScript(['verify', $app]);
        $this->assertSame(2, $run['exit'], $run['output']);
        $this->assertStringContainsString('zero sugarcraft', $run['output']);
    }

    public function testInjectRejectsMalformedArguments(): void
    {
        $root = $this->makeChainFixture();
        $app = $root . '/candy-demo';

        // A truncated SHA cannot certify provenance: refuse, do not pad.
        $short = $this->runScript(['inject', $app, '--root', $root, '--sha', 'deadbeef']);
        $this->assertSame(2, $short['exit'], $short['output']);
        $this->assertStringContainsString('Usage:', $short['output']);

        // Missing --root would silently scan the wrong tree.
        $noRoot = $this->runScript(['inject', $app, '--sha', self::SHA]);
        $this->assertSame(2, $noRoot['exit'], $noRoot['output']);

        // An unknown mode is usage, not success.
        $bogus = $this->runScript(['frobnicate', $app]);
        $this->assertSame(2, $bogus['exit'], $bogus['output']);

        // Neither rejection may have touched the manifest.
        $this->assertStringNotContainsString('repositories', (string) \file_get_contents($app . '/composer.json'));
    }

    public function testTheCommittedTreeKeepsTheNoLibPathRepoPolicy(): void
    {
        // The other half of the mechanism: inject is transient. The REAL
        // monorepo tree (this checkout) must keep passing the inverse policy
        // check with zero lib-manifest path repos — proof that the release
        // doctrine adds pins without moving the committed rule that every
        // published lib's own composer.json carries `@dev` requires ONLY.
        $script = \dirname(__DIR__) . '/check-path-repos.php';
        $this->assertFileExists($script);
        // The script's own root resolution is the env override its sibling
        // guard (CheckPathReposTest) already uses; pointing it at THIS
        // checkout's root keeps the verdict about the tree under test.
        $cmd = 'SUGARCRAFT_CHECK_PATH_REPOS_ROOT=' . \escapeshellarg(\dirname(__DIR__, 2))
            . ' ' . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script)
            . ' --no-lib-path-repos 2>&1';
        $output = [];
        $exit = 0;
        \exec($cmd, $output, $exit);
        $this->assertSame(0, $exit, \implode("\n", $output));
    }
}
