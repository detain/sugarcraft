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
 * THAT LAST SENTENCE WAS ONCE TRUE OF THE IDEA AND FALSE OF THE CODE, and the
 * correction is kept here because it is the more useful half of the lesson.
 * WHAT IT SAID: the census "catches a new KEY". WHAT WAS TRUE: it caught a new
 * key written in one syntax with one alphabet; two third keys were measured
 * straight past it. WHY THE CLAIM SURVIVES IN CORRECTED FORM: it is now derived
 * from the token stream rather than from a regex over reassembled source, an
 * argument it cannot read is recorded rather than skipped, and the alias routes
 * a token walk cannot follow are refused outright by
 * `testTheCallbackIsNeverInvokedThroughAnAliasThisCensusCannotFollow()`. The
 * generator behind the claim is named on {@see keysWrittenFromSource()}.
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
     * {@see \SugarCraft\Crush\Chat}'s `handleModelCommand()` doc-block writes
     * `$onConfigChange('provider', …)` in prose, so a text scan reports a call
     * site that is a sentence. A census that counts documentation as evidence
     * cannot then be used to check documentation.
     *
     * STRUCTURAL, AND THIS PARAGRAPH REPLACES A REGEX THAT WAS NOT.
     * WHAT IT SAID: the first cut tokenised the file only to strip comments and
     * then ran `/onConfigChange\s*(?:\?)?->__invoke\(\s*['"]([A-Za-z]+)['"]/`
     * over the reassembled text, with the doc-block claiming this "catches a new
     * KEY, which is the half that is mechanically derivable".
     * WHAT IS TRUE NOW: it caught a new key only if the key was spelled in one
     * particular way through one particular call syntax. Two third keys were
     * MEASURED past it on PHP 8.3.6, 2026-08-22, each leaving the whole file
     * green: `($this->onConfigChange)('titleModel', …)`, the ordinary
     * parenthesised-closure call, which the regex has no arm for; and
     * `$this->onConfigChange?->__invoke('title_model', …)`, the canonical
     * spelling, whose underscore `[A-Za-z]+` rejects — and rejecting the KEY
     * silently skipped the whole CALL, so a key class too narrow to name a key
     * hid the call site as well.
     * WHY THE CENSUS STILL EARNS ITS PLACE: the failure was the oracle, not the
     * idea. A key reaching `config.json` is still mechanically derivable, and it
     * is still the half of this file that does not depend on prose. It is now
     * derived from the TOKEN STREAM: a `T_STRING` `onConfigChange` is treated as
     * a call only in the two shapes PHP has for invoking a closure property, and
     * an argument that is not a literal is recorded as {@see UNRESOLVED_KEY}
     * rather than skipped — a call this method cannot read must red, not vanish.
     *
     * THE TWO SHAPES ARE THE WHOLE COVERAGE, and the remaining ways to reach a
     * closure through a variable are closed by
     * {@see testTheCallbackIsNeverInvokedThroughAnAliasThisCensusCannotFollow()}
     * rather than left as a stated hope.
     *
     * @return list<string>
     */
    private function keysWrittenFromSource(): array
    {
        $found = [];
        foreach ($this->sourceFiles() as $path) {
            $tokens = $this->codeTokens($path);
            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== 'onConfigChange') {
                    continue;
                }
                $key = $this->invokedKeyAt($tokens, $i);
                if ($key !== null) {
                    $found[$key] = true;
                }
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * Recorded in place of a key whose argument is not a literal string.
     *
     * A census that cannot read an argument must SAY SO in its result. The
     * regex this replaced returned the same value for "no third key exists" and
     * for "a third key exists in a shape I do not parse", which is the property
     * that let two of them through.
     */
    private const UNRESOLVED_KEY = '<not a literal>';

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        $paths = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /** @var array<string, list<array{0: int, 1: string}|string>> */
    private array $codeTokenCache = [];

    /**
     * One file's tokens with comments AND whitespace dropped, so index+1 is the
     * next thing PHP actually reads rather than a space.
     *
     * @return list<array{0: int, 1: string}|string>
     */
    private function codeTokens(string $path): array
    {
        if (isset($this->codeTokenCache[$path])) {
            return $this->codeTokenCache[$path];
        }

        $out = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $out[] = \is_array($token) ? [$token[0], $token[1]] : $token;
        }

        return $this->codeTokenCache[$path] = $out;
    }

    /**
     * The key argument of the call at `$i`, or `null` when `$i` is a mere
     * reference to the property rather than an invocation of it.
     *
     * PHP has exactly two shapes for calling a closure held in a property, and
     * both are here:
     *
     *   `$this->onConfigChange?->__invoke('theme', …)`   — the one `src/` uses
     *   `($this->onConfigChange)('theme', …)`            — the one it does not
     *
     * The second is included precisely BECAUSE `src/` does not use it: a census
     * that only knows the spelling already in the tree cannot catch the day
     * someone writes the other one, and that day is the only day it matters.
     *
     * @param list<array{0: int, 1: string}|string> $tokens
     */
    private function invokedKeyAt(array $tokens, int $i): ?string
    {
        $isOp = static fn(mixed $t, int $id): bool => \is_array($t) && $t[0] === $id;
        $isPunct = static fn(mixed $t, string $c): bool => $t === $c;

        // `?->__invoke(` / `->__invoke(`
        if (
            (($tokens[$i + 1] ?? null) !== null)
            && ($isOp($tokens[$i + 1], T_NULLSAFE_OBJECT_OPERATOR) || $isOp($tokens[$i + 1], T_OBJECT_OPERATOR))
            && $isOp($tokens[$i + 2] ?? null, T_STRING)
            && ($tokens[$i + 2][1] ?? '') === '__invoke'
            && $isPunct($tokens[$i + 3] ?? null, '(')
        ) {
            return $this->literalAt($tokens[$i + 4] ?? null);
        }

        // `)(` — the parenthesised-closure call.
        if ($isPunct($tokens[$i + 1] ?? null, ')') && $isPunct($tokens[$i + 2] ?? null, '(')) {
            return $this->literalAt($tokens[$i + 3] ?? null);
        }

        return null;
    }

    /** @param array{0: int, 1: string}|string|null $token */
    private function literalAt(mixed $token): string
    {
        if (\is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $raw = $token[1];
            $unquoted = substr($raw, 1, -1);

            return $unquoted === '' ? self::UNRESOLVED_KEY : $unquoted;
        }

        return self::UNRESOLVED_KEY;
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
     * THE CENSUS FOLLOWS TOKENS, SO THE WAYS OF LOSING THE TOKEN ARE REFUSED.
     *
     * {@see keysWrittenFromSource()} recognises a call only where the property
     * itself is the callee. Two spellings would move the invocation somewhere a
     * token walk cannot follow it — `$cb = $this->onConfigChange; $cb('k', …)`,
     * and `call_user_func($this->onConfigChange, 'k', …)` — and in both the key
     * literal sits beside a name the census has never heard of.
     *
     * Rather than record that as a blind spot and hope, this makes the blind
     * spot unreachable: neither spelling may appear in `src/` at all. That is a
     * real constraint on future code and it is the cheaper half of the trade —
     * the property is private, there is no legitimate reason to hand it to
     * `call_user_func()`, and the day someone needs to, this test is the note
     * telling them the census must grow an arm first.
     */
    public function testTheCallbackIsNeverInvokedThroughAnAliasThisCensusCannotFollow(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $path) {
            $code = '';
            foreach ($this->codeTokens($path) as $token) {
                $code .= \is_array($token) ? $token[1] : $token;
            }

            if (preg_match('/call_user_func(?:_array)?\(\$this->onConfigChange/', $code) === 1) {
                $offenders[] = basename($path) . ': handed to call_user_func()';
            }
            if (preg_match('/=\$this->onConfigChange;/', $code) === 1) {
                $offenders[] = basename($path) . ': aliased to a local variable';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'onConfigChange is now invoked through a route the key census cannot follow; '
            . 'teach keysWrittenFromSource() that shape before shipping this',
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
        // QUOTE-INSENSITIVE, and the exemption below is why. The retracted
        // sentence used `"Switch Model"`; the retraction quoting it writes
        // `'Switch Model'`, because it is itself already inside double quotes.
        // With a literal needle the exemption branch could never fire — it was
        // dead code guarding a case that could not arise, so the day someone
        // re-quoted the sentence verbatim (which rule 7's three-part form
        // actively invites) this would have redded against CORRECT prose.
        // Folding both quote characters together makes the exemption real, and
        // the test now passes BECAUSE of it rather than despite it.
        $fold = static fn(string $t): string => str_replace(['"', "'", '\u{2018}', '\u{2019}', '\u{201C}', '\u{201D}'], '"', $t);
        $needle = $fold('`provider`, from the Ctrl+P palette\'s "Switch Model" action');

        $offenders = [];
        foreach ($this->paragraphs($this->layeredSettingsDocBlock()) as $para) {
            if (stripos($fold($para), $needle) === false) {
                continue;
            }
            if (!str_contains($para, 'WHAT IT SAID')) {
                $offenders[] = mb_substr($para, 0, 90);
            }
        }

        // The exemption is LOAD-BEARING, not decorative: the retraction
        // paragraph must actually match the needle, or this test is passing
        // because it found nothing rather than because it forgave the right
        // thing — and would keep passing after the retraction was deleted.
        $quoting = array_values(array_filter(
            $this->paragraphs($this->layeredSettingsDocBlock()),
            static fn(string $p): bool => stripos($fold($p), $needle) !== false,
        ));
        $this->assertCount(
            1,
            $quoting,
            'exactly one paragraph should quote the retracted wording — its retraction',
        );

        $this->assertSame(
            [],
            $offenders,
            'the retracted "palette alone writes provider" wording is back outside its retraction',
        );
    }

    /**
     * AND THE README'S OWN COUNTERFACTUAL, which had the identical omission one
     * section below the table that did not.
     *
     * "Ranked the other way, a `settings.json` naming `theme` would outrank
     * what Ctrl+P 'Switch theme' had just written" credited the palette alone
     * for `theme`, exactly as the {@see LayeredSettings} doc-block had for
     * `provider`. The table three lines above it named `/theme`. That is the
     * whole E81 shape reproduced inside one page: the ROSTER stayed right and
     * the PROSE that explains the roster drifted, and prose is what a reader
     * who is debugging actually reads.
     *
     * Pinned separately from the table row because they fail separately — the
     * table is generated from constants and this is a sentence.
     */
    public function testTheReadmeCounterfactualCreditsTheSlashCommandAndNotThePaletteAlone(): void
    {
        $readme = (string) file_get_contents(self::README);

        $hits = [];
        foreach ($this->paragraphs($readme) as $para) {
            if (str_contains($para, 'would outrank what')) {
                $hits[] = $para;
            }
        }

        $this->assertCount(1, $hits, "README.md's reversed-ordering counterfactual is gone or no longer unique");

        // THE SENTENCE, NOT THE PARAGRAPH — and this narrowing was forced by a
        // measurement, not chosen. Written against the whole paragraph, the
        // assertion passed with the palette-alone wording restored, because the
        // RETRACTION that follows the claim also names `/theme` and the needle
        // found it there. A guard whose own explanatory prose satisfies it is
        // the round's recurring shape (see this file's key census, and
        // `docs/SETTINGS.md`'s list of stale sites), so the window stops at the
        // full stop that ends the claim.
        $sentences = preg_split('/(?<=\.)\s+/', $hits[0]) ?: [];
        $claims = array_values(array_filter(
            $sentences,
            static fn(string $sentence): bool => str_contains($sentence, 'would outrank what'),
        ));
        $this->assertCount(1, $claims, 'the counterfactual claim is no longer exactly one sentence');

        $this->assertStringContainsString(
            'Switch theme',
            $claims[0],
            "README.md's counterfactual stopped naming the palette door",
        );
        $this->assertStringContainsString(
            '/theme',
            $claims[0],
            "README.md's counterfactual credits the palette alone again — the /theme door reaches the same write",
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

        // preg_match_ALL for the reason {@see ReadmeRosterDriftTest}'s class
        // doc-block records at length: `assertSame(1, preg_match(…))` is a
        // presence check, and a second layer-4 row would be read past.
        $matched = preg_match_all('/^\| 4 \| `~\/\.sugar-crush\/config\.json` \|([^|]*)\|/m', $readme, $m);
        $this->assertSame(1, $matched, 'the layer-4 row of the README precedence table is not uniquely locatable');

        $this->assertStringContainsString('/theme', $m[1][0], 'the layer-4 row stopped crediting /theme');
        $this->assertStringContainsString('/model', $m[1][0], 'the layer-4 row stopped crediting /model');
    }
}
