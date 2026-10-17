<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Logical key types emitted by the input parser. {@see KeyType::Char} carries
 * the actual rune in the {@see Msg\KeyMsg::$rune} field; all other cases are
 * named keys whose rune is irrelevant.
 *
 * Keypad / media / lock / system / modifier-as-key cases are reachable via
 * the Kitty progressive keyboard protocol (Private Use Area codepoints
 * 0xE000+) decoded by {@see InputReader::decodeKittyKey()}.
 */
enum KeyType: string
{
    case Char      = 'char';

    // Cursor / navigation
    case Up        = 'up';
    case Down      = 'down';
    case Left      = 'left';
    case Right     = 'right';
    case Begin     = 'begin';
    case Find      = 'find';
    case Select    = 'select';
    case Extended  = 'extended';

    // Editing
    case Enter     = 'enter';
    case Escape    = 'escape';
    case Tab       = 'tab';
    case Backspace = 'backspace';
    case Space     = 'space';
    case Delete    = 'delete';
    case Insert    = 'insert';
    case Home      = 'home';
    case End       = 'end';
    case PageUp    = 'pageup';
    case PageDown  = 'pagedown';

    // F-keys (upstream goes to F63; we cover F1-F35, the Kitty PUA range).
    case F1  = 'f1';
    case F2  = 'f2';
    case F3  = 'f3';
    case F4  = 'f4';
    case F5  = 'f5';
    case F6  = 'f6';
    case F7  = 'f7';
    case F8  = 'f8';
    case F9  = 'f9';
    case F10 = 'f10';
    case F11 = 'f11';
    case F12 = 'f12';
    case F13 = 'f13';
    case F14 = 'f14';
    case F15 = 'f15';
    case F16 = 'f16';
    case F17 = 'f17';
    case F18 = 'f18';
    case F19 = 'f19';
    case F20 = 'f20';
    case F21 = 'f21';
    case F22 = 'f22';
    case F23 = 'f23';
    case F24 = 'f24';
    case F25 = 'f25';
    case F26 = 'f26';
    case F27 = 'f27';
    case F28 = 'f28';
    case F29 = 'f29';
    case F30 = 'f30';
    case F31 = 'f31';
    case F32 = 'f32';
    case F33 = 'f33';
    case F34 = 'f34';
    case F35 = 'f35';
    case F36 = 'f36';
    case F37 = 'f37';
    case F38 = 'f38';
    case F39 = 'f39';
    case F40 = 'f40';
    case F41 = 'f41';
    case F42 = 'f42';
    case F43 = 'f43';
    case F44 = 'f44';
    case F45 = 'f45';
    case F46 = 'f46';
    case F47 = 'f47';
    case F48 = 'f48';
    case F49 = 'f49';
    case F50 = 'f50';
    case F51 = 'f51';
    case F52 = 'f52';
    case F53 = 'f53';
    case F54 = 'f54';
    case F55 = 'f55';
    case F56 = 'f56';
    case F57 = 'f57';
    case F58 = 'f58';
    case F59 = 'f59';
    case F60 = 'f60';
    case F61 = 'f61';
    case F62 = 'f62';
    case F63 = 'f63';

    // Numeric keypad
    case Kp0           = 'kp0';
    case Kp1           = 'kp1';
    case Kp2           = 'kp2';
    case Kp3           = 'kp3';
    case Kp4           = 'kp4';
    case Kp5           = 'kp5';
    case Kp6           = 'kp6';
    case Kp7           = 'kp7';
    case Kp8           = 'kp8';
    case Kp9           = 'kp9';
    case KpDecimal     = 'kp_decimal';
    case KpDivide      = 'kp_divide';
    case KpMultiply    = 'kp_multiply';
    case KpSubtract    = 'kp_subtract';
    case KpAdd         = 'kp_add';
    case KpEnter       = 'kp_enter';
    case KpEqual       = 'kp_equal';
    case KpSeparator   = 'kp_separator';
    case KpLeft        = 'kp_left';
    case KpRight       = 'kp_right';
    case KpUp          = 'kp_up';
    case KpDown        = 'kp_down';
    case KpPageUp      = 'kp_pageup';
    case KpPageDown    = 'kp_pagedown';
    case KpHome        = 'kp_home';
    case KpEnd         = 'kp_end';
    case KpInsert      = 'kp_insert';
    case KpDelete      = 'kp_delete';
    case KpBegin       = 'kp_begin';

    // Media keys
    case MediaPlay         = 'media_play';
    case MediaPause        = 'media_pause';
    case MediaPlayPause    = 'media_play_pause';
    case MediaReverse      = 'media_reverse';
    case MediaStop         = 'media_stop';
    case MediaFastForward  = 'media_fast_forward';
    case MediaRewind       = 'media_rewind';
    case MediaNext         = 'media_next';
    case MediaPrev         = 'media_prev';
    case MediaRecord       = 'media_record';
    case LowerVolume       = 'lower_volume';
    case RaiseVolume       = 'raise_volume';
    case MuteVolume        = 'mute_volume';

    // Lock + system
    case CapsLock      = 'caps_lock';
    case ScrollLock    = 'scroll_lock';
    case NumLock       = 'num_lock';
    case PrintScreen   = 'print_screen';
    case Pause         = 'pause';
    case Menu          = 'menu';

