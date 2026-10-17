<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;

/**
 * rv-tui-5 (fix 4): every `--output-format json` envelope carries a
 * top-level `"schema": 1` as its FIRST key, so a machine consumer can pin
 * the contract version without sniffing key presence.
 *
 * Four emitters, one version: {@see NonInteractive::format()}'s success
 * document, {@see NonInteractive::emitErrorDocument()}'s error document
 * (which `failUsage()` and the provider refusals route through),
 * Subcommands' `emitDocument()` — which prepends the key at its single
 * encode site so every subcommand document rides the same version — and the
 * two last-resort literals that exist precisely because the encoders may be
 * the thing that failed (NonInteractive's `sprintf` fallback, Subcommands'
 * encoding fallback, and bin's pre-autoload guard). The literals are pinned
 * by source scan because they must stay encodable by construction; the live
 * documents are pinned by decoding an actual emit.
 */
final class JsonSchemaEnvelopeTest extends TestCase
{
    public function testTheSuccessDocumentCarriesSchemaFirst(): void
    {
        $json = NonInteractive::format(Message::assistant('the answer'), NonInteractive::FORMAT_JSON);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result'], array_keys($document));
        $this->assertSame(NonInteractive::SCHEMA, $document['schema']);
        $this->assertSame('the answer', $document['result']);
    }

    public function testTheSuccessDocumentKeepsRefusalsAfterSchemaAndResult(): void
    {
        $json = NonInteractive::format(
            Message::assistant('the answer'),
            NonInteractive::FORMAT_JSON,
            [['tool' => 'Bash', 'reason' => 'hook']],
        );
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result', 'refusals'], array_keys($document));
    }

    public function testTheUsageErrorDocumentCarriesSchemaFirst(): void
    {
        ob_start();
        $exitCode = NonInteractive::failUsage('bad flag frob', NonInteractive::FORMAT_JSON);
        $output = (string) ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_CONFIG, $exitCode);
        $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['schema', 'result', 'error'], array_keys($document));
        $this->assertSame(NonInteractive::SCHEMA, $document['schema']);
        $this->assertNull($document['result']);
        $this->assertSame('usage', $document['error']['type']);
    }

    public function testTheConstantIsOne(): void
    {
        $this->assertSame(1, NonInteractive::SCHEMA);
    }

    /**
     * The three by-construction literals — the two encode-failure fallbacks
     * and bin's autoload guard — must open with the schema key too, or the
     * documents a broken-encoder run emits are exactly the ones a schema
     * check would reject.
     */
    public function testEveryFallbackLiteralOpensWithTheSchemaKey(): void
    {
        $root = \dirname(__DIR__, 2);

        $nonInteractive = (string) file_get_contents($root . '/src/Cli/NonInteractive.php');
        $this->assertSame(
            1,
            substr_count($nonInteractive, "'{\"schema\":%d,\"result\":null,\"error\""),
            'the JsonException fallback sprintf must open with the schema key',
        );

        $subcommands = (string) file_get_contents($root . '/src/Cli/Subcommands.php');
        $emitFallback = <<<'NEEDLE'
'{"schema":' . NonInteractive::SCHEMA . ',"result":null,"error":{"type":"encoding"
NEEDLE;
        $this->assertStringContainsString(
            $emitFallback,
            $subcommands,
            'the emitDocument encoding fallback must open with the schema key',
        );

        $bin = (string) file_get_contents($root . '/bin/candy-code');
        $this->assertStringContainsString('{"schema":1,"result":null,"error":{"type":"installation"', $bin);
    }

    /**
     * The success document's `refusals` key must stay AFTER `result` even
     * with schema prepended — the guard test on the bin twin compares
     * `array_keys()` positionally, and this pins the ordering doctrine the
     * seam relies on: schema FIRST, every pre-existing key in its old order
     * after it.
     */
    public function testTheSchemaKeyNeverReordersTheLegacyKeys(): void
    {
        $legacy = ['result', 'refusals'];
        $withSchema = array_keys(json_decode(
            NonInteractive::format(
                Message::assistant('x'),
                NonInteractive::FORMAT_JSON,
                [['tool' => 'Bash', 'reason' => 'hook']],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));

        $this->assertSame($legacy, array_values(array_slice($withSchema, 1)));
    }
}
