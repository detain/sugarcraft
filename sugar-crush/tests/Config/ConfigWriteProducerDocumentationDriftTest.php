<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * `~/.sugar-crush/config.json` receives exactly two keys, and every document
 * that says so ALSO enumerates where those two keys come from. That
 * enumeration is the part that drifted: {@see LayeredSettings}' class
 * doc-block and `docs/SETTINGS.md` both credited the Ctrl+P palette's
 * "Switch Model" row for `provider` and stopped there, while `/model <name>`
 * reaches the identical write. `README.md`'s layer table credited `/model`.
 * Two documents disagreed and the less-read one was right — the same shape as
 * the `deprecated`-name drift {@see ReadmeSettingsTierClaimTest} pins, with
 * the roles of the documents swapped.
 *
 * THE ROUTE WAS FOLLOWED, NOT INFERRED, before any of this was written down:
 * {@see \SugarCraft\Crush\Chat}'s `handleModelCommand()` ends in
 * `selectPaletteProvider($args[0])`, and `selectPaletteProvider()` holds the
 * only `$onConfigChange('provider', …)` call in the package. The palette row
 * and the slash command are not two writers — they are ONE writer with TWO
 * DOORS, which is also why a `/model` choice persists exactly as a palette
 * choice does and why no separate persistence code had to exist for it.
 * `testTheSlashCommandAndThePaletteRowReachTheSameWrite()` is that claim as an
 * assertion rather than as a sentence.
 *
 * WHY THE PROSE RULES ARE PARAGRAPH-SCOPED AND NOT ±N CHARACTERS. Round 42
 * established, by mutation, that a character window wide enough to reach a
 * retraction is wide enough for the restored false sentence to sit inside it
 * and inherit the retraction — the window gets pinned, not the claim. So the
 * two rules here are: (1) ONE paragraph must name all four doors, which the
 * pre-drift text fails because `/model` appears nowhere in it; and (2) the
 * retracted wording may appear only in a paragraph that retracts it, which is
 * the rule that survived round 42's mutation C.
 *
 * WHAT THIS CANNOT DO, stated so the next reader does not over-trust it: it
 * pins the doors that exist TODAY against the prose that describes them. A
 * FIFTH door — a new command routed into `selectPaletteProvider()` — would
 * leave every assertion here green while the enumeration went stale again.
 * There is no cheap oracle for "every user-facing route into this method",
 * because the routes are ordinary private-method calls. The census in
 * `testConfigJsonEverReceivesExactlyTwoKeys()` catches a new KEY, which is the
 * half that is mechanically derivable.
 *
 * @internal
 */
final class ConfigWriteProducerDocumentationDriftTest extends TestCase
{
    private const SETTINGS_DOC = __DIR__ . '/../../docs/SETTINGS.md';
    private const README = __DIR__ . '/../../README.md';

