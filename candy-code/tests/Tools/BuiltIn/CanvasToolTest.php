<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\CanvasTool;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\TakesToolCallId;

/**
 * C-TOOL-6.f pins for the Canvas tool: catalog shape, the store seam it
 * shares with generated images (bound-session landing, 0600, id/path in the
 * result text, pathFor round trip), the refusal trio, the 'main' fallback,
 * and the promise that the terminal paints no page (every image leg the TUI
 * reads is bytes-gated, and this result carries a path with no bytes).
 */
final class CanvasToolTest extends TestCase
{
    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->mediaRoot = \sys_get_temp_dir() . '/canvas-media-' . \uniqid('r', true);
    }

    protected function tearDown(): void
    {
        $this->wipeCanvasTree($this->mediaRoot);
    }

    public function testCatalogRegistersCanvasAtPositionFortyFiveWithNoAskAndTheDeclaredConcerns(): void
    {
        $entry = null;
        foreach (ToolCatalog::entries() as $candidate) {
            if ($candidate->name === CanvasTool::NAME) {
                self::assertNull($entry, 'the catalog named Canvas twice');
                $entry = $candidate;
            }
        }
        self::assertNotNull($entry, 'Canvas is not in the built-in catalog');
        self::assertSame(45, $entry->position);
        self::assertSame(ToolPermissionClass::NoAsk, $entry->permission);
        self::assertSame(
            'save a self-contained HTML page as a session artifact the web client can display',
            $entry->gloss,
        );
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(CanvasTool::NAME));

        $class = CanvasTool::class;
        self::assertTrue(is_subclass_of($class, BuildsFromCatalog::class));
        self::assertTrue(is_subclass_of($class, DelegatesToEngine::class));
        self::assertTrue(is_subclass_of($class, TakesToolCallId::class));
        self::assertFalse(
            is_subclass_of($class, ParallelSafe::class),
            'Canvas writes through the store; nothing has opted it into concurrent dispatch',
        );
    }

    public function testBoundSessionLandsTheFileAtSixZeroZeroAndTheResultNamesIdPathAndBytes(): void
    {
        $session = 'ses-' . \uniqid(getmypid() . '_', true);
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')->withSessionId($session);
        $tool = CanvasTool::new()->withEngine($engine)->withMediaRoot($this->mediaRoot);

        $html = "<!doctype html>\n<html><body><h1>Reported</h1></body></html>";
        $result = $tool->execute(['id' => 'call-7', 'html' => $html, 'title' => 'Report']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('call-7', $result->toolCallId());

        // Exact result shape: 'Canvas saved — <title>: artifact <id>, <path> (<n> bytes)'.
        $matched = preg_match('/^Canvas saved — Report: artifact ([^,]+), (\S+) \((\d+) bytes\)$/', $result->content(), $m);
        self::assertSame(1, $matched, $result->content());
        self::assertSame((string) \strlen($html), $m[3], 'the byte count is the page, not the envelope');
        $path = $m[2];

        $reference = MediaStore::forSession($session, $this->mediaRoot);
        self::assertSame($reference->directoryPath(), \dirname($path), 'the file lands in the bound session folder');
        self::assertTrue(is_file($path), $path);
        self::assertSame(0o600, \fileperms($path) & 0o777);
        self::assertSame($html, file_get_contents($path));
        self::assertSame('html', pathinfo($path, PATHINFO_EXTENSION));

        // The id the result advertises resolves through the same store media.fetch would use.
        self::assertSame($path, $reference->pathFor($m[1]));

        // The artifact leg: a path with no bytes, so the projector names the
        // ref for the web canvas card while every bytes-gated TUI leg stays off.
        self::assertFalse($result->hasImage());
        self::assertSame($path, $result->imagePath());
        self::assertNull($result->imageBytes());
    }

    public function testUnboundToolWritesUnderTheFallbackSessionWithTheDefaultTitle(): void
    {
        $tool = CanvasTool::new()->withMediaRoot($this->mediaRoot);
        $html = '<p>fallback</p>';
        $result = $tool->execute(['html' => $html]);

        self::assertFalse($result->isError(), $result->content());

        $matched = preg_match('/^Canvas saved — canvas: artifact ([^,]+), (\S+) \(' . \strlen($html) . ' bytes\)$/', $result->content(), $m);
        self::assertSame(1, $matched, $result->content());

        $fallback = MediaStore::forSession('main', $this->mediaRoot);
        self::assertSame($fallback->directoryPath(), \dirname($m[2]));
        self::assertTrue(is_file($m[2]));
        self::assertSame($m[2], $fallback->pathFor($m[1]));
    }

    public function testEmptyAndWhitespaceHtmlAreRefusedWithoutWriting(): void
    {
        $tool = CanvasTool::new()->withMediaRoot($this->mediaRoot);

        foreach (['', "   \n\t "] as $empty) {
            $result = $tool->execute(['html' => $empty]);
            self::assertTrue($result->isError());
            self::assertSame('the html argument must be a non-empty string', $result->content());
        }

        $store = MediaStore::forSession('main', $this->mediaRoot);
        self::assertSame([], $store->list(), 'a refused page must not reach the store');
    }

    public function testInvalidUtf8IsRefused(): void
    {
        $tool = CanvasTool::new()->withMediaRoot($this->mediaRoot);
        $result = $tool->execute(['html' => "\xC3\x28<script>"]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('UTF-8', $result->content());
    }

    public function testCapRefusesOneOverTheLimitAndAcceptsExactlyTheLimit(): void
    {
        $tool = CanvasTool::new()->withMediaRoot($this->mediaRoot);

        $over = $tool->execute(['html' => 'a' . str_repeat('b', CanvasTool::MAX_BYTES)]);
        self::assertTrue($over->isError());
        self::assertStringContainsString((string) (CanvasTool::MAX_BYTES + 1), $over->content());
        self::assertStringContainsString((string) CanvasTool::MAX_BYTES, $over->content());

        $at = $tool->execute(['html' => 'a' . str_repeat('b', CanvasTool::MAX_BYTES - 1)]);
        self::assertFalse($at->isError(), $at->content());
    }

    public function testTitleBudgetRefusesEightyOneCharactersAndAcceptsEighty(): void
    {
        $tool = CanvasTool::new()->withMediaRoot($this->mediaRoot);

        $long = $tool->execute(['html' => '<p>x</p>', 'title' => str_repeat('é', 81)]);
        self::assertTrue($long->isError());
        self::assertStringContainsString('81', $long->content());
        self::assertStringContainsString('80', $long->content());

        $ok = $tool->execute(['html' => '<p>x</p>', 'title' => str_repeat('é', 80)]);
        self::assertFalse($ok->isError(), $ok->content());
    }

    /** Delete the temp artifact tree; scoped to this fixture, unlink-before-rmdir order. */
    private function wipeCanvasTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $walk = static function (string $dir) use (&$walk): void {
            foreach (scandir($dir) ?: [] as $leaf) {
                if ($leaf === '.' || $leaf === '..') {
                    continue;
                }
                $full = $dir . '/' . $leaf;
                if (is_dir($full)) {
                    $walk($full);
                    @rmdir($full);
                } else {
                    @unlink($full);
                }
            }
        };
        $walk($root);
        @rmdir($root);
    }
}
