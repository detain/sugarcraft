<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * Gives every tool call of one turn an id no other call of that turn shares
 * (step 0.2).
 *
 * WHY: the text-fallback parsers number their calls from zero on every
 * response — `dsml_call_0`, `minimax_xml_call_0` — and some servers send an
 * empty id or restart their own counter per response. Over a multi-step turn
 * that hands the model two different calls named `dsml_call_0`, and the
 * transcript then pairs each result with whichever call a provider's
 * converter finds first. Anthropic and Bedrock reject the request outright.
 *
 * WHAT IS REWRITTEN, to `tc_<nonce>_<seq>`:
 *  - an empty id;
 *  - a parser's positional id (`dsml_call_N`, `minimax_xml_call_N`), which is
 *    never unique beyond its own response;
 *  - an id outside `^[A-Za-z0-9_-]{1,64}$`, the shape Anthropic and Bedrock
 *    accept;
 *  - an id this turn has already seen ({@see observe()} or an earlier
 *    {@see assign()}).
 *
 * A real server id that passes all four is kept verbatim, so a provider that
 * correlates on its own ids still can.
 *
 * The nonce is random per allocator, and one allocator lives for one
 * {@see \SugarCraft\Crush\Runtime}, which is one turn (and one delegated Task
 * run, in its own fork). A random nonce needs no state threaded across the
 * fork to stay collision-free, which a session-wide sequence would.
 *
 * Mutable on purpose: it is a per-turn ledger, not a value object.
 */
final class ToolCallIdAllocator
{
    /** Positional ids the in-tree text-fallback parsers mint per response. */
    private const POSITIONAL_ID = '/^(?:dsml|minimax_xml)_call_\d+$/';

    /** The id shape every provider family accepts (Anthropic/Bedrock are the strictest). */
    private const PORTABLE_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /** @var array<string, true> */
    private array $seen = [];

    private int $seq = 0;

    public function __construct(private readonly string $nonce)
    {
        if (preg_match('/^[A-Za-z0-9]{1,16}$/', $nonce) !== 1) {
            throw new \InvalidArgumentException('A tool-call id nonce must be 1-16 ASCII letters or digits.');
        }
    }

    public static function new(): self
    {
        return new self(bin2hex(random_bytes(4)));
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /**
     * Record ids already on the conversation (a resumed transcript's calls),
     * so a new call can never reuse one.
     *
     * @param iterable<string> $ids
     */
    public function observe(iterable $ids): void
    {
        foreach ($ids as $id) {
            if ($id !== '') {
                $this->seen[$id] = true;
            }
        }
    }

    /**
     * The same calls, in the same order, each carrying an id unique within
     * this turn.
     *
     * Anything that is not a {@see ToolCall} passes through untouched: the
     * converters accept pre-shaped arrays, and renaming one here could not
     * reach the result that answers it.
     *
     * @param array<array-key, mixed> $calls
     * @return list<mixed>
     */
    public function assign(array $calls): array
    {
        $out = [];
        foreach ($calls as $call) {
            if (!$call instanceof ToolCall) {
                $out[] = $call;

                continue;
            }
            $id = $call->id();
            if ($this->mustRewrite($id)) {
                $id = $this->mint();
            }
            $this->seen[$id] = true;
            $out[] = $id === $call->id() ? $call : $call->withId($id);
        }

        return $out;
    }

    private function mustRewrite(string $id): bool
    {
        return $id === ''
            || isset($this->seen[$id])
            || preg_match(self::POSITIONAL_ID, $id) === 1
            || preg_match(self::PORTABLE_ID, $id) !== 1;
    }

    private function mint(): string
    {
        do {
            $id = 'tc_' . $this->nonce . '_' . $this->seq++;
        } while (isset($this->seen[$id]));

        return $id;
    }
}
