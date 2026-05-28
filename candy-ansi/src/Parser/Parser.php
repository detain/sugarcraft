<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * VT500 ANSI parser state machine.
 *
 * Feeds raw bytes through the Paul-Williams state machine, dispatching
 * actions to a {@see Handler}. Handles partial input naturally — a
 * sequence split across two `feed()` calls resumes at the correct state
 * on the second call.
 *
 * Mirrors charmbracelet/x/ansi/parser. Adds a Utf8 state so multi-byte
 * runes arrive at the handler as a single `printChar(string $rune)` call.
 *
 * @see https://github.com/charmbracelet/x/tree/main/ansi/parser
 * @see https://vt100.net/emu/dec_ansi_parser
 */
final class Parser
{
    private State $state = State::Ground;

    /** @var list<int> Numeric CSI/DCS params; -1 marks a default/missing slot. */
    private array $params = [];

    /** Packed command: intermediate << 16 | prefix << 8 | final. */
    private int $cmd = 0;

    /** OSC / DCS / SOS / PM / APC payload accumulator. */
    private string $stringBuffer = '';

    /** Bytes of the current UTF-8 rune in flight. */
    private string $utf8Buffer = '';

    /** Total length of the rune we're currently collecting. */
    private int $utf8Need = 0;

    public function __construct(private readonly Handler $handler)
    {
    }

    /**
     * Feed a chunk of bytes through the state machine. Safe to call
     * repeatedly; in-flight sequences carry across calls.
     */
    public function feed(string $bytes): void
    {
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $this->advance(ord($bytes[$i]));
        }
    }

    /**
     * Force any in-flight string sequence (OSC/DCS/SOS/PM/APC) to
     * dispatch with whatever payload has been collected, and return
     * to ground. Useful at end-of-stream.
     */
    public function flush(): void
    {
        $from = $this->state;
        if ($from === State::OscString || $from === State::DcsString
            || $from === State::SosString || $from === State::PmString
            || $from === State::ApcString
        ) {
            $this->dispatch(0, $from);
        }
        $this->state = State::Ground;
        $this->utf8Buffer = '';
        $this->utf8Need = 0;
    }

    /**
     * Reset the parser to ground state, clearing all accumulated state.
     */
    public function reset(): void
    {
        $this->flush();
        $this->clear();
    }

    /** @internal Exposed for tests asserting partial-input progress. */
    public function currentState(): State
    {
        return $this->state;
    }

    private function advance(int $byte): void
    {
        if ($this->state === State::Utf8) {
            if ($byte >= 0x80 && $byte <= 0xBF) {
                $this->utf8Buffer .= chr($byte);
                if (strlen($this->utf8Buffer) >= $this->utf8Need) {
                    $this->handler->printChar($this->utf8Buffer);
                    $this->utf8Buffer = '';
                    $this->utf8Need = 0;
                    $this->state = State::Ground;
                }
                return;
            }
            // Non-continuation byte in Utf8 — drop the incomplete rune
            // and fall through with the byte processed from Ground.
            $this->utf8Buffer = '';
            $this->utf8Need = 0;
            $this->state = State::Ground;
        }

        $entry = Transitions::get($this->state->value, $byte);
        $action = Action::from(Transitions::action($entry));
        $next = State::from(Transitions::nextState($entry));

        // UTF-8 lead bytes start a rune; bypass the regular Collect.
        if ($next === State::Utf8) {
            $this->utf8Buffer = chr($byte);
            $this->utf8Need = match (true) {
                $byte <= 0xDF => 2,
                $byte <= 0xEF => 3,
                default => 4,
            };
            $this->state = State::Utf8;
            return;
        }

        $from = $this->state;
        $this->perform($action, $byte, $from);
        $this->state = $next;
    }

    private function perform(Action $action, int $byte, State $from): void
    {
        match ($action) {
            Action::None => null,
            Action::Print => $this->handler->printChar(chr($byte)),
            Action::Execute => $this->handler->execute($byte),
            Action::Clear => $this->clear(),
            Action::Collect => $this->collect($byte),
            Action::Prefix => $this->prefix($byte),
            Action::Param => $this->param($byte),
            Action::Start => $this->start($byte, $from),
            Action::Put => $this->put($byte),
            Action::Dispatch => $this->dispatch($byte, $from),
        };
    }

    private function clear(): void
    {
        $this->params = [];
        $this->cmd = 0;
        $this->stringBuffer = '';
    }

    private function collect(int $byte): void
    {
        $this->cmd = ($this->cmd & ~(0xFF << 16)) | ($byte << 16);
    }

    private function prefix(int $byte): void
    {
        $this->cmd = ($this->cmd & ~(0xFF << 8)) | ($byte << 8);
    }

    private function param(int $byte): void
    {
        // ';' (0x3B) and ':' (0x3A) both start a new param slot.
        // ':' is the sub-parameter separator per VT500 spec.
        if ($byte === 0x3B || $byte === 0x3A) {
            if (empty($this->params)) {
                $this->params[] = -1; // implicit default before the separator
            }
            $this->params[] = -1;
            return;
        }

        $digit = $byte - 0x30;
        if (empty($this->params) || $this->params[count($this->params) - 1] === -1) {
            if (empty($this->params)) {
                $this->params[] = $digit;
            } else {
                $this->params[count($this->params) - 1] = $digit;
            }
            return;
        }
        $last = count($this->params) - 1;
        $this->params[$last] = $this->params[$last] * 10 + $digit;
    }

    private function start(int $byte, State $from): void
    {
        $this->stringBuffer = '';
        // For DCS, the byte that triggers Start IS the final command.
        if ($from === State::DcsEntry || $from === State::DcsParam || $from === State::DcsIntermediate) {
            $this->cmd = ($this->cmd & ~0xFF) | $byte;
        }
    }

    private function put(int $byte): void
    {
        $this->stringBuffer .= chr($byte);
    }

    private function dispatch(int $byte, State $from): void
    {
        $prefix = ($this->cmd >> 8) & 0xFF;
        $intermediate = ($this->cmd >> 16) & 0xFF;

        switch ($from) {
            case State::OscString:
                $this->handler->oscDispatch($this->stringBuffer);
                break;
            case State::DcsString:
                $this->handler->dcsDispatch(
                    $this->cmd & 0xFF,
                    $this->params,
                    $prefix,
                    $intermediate,
                    $this->stringBuffer,
                );
                break;
            case State::SosString:
                $this->handler->sosPmApcDispatch('sos', $this->stringBuffer);
                break;
            case State::PmString:
                $this->handler->sosPmApcDispatch('pm', $this->stringBuffer);
                break;
            case State::ApcString:
                $this->handler->sosPmApcDispatch('apc', $this->stringBuffer);
                break;
            case State::Escape:
            case State::EscapeIntermediate:
                $this->handler->escDispatch($byte, $intermediate);
                break;
            default:
                $this->handler->csiDispatch($byte, $this->params, $prefix, $intermediate);
        }
        $this->stringBuffer = '';
    }
}
