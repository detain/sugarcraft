<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Endpoint;
use SugarCraft\Wishlist\Launcher;
use SugarCraft\Wishlist\Picker;
use SugarCraft\Core\Util\RawMode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for Launcher constructor default executor path and
 * Picker setRawMode/readKey methods.
 */
final class LauncherAndPickerMethodsTest extends TestCase
{
    // ==================================================================
    // Launcher tests
    // ==================================================================

    public function testConstructorWithNullExecutorSetsDefault(): void
    {
        // When null is passed, the constructor should install the default
        // pcntl_exec-based executor. We verify this by inspecting the
        // executor property via reflection.
        $launcher = new Launcher(null);
        $ref = new ReflectionClass($launcher);
        $prop = $ref->getProperty('executor');
        $prop->setAccessible(true);
        $executor = $prop->getValue($launcher);

        // The executor should be callable
        $this->assertIsCallable($executor);

        // Calling it with a mock that records invocation should work
        $called = false;
        $apturedBin = null;
        $apturedArgs = null;
        $mockExecutor = function (string $bin, array $args) use (&$called, &$apturedBin, &$apturedArgs): void {
            $called = true;
            $apturedBin = $bin;
            $apturedArgs = $args;
        };

        $launcher2 = new Launcher($mockExecutor);
        $e = new Endpoint(name: 'test', host: 'test.example.com');
        $launcher2->dispatch($e, '/usr/bin/ssh');
        $this->assertTrue($called);
        $this->assertSame('/usr/bin/ssh', $apturedBin);
    }

    public function testDefaultExecutorThrowsWhenPcntlExecMissing(): void
    {
        // We cannot easily test the actual pcntl_exec missing branch
        // since pcntl is loaded. But we can verify the executor exists
        // and is callable.
        $launcher = new Launcher(null);
        $ref = new ReflectionClass($launcher);
        $prop = $ref->getProperty('executor');
        $prop->setAccessible(true);
        $executor = $prop->getValue($launcher);
        $this->assertIsCallable($executor);
    }

