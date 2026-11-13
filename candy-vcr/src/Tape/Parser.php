<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CopyDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\Directive;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\KeyDirective;
use SugarCraft\Vcr\Tape\Ast\ModifierDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
use SugarCraft\Vcr\Tape\Ast\PasteDirective;
use SugarCraft\Vcr\Tape\Ast\RequireDirective;
use SugarCraft\Vcr\Tape\Ast\ScreenshotDirective;
use SugarCraft\Vcr\Tape\Ast\SetDirective;
use SugarCraft\Vcr\Tape\Ast\ShowDirective;
use SugarCraft\Vcr\Tape\Ast\SleepDirective;
use SugarCraft\Vcr\Tape\Ast\SourceDirective;
use SugarCraft\Vcr\Tape\Ast\SpaceDirective;
use SugarCraft\Vcr\Tape\Ast\TabDirective;
use SugarCraft\Vcr\Tape\Ast\TypeDirective;
use SugarCraft\Vcr\Tape\Ast\WaitDirective;

/**
 * Recursive-descent parser for tape tokens → AST directives.
 */
final readonly class Parser
{
    /**
     * The upstream-legal `Set` sub-keywords — exactly the 21 in the measured
     * contract roster (candy-code/tests/VhsTapeContractTest.php KEYWORDS,
     * transcribed as fixture data per the S05 law against cross-target edits).
     * Pinned so a silent narrowing can never recur (finding 008); the keys
     * the renderer does not act on warn "accepted, not enforced" at compile
     * time (S09's warnings channel is the route).
     */
    private const SET_KEYS = [
        'Theme' => true,
        'FontSize' => true,
        'Width' => true,
        'Height' => true,
        'TypingSpeed' => true,
        'FontFamily' => true,
        'Padding' => true,
        'Margin' => true,
        'PlaybackSpeed' => true,
        'Shell' => true,
        'Framerate' => true,
        'LetterSpacing' => true,
        'LineHeight' => true,
        'LoopOffset' => true,
        'MarginFill' => true,
        'CursorBlink' => true,
        'BorderRadius' => true,
        'WindowBar' => true,
        'WindowBarSize' => true,
        'WaitPattern' => true,
        'WaitTimeout' => true,
    ];

    /**
     * @param list<Token> $tokens
     * @return list<Directive|ParseError>
     */
    public function parse(array $tokens): array
    {
        $directives = [];

        foreach ($tokens as $token) {
            if ($token->type === Lexer::TOKEN_COMMENT) {
                continue;
            }

            $result = $this->parseToken($token);
            if ($result instanceof ParseError) {
                $directives[] = $result;
            } elseif ($result !== null) {
                $directives[] = $result;
            }
        }

        return $directives;
    }

    /**
     * @return Directive|ParseError|null
     */
    private function parseToken(Token $token): Directive|ParseError|null
    {
        return match ($token->type) {
            Lexer::TOKEN_TYPE => new TypeDirective($token->value),

            Lexer::TOKEN_ENTER => new EnterDirective(),

            Lexer::TOKEN_TAB => new TabDirective(),

            Lexer::TOKEN_BACKSPACE => new BackspaceDirective(),

            Lexer::TOKEN_SLEEP => new SleepDirective((float) $token->value),

            Lexer::TOKEN_SET => $this->parseSet($token),

            Lexer::TOKEN_ENV => $this->parseEnv($token),

            Lexer::TOKEN_OUTPUT => new OutputDirective($token->value),

            Lexer::TOKEN_ARROW => new ArrowDirective($token->value),

            Lexer::TOKEN_CTRL => new CtrlDirective($token->value),

            Lexer::TOKEN_KEY => new KeyDirective($token->value),

            Lexer::TOKEN_MODIFIER => new ModifierDirective($token->value),

            Lexer::TOKEN_COPY => $token->value === ''
                ? new ParseError($token->line, 'Copy expects string') // upstream's measured phrase
                : new CopyDirective($token->value),

            Lexer::TOKEN_PASTE => new PasteDirective(),

            Lexer::TOKEN_REQUIRE => $token->value === ''
                ? new ParseError($token->line, 'Require expects one string') // upstream's measured phrase
                : new RequireDirective($token->value),

            Lexer::TOKEN_SPACE => new SpaceDirective(),

            Lexer::TOKEN_ESCAPE => new EscapeDirective(),

            Lexer::TOKEN_HIDE => new HideDirective(),

            Lexer::TOKEN_SHOW => new ShowDirective(),

            Lexer::TOKEN_WAIT => new WaitDirective((float) $token->value),

            Lexer::TOKEN_SCREEN => null,

            Lexer::TOKEN_SCREENSHOT => new ScreenshotDirective($token->value),

            Lexer::TOKEN_SOURCE => new SourceDirective($token->value),

            // Finding 005's silence close: an unclassifiable line used to be
            // dropped `null` — `Typ "hello"` deleted a whole keystroke group
            // even under `--strict`, because the strict gate only sees
            // ParseError NODES. The head word now becomes one, so non-strict
            // mode warns (Compiler collects it as a parse problem) and
            // strict mode aborts. Argument-shaped heads answer upstream's
            // own measured phrases.
            Lexer::TOKEN_UNKNOWN => new ParseError($token->line, self::unknownDirectiveMessage($token->value)),

            // E15 grammar port classified JSON/REGEX values by the lexer (so
            // they can never hide the directive behind them). A grammar follow-up gave REGEX
            // its directive. TOKEN_REGEX arrives from BOTH lexer arms that
            // emit it: the `Wait /re/` arm (which strips the keyword) and the
            // generic value arm, which starts a line-initial bare `/re/` on
            // the same footing as a keyword-led one. At statement position a
            // regex can spell nothing but the condition wait, so both arms
            // compile to one. The bare path used to fall to the silent `null`
            // drops below; promoting a token no consumer ever read into what
            // it reads as is the truthful mapping, not a restriction — an
            // intentional strictness gain, pinned by no old-drop expectation.
            // JSON still drops by KIND (no consumer), never by text sniff.
            Lexer::TOKEN_JSON  => null,
            Lexer::TOKEN_REGEX => new WaitDirective(0.0, $token->value),

            default => null,
        };
    }

    private function parseSet(Token $token): SetDirective|ParseError
    {
        $parts = explode("\x00", $token->value, 2);
        $key = $parts[0];
        $value = $parts[1] ?? '';

        if (!isset(self::SET_KEYS[$key])) {
            return new ParseError(
                $token->line,
                "Unknown Set key '{$key}'. Allowed: " . implode(', ', array_keys(self::SET_KEYS)),
            );
        }

        return new SetDirective($key, $value);
    }

    private function parseEnv(Token $token): EnvDirective
    {
        $parts = explode("\x00", $token->value, 2);
        return new EnvDirective($parts[0], $parts[1] ?? '');
    }

    /**
     * Classify an UNKNOWN run by its head word. Argument-taking commands
     * answer upstream's measured phrases (`VhsTapeContractTest`: `parseType`
     * and `parseCopy` build "Type/Copy expects string", `parseRequire`
     * "Require expects one string"); everything else is named as an unknown
     * directive. Either way it is a node the strict gate can finally see.
     */
    private static function unknownDirectiveMessage(string $value): string
    {
        $head = preg_split('/\s+/', trim($value), 2)[0] ?? '';

        return match ($head) {
            'Type'    => 'Type expects string',
            'Copy'    => 'Copy expects string',
            'Require' => 'Require expects one string',
            default   => "Unknown directive: {$head}",
        };
    }
}
