<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * VT500 ANSI transition table.
 *
 * Generated at first use as a 4096-byte string (15 states × 256 codes).
 * Each entry packs `(action << 4) | nextState` into one byte.
 *
 * Mirrors charmbracelet/x/ansi/parser.GenerateTransitionTable() — same
 * tweaks: a Utf8 state is added; OSC/DCS string ranges extend to 0xFF;
 * ':' (0x3A) is a sub-parameter separator; SOS/PM/APC dispatch; DEL
 * executes in Ground; ST C1 (0x9C) is dispatched not ignored.
 *
 * @see https://github.com/charmbracelet/x/blob/main/ansi/parser/transition_table.go
 */
final class Transitions
{
    private const SIZE = 4096;
    private const STATE_SHIFT = 8;
    private const ACTION_SHIFT = 4;
    private const STATE_MASK = 0x0F;

    private static ?string $table = null;

    /**
     * Look up the packed transition entry for a given state and byte.
     *
     * Returns `(action << 4) | nextState`. Use {@see Transitions::action()}
     * and {@see Transitions::nextState()} to unpack.
     */
    public static function get(int $state, int $byte): int
    {
        return ord((self::$table ??= self::build())[($state << self::STATE_SHIFT) | $byte]);
    }

    public static function action(int $entry): int
    {
        return $entry >> self::ACTION_SHIFT;
    }

    public static function nextState(int $entry): int
    {
        return $entry & self::STATE_MASK;
    }

