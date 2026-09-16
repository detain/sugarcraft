<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Msg;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\CursorPositionMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\FocusLostMsg;
use SugarCraft\Core\Msg\ForegroundColorMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Vt\Msg\FocusInMsg;
use SugarCraft\Vt\Msg\FocusOutMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Vcr\Support\Scalars;

/**
 * Serializer for the candy-core Msg classes commonly produced by the
 * input parser. Coverage:
 *
 * - KeyMsg
 * - MouseClickMsg / MouseMotionMsg / MouseWheelMsg / MouseReleaseMsg
 * - WindowSizeMsg
 * - FocusGainedMsg / FocusLostMsg / BlurMsg
 * - FocusInMsg / FocusOutMsg (candy-vt CSI I/O focus events)
 * - PasteStartMsg / PasteEndMsg / PasteMsg
 * - BackgroundColorMsg / ForegroundColorMsg
 * - CursorPositionMsg
 *
 * Uses the unqualified class name as `@type`, matching the cassette
 * format spec in `plans/x-vcr.md`. Anything outside this set falls
 * through to the next serializer in the registry (typically
 * {@see JsonableSerializer}).
 */
final class BuiltinSerializer implements MsgSerializer
{
    /**
     * Class-name → encoder/decoder pair. Iteration order is preserved
     * so `canEncode`'s first-match wins is deterministic.
     *
     * @var array<class-string, array{encode: \Closure, decode: \Closure}>
     */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [
            KeyMsg::class => [
                'encode' => static fn (KeyMsg $m): array => [
                    '@type' => 'KeyMsg',
                    'type' => $m->type->value,
                    'rune' => $m->rune,
                    'alt' => $m->alt,
                    'ctrl' => $m->ctrl,
                    'shift' => $m->shift,
                ],
                'decode' => static fn (array $e): KeyMsg => new KeyMsg(
                    type: KeyType::from(Scalars::string($e['type'] ?? '', 'KeyMsg type')),
                    rune: Scalars::string($e['rune'] ?? '', 'KeyMsg rune'),
                    alt: (bool) ($e['alt'] ?? false),
                    ctrl: (bool) ($e['ctrl'] ?? false),
                    shift: (bool) ($e['shift'] ?? false),
                ),
            ],
            MouseClickMsg::class => self::mouseHandler(MouseClickMsg::class, 'MouseClickMsg'),
            MouseMotionMsg::class => self::mouseHandler(MouseMotionMsg::class, 'MouseMotionMsg'),
            MouseWheelMsg::class => self::mouseHandler(MouseWheelMsg::class, 'MouseWheelMsg'),
            MouseReleaseMsg::class => self::mouseHandler(MouseReleaseMsg::class, 'MouseReleaseMsg'),
            WindowSizeMsg::class => [
                'encode' => static fn (WindowSizeMsg $m): array => [
                    '@type' => 'WindowSizeMsg',
                    'cols' => $m->cols,
                    'rows' => $m->rows,
                ],
                'decode' => static fn (array $e): WindowSizeMsg => new WindowSizeMsg(
                    cols: Scalars::int($e['cols'] ?? 0, 'WindowSizeMsg cols'),
                    rows: Scalars::int($e['rows'] ?? 0, 'WindowSizeMsg rows'),
                ),
            ],
            FocusGainedMsg::class => [
                'encode' => static fn (FocusGainedMsg $m): array => ['@type' => 'FocusGainedMsg'],
                'decode' => static fn (array $e): FocusGainedMsg => new FocusGainedMsg(),
            ],
            FocusLostMsg::class => [
                'encode' => static fn (FocusLostMsg $m): array => ['@type' => 'FocusLostMsg'],
                'decode' => static fn (array $e): FocusLostMsg => new FocusLostMsg(),
            ],
            BlurMsg::class => [
                'encode' => static fn (BlurMsg $m): array => ['@type' => 'BlurMsg'],
                'decode' => static fn (array $e): BlurMsg => new BlurMsg(),
            ],
            PasteStartMsg::class => [
                'encode' => static fn (PasteStartMsg $m): array => ['@type' => 'PasteStartMsg'],
                'decode' => static fn (array $e): PasteStartMsg => new PasteStartMsg(),
            ],
            PasteEndMsg::class => [
                'encode' => static fn (PasteEndMsg $m): array => ['@type' => 'PasteEndMsg'],
                'decode' => static fn (array $e): PasteEndMsg => new PasteEndMsg(),
            ],
            PasteMsg::class => [
                'encode' => static fn (PasteMsg $m): array => [
                    '@type' => 'PasteMsg',
                    'content' => $m->content,
                ],
                'decode' => static fn (array $e): PasteMsg => new PasteMsg(
                    content: Scalars::string($e['content'] ?? '', 'PasteMsg content'),
                ),
            ],
            BackgroundColorMsg::class => [
                'encode' => static fn (BackgroundColorMsg $m): array => [
                    '@type' => 'BackgroundColorMsg',
                    'r' => $m->r, 'g' => $m->g, 'b' => $m->b,
                ],
                'decode' => static fn (array $e): BackgroundColorMsg => new BackgroundColorMsg(
                    r: Scalars::int($e['r'] ?? 0, 'BackgroundColorMsg r'),
                    g: Scalars::int($e['g'] ?? 0, 'BackgroundColorMsg g'),
                    b: Scalars::int($e['b'] ?? 0, 'BackgroundColorMsg b'),
                ),
            ],
            ForegroundColorMsg::class => [
                'encode' => static fn (ForegroundColorMsg $m): array => [
                    '@type' => 'ForegroundColorMsg',
                    'r' => $m->r, 'g' => $m->g, 'b' => $m->b,
                ],
                'decode' => static fn (array $e): ForegroundColorMsg => new ForegroundColorMsg(
                    r: Scalars::int($e['r'] ?? 0, 'ForegroundColorMsg r'),
                    g: Scalars::int($e['g'] ?? 0, 'ForegroundColorMsg g'),
                    b: Scalars::int($e['b'] ?? 0, 'ForegroundColorMsg b'),
                ),
            ],
            CursorPositionMsg::class => [
                'encode' => static fn (CursorPositionMsg $m): array => [
                    '@type' => 'CursorPositionMsg',
                    'row' => $m->row, 'col' => $m->col,
                ],
                'decode' => static fn (array $e): CursorPositionMsg => new CursorPositionMsg(
                    row: Scalars::int($e['row'] ?? 0, 'CursorPositionMsg row'),
                    col: Scalars::int($e['col'] ?? 0, 'CursorPositionMsg col'),
                ),
            ],
            FocusInMsg::class => [
                'encode' => static fn (FocusInMsg $m): array => ['@type' => 'FocusInMsg'],
                'decode' => static fn (array $e): FocusInMsg => new FocusInMsg(),
            ],
            FocusOutMsg::class => [
                'encode' => static fn (FocusOutMsg $m): array => ['@type' => 'FocusOutMsg'],
                'decode' => static fn (array $e): FocusOutMsg => new FocusOutMsg(),
            ],
        ];
    }

    public function canEncode(Msg $msg): bool
    {
        return isset($this->handlers[$msg::class]);
    }

    public function canDecode(array $envelope): bool
    {
        $tag = $envelope['@type'] ?? null;
        return is_string($tag) && self::classForTag($tag) !== null;
    }

    public function encode(Msg $msg): array
    {
        $class = $msg::class;
        if (!isset($this->handlers[$class])) {
            throw new \LogicException("BuiltinSerializer cannot encode {$class}");
        }
        /** @var \Closure(Msg): array<string, mixed> $encoder */
        $encoder = $this->handlers[$class]['encode'];
        return $encoder($msg);
    }

    public function decode(array $envelope): Msg
    {
        $tag = Scalars::string($envelope['@type'] ?? '', 'BuiltinSerializer @type');
        $class = self::classForTag($tag);
        if ($class === null || !isset($this->handlers[$class])) {
            throw new \LogicException("BuiltinSerializer cannot decode '{$tag}'");
        }
        /** @var \Closure(array<string, mixed>): Msg $decoder */
        $decoder = $this->handlers[$class]['decode'];
        return $decoder($envelope);
    }

    /**
     * The set of `@type` tags this serializer handles.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        return [
            'KeyMsg',
            'MouseClickMsg', 'MouseMotionMsg', 'MouseWheelMsg', 'MouseReleaseMsg',
            'WindowSizeMsg',
            'FocusGainedMsg', 'FocusLostMsg', 'BlurMsg',
            'FocusInMsg', 'FocusOutMsg',
            'PasteStartMsg', 'PasteEndMsg', 'PasteMsg',
            'BackgroundColorMsg', 'ForegroundColorMsg',
            'CursorPositionMsg',
        ];
    }

    /** @return class-string|null */
    private static function classForTag(string $tag): ?string
    {
        return match ($tag) {
            'KeyMsg' => KeyMsg::class,
            'MouseClickMsg' => MouseClickMsg::class,
            'MouseMotionMsg' => MouseMotionMsg::class,
            'MouseWheelMsg' => MouseWheelMsg::class,
            'MouseReleaseMsg' => MouseReleaseMsg::class,
            'WindowSizeMsg' => WindowSizeMsg::class,
            'FocusGainedMsg' => FocusGainedMsg::class,
            'FocusLostMsg' => FocusLostMsg::class,
            'BlurMsg' => BlurMsg::class,
            'FocusInMsg' => FocusInMsg::class,
            'FocusOutMsg' => FocusOutMsg::class,
            'PasteStartMsg' => PasteStartMsg::class,
            'PasteEndMsg' => PasteEndMsg::class,
            'PasteMsg' => PasteMsg::class,
            'BackgroundColorMsg' => BackgroundColorMsg::class,
            'ForegroundColorMsg' => ForegroundColorMsg::class,
            'CursorPositionMsg' => CursorPositionMsg::class,
            default => null,
        };
    }

    /**
     * @param class-string $class
     * @return array{encode: \Closure, decode: \Closure}
     */
    private static function mouseHandler(string $class, string $tag): array
    {
        return [
            'encode' => static fn (MouseClickMsg|MouseMotionMsg|MouseWheelMsg|MouseReleaseMsg $m): array => [
                '@type' => $tag,
                'x' => $m->x,
                'y' => $m->y,
                'button' => $m->button->value,
                'action' => $m->action->value,
                'shift' => $m->shift,
                'alt' => $m->alt,
                'ctrl' => $m->ctrl,
            ],
            'decode' => static fn (array $e) => new $class(
                x: Scalars::int($e['x'] ?? 0, "{$tag} x"),
                y: Scalars::int($e['y'] ?? 0, "{$tag} y"),
                button: MouseButton::from(Scalars::string($e['button'] ?? '', "{$tag} button")),
                action: MouseAction::from(Scalars::string($e['action'] ?? '', "{$tag} action")),
                shift: (bool) ($e['shift'] ?? false),
                alt: (bool) ($e['alt'] ?? false),
                ctrl: (bool) ($e['ctrl'] ?? false),
            ),
        ];
    }
}
