<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tape;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Ast\ArrowDirective;
use SugarCraft\Vcr\Tape\Ast\BackspaceDirective;
use SugarCraft\Vcr\Tape\Ast\CtrlDirective;
use SugarCraft\Vcr\Tape\Ast\Directive;
use SugarCraft\Vcr\Tape\Ast\EnterDirective;
use SugarCraft\Vcr\Tape\Ast\EnvDirective;
use SugarCraft\Vcr\Tape\Ast\EscapeDirective;
use SugarCraft\Vcr\Tape\Ast\HideDirective;
use SugarCraft\Vcr\Tape\Ast\OutputDirective;
use SugarCraft\Vcr\Tape\Ast\ParseError;
use SugarCraft\Vcr\Tape\Ast\SetDirective;
use SugarCraft\Vcr\Tape\Ast\ShowDirective;
use SugarCraft\Vcr\Tape\Ast\SleepDirective;
use SugarCraft\Vcr\Tape\Ast\SpaceDirective;
use SugarCraft\Vcr\Tape\Ast\TabDirective;
use SugarCraft\Vcr\Tape\Ast\TypeDirective;
use SugarCraft\Vcr\Tape\Ast\WaitDirective;

/**
 * Compiles a directive AST into a Cassette with events.
 */
final class Compiler
{
    private float $typingSpeed = 50.0;
    private int $cols = 80;
    private int $rows = 24;
    private string $theme = 'TokyoNight';
    /** @var array<string, string> */
    private array $env = [];

    private float $currentTime = 0.0;

    /** @var list<Event> */
    private array $events = [];

    /**
     * @param list<Directive|ParseError> $ast
     */
    public function compile(array $ast, string $sourcePath): Cassette
    {
        $this->reset();

        foreach ($ast as $node) {
            if ($node instanceof ParseError) {
                continue;
            }
            $this->compileNode($node);
        }

        $header = new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: date('c'),
            cols: $this->cols,
            rows: $this->rows,
            runtime: 'SugarCraft/Vcr',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_ABSOLUTE,
            env: $this->env,
            typingSpeed: $this->typingSpeed,
        );

        return new Cassette($header, $this->events);
    }

    /**
     * @return array{ast: list<Directive>, errors: list<ParseError>}
     */
    public static function parseSource(string $source): array
    {
        $lexer = new Lexer();
        $parser = new Parser();

        $tokens = $lexer->tokenize($source);
        $ast = $parser->parse($tokens);

        $errors = [];
        $directives = [];

        foreach ($ast as $node) {
            if ($node instanceof ParseError) {
                $errors[] = $node;
            } else {
                $directives[] = $node;
            }
        }

        return ['ast' => $directives, 'errors' => $errors];
    }

    private function reset(): void
    {
        $this->typingSpeed = 50.0;
        $this->cols = 80;
        $this->rows = 24;
        $this->theme = 'TokyoNight';
        $this->env = [];
        $this->currentTime = 0.0;
        $this->events = [];
    }

    private function compileNode(Directive $node): void
    {
        match (true) {
            $node instanceof OutputDirective => null,
            $node instanceof SetDirective => $this->compileSet($node),
            $node instanceof EnvDirective => $this->env[$node->key] = trim($node->value, '"\' '),
            $node instanceof TypeDirective => $this->compileType($node),
            $node instanceof EnterDirective => $this->emitInputBytes("\r"),
            $node instanceof TabDirective => $this->emitInputBytes("\t"),
            $node instanceof BackspaceDirective => $this->emitInputBytes("\x7f"),
            $node instanceof ArrowDirective => $this->compileArrow($node),
            $node instanceof CtrlDirective => $this->compileCtrl($node),
            $node instanceof SpaceDirective => $this->emitInputBytes(' '),
            $node instanceof EscapeDirective => $this->emitInputBytes("\x1b"),
            $node instanceof SleepDirective => $this->currentTime += $node->seconds,
            $node instanceof WaitDirective => $this->currentTime += $node->seconds,
            $node instanceof HideDirective, $node instanceof ShowDirective => null,
            default => null,
        };
    }

    private function compileSet(SetDirective $node): void
    {
        match ($node->key) {
            'Width' => $this->cols = (int) $node->value,
            'Height' => $this->rows = (int) $node->value,
            'Theme' => $this->theme = trim($node->value, '"\' '),
            'TypingSpeed' => $this->typingSpeed = $this->parseTypingSpeed($node->value),
            default => null,
        };
    }

    private function parseTypingSpeed(string $value): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*ms$/i', $value, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*s$/i', $value, $m)) {
            return (float) $m[1] * 1000.0;
        }
        return (float) ($value ?: 50.0);
    }

    private function compileType(TypeDirective $node): void
    {
        $chars = mb_str_split($node->text);

        foreach ($chars as $char) {
            $byte = $this->charToByte($char);
            if ($byte !== null) {
                $this->emitInputBytes($byte);
            }
            $this->currentTime += $this->typingSpeed / 1000.0;
        }
    }

    /**
     * Convert a character to its raw byte representation matching InputReader.decodeChar().
     * Returns null for characters that don't emit bytes (e.g., multi-byte chars beyond ASCII).
     */
    private function charToByte(string $char): ?string
    {
        $code = mb_ord($char, 'UTF-8');

        if ($code === 0x09) {
            return "\t";
        }
        if ($code === 0x0d || $code === 0x0a) {
            return "\r";
        }
        if ($code === 0x7f || $code === 0x08) {
            return "\x7f";
        }
        if ($code === 0x20) {
            return ' ';
        }
        if ($code === 0x1b) {
            return "\x1b";
        }
        if ($code >= 1 && $code <= 26) {
            return chr($code);
        }
        if ($code >= 0x20 && $code < 0x7f) {
            return chr($code);
        }

        return null;
    }

    private function compileArrow(ArrowDirective $node): void
    {
        $bytes = match ($node->direction) {
            'Up' => "\x1b[A",
            'Down' => "\x1b[B",
            'Left' => "\x1b[D",
            'Right' => "\x1b[C",
            default => '',
        };
        if ($bytes !== '') {
            $this->emitInputBytes($bytes);
        }
    }

    private function compileCtrl(CtrlDirective $node): void
    {
        $letter = $node->letter;
        $ord = ord($letter);
        if ($ord >= 65 && $ord <= 90) {
            $ctrlCode = $ord - 64;
        } elseif ($ord >= 97 && $ord <= 122) {
            $ctrlCode = $ord - 96;
        } elseif ($letter === '@') {
            $ctrlCode = 0;
        } elseif ($letter === '[') {
            $ctrlCode = 27;
        } elseif ($letter === '\\') {
            $ctrlCode = 28;
        } elseif ($letter === ']') {
            $ctrlCode = 29;
        } elseif ($letter === '^') {
            $ctrlCode = 30;
        } elseif ($letter === '_') {
            $ctrlCode = 31;
        } else {
            $ctrlCode = $ord & 0x1F;
        }
        $this->emitInputBytes(chr($ctrlCode));
    }

    private function emitInputBytes(string $bytes): void
    {
        $this->events[] = new Event(
            $this->currentTime,
            EventKind::Input,
            ['b' => $bytes],
        );
    }

}