    private static function build(): string
    {
        $g = State::Ground->value;
        $t = str_repeat(self::pack(Action::None->value, $g), self::SIZE);

        $set = static function (int $state, int $byte, int $action, int $next) use (&$t): void {
            $t[($state << 8) | $byte] = self::pack($action, $next);
        };

        // Range helpers
        $setRange = static function (int $state, int $start, int $end, int $action, int $next) use ($set): void {
            for ($b = $start; $b <= $end; $b++) {
                $set($state, $b, $action, $next);
            }
        };

        $setMany = static function (int $state, array $bytes, int $action, int $next) use ($set): void {
            foreach ($bytes as $b) {
                $set($state, $b, $action, $next);
            }
        };

        // Anywhere transitions — applied to every state including Utf8
        for ($state = 0; $state <= State::Utf8->value; $state++) {
            // Anywhere -> Ground
            $setMany($state, [0x18, 0x1A, 0x99, 0x9A], Action::Execute->value, $g);
            $setRange($state, 0x80, 0x8F, Action::Execute->value, $g);
            $setRange($state, 0x90, 0x97, Action::Execute->value, $g);
            $set($state, 0x9C, Action::Execute->value, $g);
            // Anywhere -> Escape
            $set($state, 0x1B, Action::Clear->value, State::Escape->value);
            // Anywhere -> SosString / PmString / ApcString
            $set($state, 0x98, Action::Start->value, State::SosString->value);
            $set($state, 0x9E, Action::Start->value, State::PmString->value);
            $set($state, 0x9F, Action::Start->value, State::ApcString->value);
            // Anywhere -> CsiEntry / DcsEntry / OscString (C1 8-bit forms)
            $set($state, 0x9B, Action::Clear->value, State::CsiEntry->value);
            $set($state, 0x90, Action::Clear->value, State::DcsEntry->value);
            $set($state, 0x9D, Action::Start->value, State::OscString->value);
            // Anywhere -> Utf8
            $setRange($state, 0xC2, 0xDF, Action::Collect->value, State::Utf8->value);
            $setRange($state, 0xE0, 0xEF, Action::Collect->value, State::Utf8->value);
            $setRange($state, 0xF0, 0xF4, Action::Collect->value, State::Utf8->value);
        }

        $G = State::Ground->value;
        $E = State::Escape->value;
        $EI = State::EscapeIntermediate->value;
        $CE = State::CsiEntry->value;
        $CP = State::CsiParam->value;
        $CI = State::CsiIntermediate->value;
        $DE = State::DcsEntry->value;
        $DP = State::DcsParam->value;
        $DI = State::DcsIntermediate->value;
        $DS = State::DcsString->value;
        $OS = State::OscString->value;
        $SS = State::SosString->value;
        $PS = State::PmString->value;
        $AS = State::ApcString->value;

        // Ground
        $setRange($G, 0x00, 0x17, Action::Execute->value, $G);
        $set($G, 0x19, Action::Execute->value, $G);
        $setRange($G, 0x1C, 0x1F, Action::Execute->value, $G);
        $setRange($G, 0x20, 0x7E, Action::Print->value, $G);
        $set($G, 0x7F, Action::Execute->value, $G);

        // EscapeIntermediate
        $setRange($EI, 0x00, 0x17, Action::Execute->value, $EI);
        $set($EI, 0x19, Action::Execute->value, $EI);
        $setRange($EI, 0x1C, 0x1F, Action::Execute->value, $EI);
        $setRange($EI, 0x20, 0x2F, Action::Collect->value, $EI);
        $set($EI, 0x7F, Action::None->value, $EI);
        $setRange($EI, 0x30, 0x7E, Action::Dispatch->value, $G);

        // Escape
        $setRange($E, 0x00, 0x17, Action::Execute->value, $E);
        $set($E, 0x19, Action::Execute->value, $E);
        $setRange($E, 0x1C, 0x1F, Action::Execute->value, $E);
        $set($E, 0x7F, Action::None->value, $E);
        $setRange($E, 0x30, 0x4F, Action::Dispatch->value, $G);
        $setRange($E, 0x51, 0x57, Action::Dispatch->value, $G);
        $set($E, 0x59, Action::Dispatch->value, $G);
        $set($E, 0x5A, Action::Dispatch->value, $G);
        $set($E, 0x5C, Action::Dispatch->value, $G);
        $setRange($E, 0x60, 0x7E, Action::Dispatch->value, $G);
        $setRange($E, 0x20, 0x2F, Action::Collect->value, $EI);
        $set($E, ord('X'), Action::Start->value, $SS);
        $set($E, ord('^'), Action::Start->value, $PS);
        $set($E, ord('_'), Action::Start->value, $AS);
        $set($E, ord('P'), Action::Clear->value, $DE);
        $set($E, ord('['), Action::Clear->value, $CE);
        $set($E, ord(']'), Action::Start->value, $OS);

        // SOS / PM / APC strings
        foreach ([$SS, $PS, $AS] as $state) {
            $setRange($state, 0x00, 0x17, Action::Put->value, $state);
            $set($state, 0x19, Action::Put->value, $state);
            $setRange($state, 0x1C, 0x1F, Action::Put->value, $state);
            $setRange($state, 0x20, 0x7F, Action::Put->value, $state);
            $set($state, 0x1B, Action::Dispatch->value, $E);
            $set($state, 0x9C, Action::Dispatch->value, $G);
            $setMany($state, [0x18, 0x1A], Action::None->value, $G);
        }

        // DcsEntry
        $setRange($DE, 0x00, 0x07, Action::None->value, $DE);
        $setRange($DE, 0x0E, 0x17, Action::None->value, $DE);
        $set($DE, 0x19, Action::None->value, $DE);
        $setRange($DE, 0x1C, 0x1F, Action::None->value, $DE);
        $set($DE, 0x7F, Action::None->value, $DE);
        $setRange($DE, 0x20, 0x2F, Action::Collect->value, $DI);
        $setRange($DE, 0x30, 0x3B, Action::Param->value, $DP); // ':' and ';' are param bytes per VT500
        $setRange($DE, 0x3C, 0x3F, Action::Prefix->value, $DP);
        $setRange($DE, 0x08, 0x0D, Action::Put->value, $DS);
        $set($DE, 0x1B, Action::Put->value, $DS); // Tmux passthrough quirk
        $setRange($DE, 0x40, 0x7E, Action::Start->value, $DS);

        // DcsIntermediate
        $setRange($DI, 0x00, 0x17, Action::None->value, $DI);
        $set($DI, 0x19, Action::None->value, $DI);
        $setRange($DI, 0x1C, 0x1F, Action::None->value, $DI);
        $setRange($DI, 0x20, 0x2F, Action::Collect->value, $DI);
        $set($DI, 0x7F, Action::None->value, $DI);
        $setRange($DI, 0x30, 0x3F, Action::Start->value, $DS);
        $setRange($DI, 0x40, 0x7E, Action::Start->value, $DS);

        // DcsParam
        $setRange($DP, 0x00, 0x17, Action::None->value, $DP);
        $set($DP, 0x19, Action::None->value, $DP);
        $setRange($DP, 0x1C, 0x1F, Action::None->value, $DP);
        $setRange($DP, 0x30, 0x3B, Action::Param->value, $DP); // ':' and ';' are param bytes per VT500
        $set($DP, 0x7F, Action::None->value, $DP);
        $setRange($DP, 0x3C, 0x3F, Action::None->value, $DP);
        $setRange($DP, 0x20, 0x2F, Action::Collect->value, $DI);
        $setRange($DP, 0x40, 0x7E, Action::Start->value, $DS);

        // DcsString (passthrough)
        $setRange($DS, 0x00, 0x17, Action::Put->value, $DS);
        $set($DS, 0x19, Action::Put->value, $DS);
        $setRange($DS, 0x1C, 0x1F, Action::Put->value, $DS);
        $setRange($DS, 0x20, 0x7E, Action::Put->value, $DS);
        $set($DS, 0x7F, Action::Put->value, $DS);
        $setRange($DS, 0x80, 0xFF, Action::Put->value, $DS);
        $set($DS, 0x1B, Action::Dispatch->value, $E);
        $set($DS, 0x9C, Action::Dispatch->value, $G);
        $setMany($DS, [0x18, 0x1A], Action::None->value, $G);

        // CsiParam
        $setRange($CP, 0x00, 0x17, Action::Execute->value, $CP);
        $set($CP, 0x19, Action::Execute->value, $CP);
        $setRange($CP, 0x1C, 0x1F, Action::Execute->value, $CP);
        $setRange($CP, 0x30, 0x3B, Action::Param->value, $CP); // ':' and ';' are param bytes per VT500
        $set($CP, 0x7F, Action::None->value, $CP);
        $setRange($CP, 0x3C, 0x3F, Action::None->value, $CP);
        $setRange($CP, 0x40, 0x7E, Action::Dispatch->value, $G);
        $setRange($CP, 0x20, 0x2F, Action::Collect->value, $CI);

        // CsiIntermediate
        $setRange($CI, 0x00, 0x17, Action::Execute->value, $CI);
        $set($CI, 0x19, Action::Execute->value, $CI);
        $setRange($CI, 0x1C, 0x1F, Action::Execute->value, $CI);
        $setRange($CI, 0x20, 0x2F, Action::Collect->value, $CI);
        $set($CI, 0x7F, Action::None->value, $CI);
        $setRange($CI, 0x40, 0x7E, Action::Dispatch->value, $G);
        $setRange($CI, 0x30, 0x3F, Action::None->value, $G);

        // CsiEntry
        $setRange($CE, 0x00, 0x17, Action::Execute->value, $CE);
        $set($CE, 0x19, Action::Execute->value, $CE);
        $setRange($CE, 0x1C, 0x1F, Action::Execute->value, $CE);
        $set($CE, 0x7F, Action::None->value, $CE);
        $setRange($CE, 0x40, 0x7E, Action::Dispatch->value, $G);
        $setRange($CE, 0x20, 0x2F, Action::Collect->value, $CI);
        $setRange($CE, 0x30, 0x3B, Action::Param->value, $CP); // ':' and ';' are param bytes per VT500
        $setRange($CE, 0x3C, 0x3F, Action::Prefix->value, $CP);

        // OscString
        $setRange($OS, 0x00, 0x06, Action::None->value, $OS);
        $setRange($OS, 0x08, 0x17, Action::None->value, $OS);
        $set($OS, 0x19, Action::None->value, $OS);
        $setRange($OS, 0x1C, 0x1F, Action::None->value, $OS);
        $setRange($OS, 0x20, 0xFF, Action::Put->value, $OS);
        $set($OS, 0x1B, Action::Dispatch->value, $E);
        $set($OS, 0x07, Action::Dispatch->value, $G);
        $set($OS, 0x9C, Action::Dispatch->value, $G);
        $setMany($OS, [0x18, 0x1A], Action::None->value, $G);

        return $t;
    }

    private static function pack(int $action, int $state): string
    {
        return chr(($action << self::ACTION_SHIFT) | $state);
    }
}
