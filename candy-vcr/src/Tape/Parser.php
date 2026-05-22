<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\Directive;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
use SugarCraft\Vcr\Tape\Ast\ScreenshotDirective;
use SugarCraft\Vcr\Tape\Ast\SetDirective;
use SugarCraft\Vcr\Tape\Ast\ShowDirective;
use SugarCraft\Vcr\Tape\Ast\SleepDirective;
use SugarCraft\Vcr\Tape\Ast\SpaceDirective;
use SugarCraft\Vcr\Tape\Ast\TabDirective;
use SugarCraft\Vcr\Tape\Ast\TypeDirective;
use SugarCraft\Vcr\Tape\Ast\WaitDirective;

/**
 * Recursive-descent parser for tape tokens → AST directives.
 */
final readonly class Parser
{
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

            Lexer::TOKEN_SPACE => new SpaceDirective(),

            Lexer::TOKEN_ESCAPE => new EscapeDirective(),

            Lexer::TOKEN_HIDE => new HideDirective(),

            Lexer::TOKEN_SHOW => new ShowDirective(),

            Lexer::TOKEN_WAIT => new WaitDirective((float) $token->value),

            Lexer::TOKEN_SCREEN => null,

            Lexer::TOKEN_SCREENSHOT => new ScreenshotDirective($token->value),

            Lexer::TOKEN_UNKNOWN => null,

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
}
