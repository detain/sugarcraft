<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * "INHERITED RATHER THAN INVENTED" WAS PROSE, AND PROSE IS NOT DERIVATION.
 *
 * Three framing classes carry a `MAX_FRAME_BYTES` doc-block saying the number
 * is taken from {@see \SugarCraft\Crush\Backend\EngineBackend}. All three then
 * spell `64 * 1024 * 1024` as their own literal, because
 * `EngineBackend::MAX_FRAME_BYTES` is `private` and PHP cannot write
 * `const X = EngineBackend::MAX_FRAME_BYTES;` against a private constant. So
 * the claim of membership had nothing behind it: raise the engine's cap and the
 * whole family silently stops agreeing with the thing its comments cite, while
 * every existing test stays green.
 *
 * MEASURED before this file existed: `McpFrameCapTest` pins that the two NDJSON
 * framers agree with EACH OTHER and that the value is `64 * 1024 * 1024` — its
 * own fourth literal — and `LspConnectionFrameCapTest` pins a fifth. Nothing
 * anywhere compared any of them to the engine. The desynchronisation this file
 * exists to catch was invisible in both directions.
 *
 * WHY A TEST AND NOT A LANGUAGE-LEVEL DERIVATION. The honest fix would be for
 * each class to name the engine's constant directly. It cannot: PHP 8.3.6
 * refuses to read a `private` constant from another class at compile time, and
 * `ReflectionClass::getConstant()` — which DOES read private constants,
 * verified on this host — is a runtime call and cannot initialise a `const`.
 * Promoting the engine's constant to `public` is the alternative, and it is a
 * change to a file this lane does not own. Until then this test IS the
 * derivation, and the doc-blocks cite it rather than asserting an inheritance
 * that nothing checks.
 *
 * @see \SugarCraft\Crush\Tests\MCP\McpFrameCapTest for what each framer does at
 *      the cap; this file only pins that they all mean the same number.
 */
final class FrameCapFamilyTest extends TestCase
{
    /**
     * Every class whose `MAX_FRAME_BYTES` doc-block claims descent from the
     * engine's. A class added to the family belongs here; a class removed from
     * it should lose the claim in its doc-block at the same time.
     *
     * @return list<class-string>
     */
    private static function claimants(): array
    {
        return [
            ClaudeCodeMcpClient::class,
            LspConnection::class,
            StdioMcpServer::class,
        ];
    }

    /**
     * The reader is checked before it is believed.
     *
     * `getConstant()` answers `false` for a name that does not exist, and
     * `false === false` is exactly what a comparison between two DEAD reads
     * looks like. So the source is required to be a positive int, and the
     * reader is required to still be able to say "no".
     */
    public function testTheConstantReaderIsAliveInBothPolarities(): void
    {
        $engine = new \ReflectionClass(EngineBackend::class);

        $source = $engine->getConstant('MAX_FRAME_BYTES');
        $this->assertIsInt($source, 'the engine no longer declares MAX_FRAME_BYTES as an int');
        $this->assertGreaterThan(0, $source);

        $this->assertFalse(
            $engine->getConstant('MAX_FRAME_BYTES_NO_SUCH_CONSTANT'),
            'getConstant() answered something other than false for a constant that does not '
            . 'exist, so a false it returns elsewhere can no longer be read as "absent" and '
            . 'the comparisons below could be comparing two failures to each other',
        );

        $this->assertTrue(
            $engine->getReflectionConstant('MAX_FRAME_BYTES')->isPrivate(),
            'EngineBackend::MAX_FRAME_BYTES is no longer private, so the family can now name it '
            . 'directly: replace each `64 * 1024 * 1024` literal with the constant itself and '
            . 'this test becomes a formality rather than the only thing holding the family '
            . 'together',
        );

        $this->assertNotSame([], self::claimants());
    }

    /**
     * The claim itself: every member equals the engine, and every member spells
     * the constant ITSELF rather than inheriting one from a parent — an
     * inherited constant would compare equal while the class it is written in
     * is not a member of this family at all.
     */
    public function testEveryClaimantAgreesWithTheEngine(): void
    {
        $source = (new \ReflectionClass(EngineBackend::class))->getConstant('MAX_FRAME_BYTES');

        foreach (self::claimants() as $class) {
            $ref = new \ReflectionClass($class);
            $constant = $ref->getReflectionConstant('MAX_FRAME_BYTES');

            $this->assertNotFalse(
                $constant,
                $class . ' no longer declares MAX_FRAME_BYTES, so its doc-block cites a family '
                . 'it has left. Drop it from claimants() in the same change.',
            );

            $this->assertSame(
                $class,
                $constant->getDeclaringClass()->getName(),
                $class . ' inherits MAX_FRAME_BYTES rather than declaring it, so this row is '
                . 'measuring a parent and would keep agreeing with the engine while ' . $class
                . ' itself framed at some other bound',
            );

            $this->assertSame(
                $source,
                $constant->getValue(),
                $class . '::MAX_FRAME_BYTES and EngineBackend::MAX_FRAME_BYTES have diverged. '
                . 'The doc-block on ' . $class . ' says the number is inherited from the '
                . 'engine, and until this test existed nothing checked that. THE FIX IS TO '
                . 'MOVE BOTH, not to relax this: a framer that accepts a payload the engine '
                . 'will refuse turns a clean over-size rejection into a truncated frame one '
                . 'process further down. If the divergence is deliberate, delete the '
                . 'inheritance claim from that class\'s doc-block in the same commit and drop '
                . 'it from claimants().',
            );
        }
    }
}