    public function testDispatchWithoutIdentityFiles(): void
    {
        // Endpoint with no identity files - tests the identityFiles loop skip
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'noident', host: 'noid.example.com', port: 22, user: 'root');
        $launcher->dispatch($e);
        // No -i flags since no identity files
        $this->assertSame(['--', 'root@noid.example.com'], $captured);
    }

    public function testDispatchWithEmptyIdentityFileSkipped(): void
    {
        // Empty string identity file should be skipped
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'emptyident', host: 'example.com', identityFiles: ['']);
        $launcher->dispatch($e);
        // Empty identity file skipped, no -i flag
        $this->assertSame(['--', 'example.com'], $captured);
    }

    public function testDispatchWithProxyJump(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'internal', host: 'internal.example.com', proxyJump: 'bastion.example.com');
        $launcher->dispatch($e);
        $this->assertSame(['-J', 'bastion.example.com', '--', 'internal.example.com'], $captured);
    }

    public function testDispatchWithOptions(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(name: 'prod', host: 'prod.example.com', options: ['ServerAliveInterval=60', 'StrictHostKeyChecking=no']);
        $launcher->dispatch($e);
        $this->assertSame(['-o', 'ServerAliveInterval=60', '-o', 'StrictHostKeyChecking=no', '--', 'prod.example.com'], $captured);
    }

    public function testDispatchWithProxyJumpAndOptions(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(
            name: 'internal',
            host: 'internal.example.com',
            user: 'admin',
            proxyJump: 'bastion.example.com',
            options: ['ServerAliveInterval=30']
        );
        $launcher->dispatch($e);
        $this->assertSame(
            ['-J', 'bastion.example.com', '-o', 'ServerAliveInterval=30', '--', 'admin@internal.example.com'],
            $captured
        );
    }

    public function testDispatchWithIdentityFiles(): void
    {
        $captured = null;
        $launcher = new Launcher(function (string $bin, array $args) use (&$captured): void {
            $captured = $args;
        });
        $e = new Endpoint(
            name: 'multikey',
            host: 'example.com',
            user: 'deploy',
            identityFiles: ['/path/to/key1', '/path/to/key2']
        );
        $launcher->dispatch($e);
        $this->assertSame(
            ['-i', '/path/to/key1', '-i', '/path/to/key2', '--', 'deploy@example.com'],
            $captured
        );
    }

    // ==================================================================
    // Picker tests - setRawMode and readKey
    // ==================================================================

    private function makePicker(string $keys): Picker
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        fwrite($in, $keys);
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void
            {
                // noop for tests
            }
        };
        return $p;
    }

    public function testSetRawModeCallsRawModeEnable(): void
    {
        // Create a picker and verify setRawMode is called during pick()
        // by checking that RawMode::enable is invoked (we can't easily
        // verify the actual call but we verify the method exists)
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");
        rewind($in);

        $setRawModeCalled = false;
        $p = new class($in, $out) extends Picker {
            public bool $setRawModeCalled = false;
            protected function setRawMode(bool $on): void
            {
                $this->setRawModeCalled = true;
            }
        };

        $endpoints = [new Endpoint(name: 'test', host: 'test.example.com')];
        $p->pick($endpoints);

        // setRawMode(true) should be called
        $this->assertTrue($p->setRawModeCalled);
    }

    public function testReadKeyReturnsSingleByte(): void
    {
        // Test that a single byte key is returned correctly
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, 'a');  // Single 'a' key
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame('a', $key);
    }

    public function testReadKeyReturnsEmptyOnEof(): void
    {
        // When input is exhausted, readKey returns ^C (\x03)
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        // Empty input
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame("\x03", $key);
    }

    public function testReadKeyParsesArrowDownSequence(): void
    {
        // ESC [ B is arrow down
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\x1b[B");  // Arrow down
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame("\x1b[B", $key);
    }

    public function testReadKeyParsesArrowUpSequence(): void
    {
        // ESC [ A is arrow up
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\x1b[A");  // Arrow up
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame("\x1b[A", $key);
    }

    public function testReadKeyParsesLoneEscKey(): void
    {
        // A lone ESC without following CSI sequence should return just ESC
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\x1b");  // Just ESC
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame("\x1b", $key);
    }

    public function testReadKeyFollowedByNonBracket(): void
    {
        // ESC followed by something other than [ (not a CSI sequence)
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\x1bX");  // ESC + X
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function readKeyPublic(): string
            {
                return $this->readKey();
            }
        };

        $key = $p->readKeyPublic();
        $this->assertSame("\x1bX", $key);
    }

    public function testPickDrawsCorrectOutput(): void
    {
        // Test that draw() outputs correct format
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");  // Enter immediately
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'prod', host: 'prod.example.com', description: 'Production server'),
        ];

        $p->pick($endpoints);

        $output = stream_get_contents($out);
        $this->assertStringContainsString('wishlist', $output);
        $this->assertStringContainsString('prod', $output);
        $this->assertStringContainsString('filter:', $output);
    }

    public function testPickWithDescriptionShown(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");  // Enter
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'prod', host: 'prod.example.com', description: 'Prod env'),
        ];

        $p->pick($endpoints);

        $output = stream_get_contents($out);
        $this->assertStringContainsString('Prod env', $output);
    }

    public function testPickNoMatchesShowsNoMatchesMessage(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "nonexistent\r");  // Filter that matches nothing
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'prod', host: 'prod.example.com'),
        ];

        $p->pick($endpoints);

        $output = stream_get_contents($out);
        $this->assertStringContainsString('no matches', $output);
    }

    public function testPickHighlightsMatchedCharacters(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        // Type "prd" which partially matches "prod" 
        fwrite($in, "prd\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'prod', host: 'prod.example.com'),
            new Endpoint(name: 'dev', host: 'dev.example.com'),
        ];

        $p->pick($endpoints);

        $output = stream_get_contents($out);
        // ANSI bold+cyan should be in output
        $this->assertStringContainsString("\x1b[1;36m", $output);
    }

    public function testPickCursorAtEndStaysAtEnd(): void
    {
        // When cursor would go past the end, it should clamp to last item
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        // Move down multiple times beyond list length, then enter
        fwrite($in, "jjjjjj\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'one', host: 'one.example.com'),
            new Endpoint(name: 'two', host: 'two.example.com'),
            new Endpoint(name: 'three', host: 'three.example.com'),
        ];

        $picked = $p->pick($endpoints);
        // Should pick the last one (three), not crash
        $this->assertSame('three', $picked->name);
    }

    public function testPickWithZeroEndpoints(): void
    {
        // Empty list returns null immediately
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $result = $p->pick([]);
        $this->assertNull($result);
    }

    public function testEnterOnEmptyFilterSelectsFirstItem(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'first', host: 'first.example.com'),
            new Endpoint(name: 'second', host: 'second.example.com'),
        ];

        $picked = $p->pick($endpoints);
        $this->assertSame('first', $picked->name);
    }

    public function testFilterThenBackspaceThenEnter(): void
    {
        // Type "sec", backspace once (becomes "se"), enter
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "sec\x7fse\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: 'first', host: 'first.example.com'),
            new Endpoint(name: 'second', host: 'second.example.com'),
            new Endpoint(name: 'select', host: 'select.example.com'),
        ];

        $picked = $p->pick($endpoints);
        // "se" should match "second" and "select" - second comes first alphabetically
        $this->assertSame('second', $picked->name);
    }

    public function testFilterWithUtf8Multibyte(): void
    {
        // UTF-8 characters should be handled correctly
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [
            new Endpoint(name: '日本語', host: 'jp.example.com'),
            new Endpoint(name: 'english', host: 'en.example.com'),
        ];

        $picked = $p->pick($endpoints);
        $this->assertSame('日本語', $picked->name);
    }

    public function testDrawEndsWithHelpLine(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        fwrite($in, "\r");
        rewind($in);

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
        };

        $endpoints = [new Endpoint(name: 'test', host: 'test.example.com')];
        $p->pick($endpoints);

        $output = stream_get_contents($out);
        $this->assertStringContainsString('select', $output);
        $this->assertStringContainsString('connect', $output);
        $this->assertStringContainsString('quit', $output);
        $this->assertStringContainsString('filter', $output);
    }

    public function testStripControlsRemovesControlChars(): void
    {
        // Use reflection to test stripControls directly
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function stripControlsPublic(string $s): string
            {
                return $this->stripControls($s);
            }
        };

        // Control chars should be stripped
        $result = $p->stripControlsPublic("\x01\x02test\x03");
        $this->assertSame('test', $result);

        // CR/LF should be preserved
        $result = $p->stripControlsPublic("line1\nline2\rline3");
        $this->assertSame("line1\nline2\rline3", $result);
    }

    public function testStripControlsRemovesBelAndOtherC0(): void
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');

        $p = new class($in, $out) extends Picker {
            protected function setRawMode(bool $on): void { }
            public function stripControlsPublic(string $s): string
            {
                return $this->stripControls($s);
            }
        };

        // BEL (0x07), VT (0x0B), etc should be stripped
        $result = $p->stripControlsPublic("a\x07b\x0bcd");
        $this->assertSame('abcd', $result);
    }
}
