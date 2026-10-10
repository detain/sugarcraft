<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\PlayCommand;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\CommandEffectKind;
use SugarCraft\Crush\Host\Commands\PlayHostCommand;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Support\MediaStore;

/**
 * The W5.4 /play door: usage refusals, the traversal-guess law, the typed-
 * path-is-the-consent law, and the session-store artifact lookup — all
 * without touching a terminal or a decoder.
 */
final class PlayHostCommandTest extends TestCase
{
    private string $phRoot = '';

    protected function setUp(): void
    {
        $this->phRoot = sys_get_temp_dir() . '/w5b-play-' . getmypid() . '-' . substr(hash('sha256', uniqid('ph', true)), 0, 8);
        \mkdir($this->phRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->phRemoveTree($this->phRoot);
    }

    public function testBareCommandAnswersUsageAndRaisesNothing(): void
    {
        $result = (new PlayHostCommand($this->phRoot))->run(CommandContext::new(), '/play');

        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer));
        self::assertStringContainsString(PlayCommand::USAGE, $result->rows[1]->content);
    }

    public function testHelpFlagShortCircuitsToTheControlBlock(): void
    {
        $result = (new PlayHostCommand($this->phRoot))->run(CommandContext::new(), '/play --help');

        self::assertStringContainsString('space play/pause', $result->rows[1]->content);
        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function phRefusalProvider(): array
    {
        return [
            'upward relative guess' => ['../private/clip.mp4', 'relative'],
            'remote http' => ['http://host/clip.mp4', 'remote'],
            'remote data uri' => ['data:video/mp4;base64,AAAA', 'remote'],
            'two targets' => ['one.mp4 two.mp4', 'exactly one'],
            'unknown flag' => ['--zoom clip.mp4', 'unknown flag'],
        ];
    }

    /**
     * @dataProvider phRefusalProvider
     */
    public function testRefusedSpellingsNeverReachTheFilesystem(string $arg, string $needle): void
    {
        $result = (new PlayHostCommand($this->phRoot))->run(CommandContext::new(), '/play ' . $arg);

        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer));
        self::assertStringContainsString($needle, strtolower($result->rows[1]->content));
    }

    public function testTypedAbsolutePathRaisesThePlayerOnTheRealPath(): void
    {
        $clip = $this->phRoot . '/typed clip.mp4';
        $link = $this->phRoot . '/link target.mp4';
        \file_put_contents($clip, 'fake mp4 bytes');
        \symlink($clip, $link);

        $result = (new PlayHostCommand($this->phRoot))
            ->run(CommandContext::new(), '/play ' . escapeshellarg($link));

        $effect = $result->effect(CommandEffectKind::OpenVideoPlayer);
        self::assertNotNull($effect);
        self::assertSame(realpath($clip), $effect->videoPath(), 'the resolved file, not the spelled link');
        self::assertStringContainsString('playing', $result->rows[1]->content);
    }

    public function testMissingAbsolutePathIsALoudRefusalNotAScreen(): void
    {
        $result = (new PlayHostCommand($this->phRoot))
            ->run(CommandContext::new(), '/play ' . $this->phRoot . '/nope.mp4');

        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer));
        self::assertStringContainsString('no readable file', $result->rows[1]->content);
    }

    public function testSessionArtifactResolvesByFilenameStemAndId(): void
    {
        $store = MediaStore::forSession('w5b-session', $this->phRoot);
        $artifact = $store->put(str_repeat('M', 64), 'mp4', ['number' => 7], MediaKind::Video);
        $path = (string) $artifact->path();
        $filename = basename($path);
        $id = null;

        foreach ($store->list() as $row) {
            if ($row['filename'] === $filename) {
                $id = (string) $row['id'];
            }
        }

        $command = new PlayHostCommand($this->phRoot);

        foreach ([$filename, pathinfo($filename, PATHINFO_FILENAME), (string) $id] as $spelling) {
            $effect = $command->run(CommandContext::new(sessionId: 'w5b-session'), '/play ' . $spelling)
                ->effect(CommandEffectKind::OpenVideoPlayer);
            self::assertNotNull($effect, 'artifact lookup by ' . $spelling);
            self::assertSame(realpath($path), $effect->videoPath());
        }
    }

    public function testUnknownArtifactNameRefusesWithThePathEscapeHatch(): void
    {
        \mkdir($this->phRoot . '/w5b-empty', 0700, true);

        $result = (new PlayHostCommand($this->phRoot))
            ->run(CommandContext::new(sessionId: 'w5b-empty'), '/play never-made.mp4');

        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer));
        self::assertStringContainsString('no playable artifact named never-made.mp4', $result->rows[1]->content);
    }

    public function testSidecarsAndStillImagesAreNotPlayableArtifacts(): void
    {
        $store = MediaStore::forSession('w5b-mixed', $this->phRoot);
        $png = $store->put(str_repeat('P', 64), 'png', ['number' => 1], MediaKind::Image);

        $result = (new PlayHostCommand($this->phRoot))
            ->run(CommandContext::new(sessionId: 'w5b-mixed'), '/play ' . pathinfo((string) $png->path(), PATHINFO_FILENAME));

        self::assertNull($result->effect(CommandEffectKind::OpenVideoPlayer), 'a PNG name is not a clip');
    }

    private function phRemoveTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->phRemoveTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