    // Modifier-as-key (when the user presses a modifier on its own
    // and the terminal reports it via the Kitty protocol)
    case LeftShift        = 'left_shift';
    case RightShift       = 'right_shift';
    case LeftCtrl         = 'left_ctrl';
    case RightCtrl        = 'right_ctrl';
    case LeftAlt          = 'left_alt';
    case RightAlt         = 'right_alt';
    case LeftSuper        = 'left_super';
    case RightSuper       = 'right_super';
    case LeftHyper        = 'left_hyper';
    case RightHyper       = 'right_hyper';
    case LeftMeta         = 'left_meta';
    case RightMeta        = 'right_meta';
    case IsoLevel3Shift   = 'iso_level3_shift';
    case IsoLevel5Shift   = 'iso_level5_shift';

    /**
     * Kitty progressive keyboard protocol functional-key codepoint table.
     * Maps a Kitty PUA codepoint (0xE000+) to its KeyType. Codepoints not
     * in this table are treated as printable Char by the input parser.
     *
     * @return array<int, self>
     */
    public static function kittyFunctionalKeys(): array
    {
        return [
            // Editing keys (some redundant with C0 controls; Kitty also
            // emits these PUA codepoints in disambiguation mode).
            57344 => self::Escape,
            57345 => self::Enter,
            57346 => self::Tab,
            57347 => self::Backspace,
            57348 => self::Insert,
            57349 => self::Delete,

            // Arrows
            57350 => self::Left,
            57351 => self::Right,
            57352 => self::Up,
            57353 => self::Down,
            57354 => self::PageUp,
            57355 => self::PageDown,
            57356 => self::Home,
            57357 => self::End,

            // Locks + system
            57358 => self::CapsLock,
            57359 => self::ScrollLock,
            57360 => self::NumLock,
            57361 => self::PrintScreen,
            57362 => self::Pause,
            57363 => self::Menu,

            // F1–F35 (Kitty range; upstream surfaces F36–F63 from xterm
            // CSI 1;m;NN~ extensions, mapped separately by the legacy
            // CSI parser).
            57364 => self::F1,
            57365 => self::F2,
            57366 => self::F3,
            57367 => self::F4,
            57368 => self::F5,
            57369 => self::F6,
            57370 => self::F7,
            57371 => self::F8,
            57372 => self::F9,
            57373 => self::F10,
            57374 => self::F11,
            57375 => self::F12,
            57376 => self::F13,
            57377 => self::F14,
            57378 => self::F15,
            57379 => self::F16,
            57380 => self::F17,
            57381 => self::F18,
            57382 => self::F19,
            57383 => self::F20,
            57384 => self::F21,
            57385 => self::F22,
            57386 => self::F23,
            57387 => self::F24,
            57388 => self::F25,
            57389 => self::F26,
            57390 => self::F27,
            57391 => self::F28,
            57392 => self::F29,
            57393 => self::F30,
            57394 => self::F31,
            57395 => self::F32,
            57396 => self::F33,
            57397 => self::F34,
            57398 => self::F35,

            // Keypad
            57399 => self::Kp0,
            57400 => self::Kp1,
            57401 => self::Kp2,
            57402 => self::Kp3,
            57403 => self::Kp4,
            57404 => self::Kp5,
            57405 => self::Kp6,
            57406 => self::Kp7,
            57407 => self::Kp8,
            57408 => self::Kp9,
            57409 => self::KpDecimal,
            57410 => self::KpDivide,
            57411 => self::KpMultiply,
            57412 => self::KpSubtract,
            57413 => self::KpAdd,
            57414 => self::KpEnter,
            57415 => self::KpEqual,
            57416 => self::KpSeparator,
            57417 => self::KpLeft,
            57418 => self::KpRight,
            57419 => self::KpUp,
            57420 => self::KpDown,
            57421 => self::KpPageUp,
            57422 => self::KpPageDown,
            57423 => self::KpHome,
            57424 => self::KpEnd,
            57425 => self::KpInsert,
            57426 => self::KpDelete,
            57427 => self::KpBegin,

            // Media
            57428 => self::MediaPlay,
            57429 => self::MediaPause,
            57430 => self::MediaPlayPause,
            57431 => self::MediaReverse,
            57432 => self::MediaStop,
            57433 => self::MediaFastForward,
            57434 => self::MediaRewind,
            57435 => self::MediaNext,
            57436 => self::MediaPrev,
            57437 => self::MediaRecord,
            57438 => self::LowerVolume,
            57439 => self::RaiseVolume,
            57440 => self::MuteVolume,

            // Modifier-as-key
            57441 => self::LeftShift,
            57442 => self::LeftCtrl,
            57443 => self::LeftAlt,
            57444 => self::LeftSuper,
            57445 => self::LeftHyper,
            57446 => self::LeftMeta,
            57447 => self::RightShift,
            57448 => self::RightCtrl,
            57449 => self::RightAlt,
            57450 => self::RightSuper,
            57451 => self::RightHyper,
            57452 => self::RightMeta,
            57453 => self::IsoLevel3Shift,
            57454 => self::IsoLevel5Shift,
        ];
    }
}