    /**
     * Every key literal handed to `onConfigChange`, read from CODE ONLY.
     *
     * `token_get_all()` rather than a plain `preg_match_all()` over the file:
     * `Chat::handleModelCommand()`'s own doc-block writes
     * `$onConfigChange('provider', …)` in prose, so a text scan reports a call
     * site that is a sentence. A census that counts documentation as evidence
     * cannot then be used to check documentation.
     *
     * @return list<string>
     */
    private function keysWrittenFromSource(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (\is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];

                    continue;
                }
                $code .= $token;
            }

            if (preg_match_all('/onConfigChange\s*(?:\?)?->__invoke\(\s*[\'"]([A-Za-z]+)[\'"]/', $code, $m) === 0) {
                continue;
            }
            foreach ($m[1] as $key) {
                $found[$key] = true;
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * Paragraphs of a doc-block or a markdown page, whitespace-normalised.
     *
     * NORMALISED because the claims being checked are line-wrapped: the phrase
     * `"Switch Model"` lands with a newline in the middle of it in
     * `docs/SETTINGS.md`, and a raw `str_contains()` would miss it and report
     * a defect that is a line break.
     *
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        // Strip the doc-block leader so a `*`-prefixed line and a markdown line
        // reach the same shape. The blank separator inside a doc-block is ` *`.
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lines[] = preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line) ?? $line;
        }

        $out = [];
        foreach (preg_split('/\n\s*\n/', implode("\n", $lines)) ?: [] as $para) {
            $normalised = trim((string) preg_replace('/\s+/', ' ', $para));
            if ($normalised !== '') {
                $out[] = $normalised;
            }
        }

        return $out;
    }

    private function layeredSettingsDocBlock(): string
    {
        $doc = (new \ReflectionClass(LayeredSettings::class))->getDocComment();
        self::assertIsString($doc, 'LayeredSettings lost its class doc-block');

        return $doc;
    }

    // ── mechanism ────────────────────────────────────────────────────────

    /**
     * The census. This is the assertion that reds the day a third key becomes
     * persistable, before any of the prose checks below are reached.
     */
    public function testConfigJsonEverReceivesExactlyTwoKeys(): void
    {
        $this->assertSame(
            ['provider', 'theme'],
            $this->keysWrittenFromSource(),
            'a third key now reaches onConfigChange; update LayeredSettings, docs/SETTINGS.md and README.md together',
        );
    }

    /**
     * The claim the doc-block now makes about `provider`, as a measurement:
     * `/model <name>` and the palette row are the same write.
     */
    public function testTheSlashCommandAndThePaletteRowReachTheSameWrite(): void
    {
        $viaCommand = [];
        $chat = (new Chat(inputBuf: '/model custom', backend: new EchoBackend()))
            ->withSize(100, 30)
            ->withOnConfigChange(function (string $k, string $v) use (&$viaCommand): void {
                $viaCommand[] = [$k, $v];
            });
        $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(
            [['provider', 'custom']],
            $viaCommand,
            '/model <name> must persist the provider exactly as the palette row does',
        );

        $viaPalette = [];
        $palette = (new Chat(backend: new EchoBackend()))
            ->withSize(100, 30)
            ->withOnConfigChange(function (string $k, string $v) use (&$viaPalette): void {
                $viaPalette[] = [$k, $v];
            });

        [$opened] = $palette->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $current = $opened;
        foreach (str_split('switch model') as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$inProviders] = $current->update(new KeyMsg(KeyType::Enter, ''));
        foreach (str_split('custom') as $ch) {
            [$inProviders] = $inProviders->update(new KeyMsg(KeyType::Char, $ch));
        }
        $inProviders->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(
            $viaCommand,
            $viaPalette,
            'the two doors must produce an identical write, or they are two writers and the doc-block is wrong',
        );
    }

    // ── prose ────────────────────────────────────────────────────────────

    /**
     * ONE paragraph, in each document that enumerates the persisted keys, must
     * name all four doors. Scattering them across a page is how a reader ends
     * up counting three.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function enumeratingDocuments(): iterable
    {
        yield 'LayeredSettings class doc-block' => ['docblock'];
        yield 'docs/SETTINGS.md' => ['settings'];
    }

    /** @dataProvider enumeratingDocuments */
    public function testOneParagraphNamesAllFourDoors(string $which): void
    {
        $text = $which === 'docblock'
            ? $this->layeredSettingsDocBlock()
            : (string) file_get_contents(self::SETTINGS_DOC);

        $doors = ['Switch Model', '/model', 'Switch Theme', '/theme'];

        $hit = null;
        foreach ($this->paragraphs($text) as $para) {
            $named = 0;
            foreach ($doors as $door) {
                if (stripos($para, $door) !== false) {
                    $named++;
                }
            }
            if ($named === \count($doors)) {
                $hit = $para;

                break;
            }
        }

        $this->assertNotNull(
            $hit,
            $which . ': no single paragraph names all four producers of the two persisted keys '
            . '(palette "Switch Model" + /model, palette "Switch Theme" + /theme) — '
            . 'this is the omission that credited the palette alone for `provider`',
        );
    }

    /**
     * And the retracted wording may appear only where it is being retracted.
     *
     * PARAGRAPH-SCOPED for round 42's measured reason. The needle is the
     * distinctive half of the old sentence — the palette credited as the sole
     * source — so restoring it verbatim reds even though the corrected text
     * quotes it a few lines away.
     */
    public function testTheRetractedProviderCreditAppearsOnlyInsideItsRetraction(): void
    {
        $needle = '`provider`, from the Ctrl+P palette\'s "Switch Model" action';

        $offenders = [];
        foreach ($this->paragraphs($this->layeredSettingsDocBlock()) as $para) {
            if (stripos($para, $needle) === false) {
                continue;
            }
            if (!str_contains($para, 'WHAT IT SAID')) {
                $offenders[] = mb_substr($para, 0, 90);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'the retracted "palette alone writes provider" wording is back outside its retraction',
        );
    }

    /**
     * The README's layer table is the accurate document here, and it is the one
     * a user actually opens — so pin the row rather than leaving it to survive
     * on being right so far. Structural: the layer-4 row, located by the file
     * it names, must credit BOTH slash commands.
     */
    public function testTheReadmeLayerTableStillCreditsBothSlashCommands(): void
    {
        $readme = (string) file_get_contents(self::README);

        $matched = preg_match('/^\| 4 \| `~\/\.sugar-crush\/config\.json` \|([^|]*)\|/m', $readme, $m);
        $this->assertSame(1, $matched, 'the layer-4 row of the README precedence table is not in the expected shape');

        $this->assertStringContainsString('/theme', $m[1], 'the layer-4 row stopped crediting /theme');
        $this->assertStringContainsString('/model', $m[1], 'the layer-4 row stopped crediting /model');
    }
}
