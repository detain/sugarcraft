<?php

declare(strict_types=1);

namespace SugarCraft\Releases\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Releases\ReleaseSource;
use SugarCraft\Releases\SelfUpdate;
use SugarCraft\Releases\UpdateOptions;

/** The engine seams hosts adopt through: translator, error stream, usage exit. */
final class EngineSeamsTest extends TestCase
{
    public function testUsageViolationsExitTwoThroughTheHostTranslator(): void
    {
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        $keys = [];
        $exit = SelfUpdate::new(ReleaseSource::new('a/b', 'tool'))
            ->withErrorStream($stream)
            ->withTranslator(static function (string $key, array $params) use (&$keys): string {
                $keys[] = [$key, $params['app'] ?? null];

                return '<' . $key . '>';
            })
            ->run(UpdateOptions::new()->withCheck()->withRollback(), 'v1.0.0');

        self::assertSame(SelfUpdate::EXIT_USAGE, $exit);
        \rewind($stream);
        self::assertSame("tool: <selfupdate.flags_exclusive>\n<selfupdate.usage>\n", \stream_get_contents($stream));
        self::assertSame([['selfupdate.flags_exclusive', 'tool'], ['selfupdate.usage', 'tool']], $keys);
    }

    public function testDefaultWordingNamesTheAppAndSourceIsExposed(): void
    {
        $source = ReleaseSource::new('a/b', 'tool')->withComposerPackage('acme/tool');
        $engine = SelfUpdate::new($source);
        self::assertSame($source, $engine->source());
        $text = $engine->composerRefusal('some/project');
        self::assertStringContainsString('tool:', $text);
        self::assertStringContainsString('composer update acme/tool', $text);
        self::assertStringContainsString('composer global update acme/tool', $text);
        self::assertStringContainsString('git pull && composer install', $engine->composerRefusal('acme/tool'));
    }

    public function testASwapLauncherSeamReplacesTheDetachedHelper(): void
    {
        $dir = \sys_get_temp_dir() . '/sr-seam-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        \file_put_contents($dir . '/t.phar', 'X');
        \file_put_contents($dir . '/t.phar.old', 'OLD');
        $calls = [];
        $stream = \fopen('php://temp', 'r+');
        try {
            $exit = SelfUpdate::new(ReleaseSource::new('a/b', 'tool'))
                ->withPharPath($dir . '/t.phar')->withWindows(true)->withErrorStream($stream)
                ->withSwapLauncher(static function (int $pid, string $new, string $phar) use (&$calls): bool {
                    $calls[] = [$new, $phar];

                    return true;
                })
                ->run(UpdateOptions::new()->withRollback(), 'v1.0.0');
            self::assertSame(SelfUpdate::EXIT_OK, $exit);
            self::assertSame([[$dir . '/t.phar.new', $dir . '/t.phar']], $calls);
            self::assertSame('OLD', \file_get_contents($dir . '/t.phar.new'));
        } finally {
            foreach (\glob($dir . '/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($dir);
        }
    }
}
