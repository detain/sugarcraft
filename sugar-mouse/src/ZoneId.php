<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * A typed zone id: a namespace (what kind of thing the zone is — `toolcall`,
 * `tab`, `menuitem`) plus a key (which one — `42`, `settings`).
 *
 * The wire spelling is `namespace:key` (or the bare namespace when the key is
 * empty), which is ordinary {@see Mark} id material, so a typed id marks,
 * scans and strips exactly like a string id — the string API keeps working
 * untouched beside it. What the type adds is the reading side: instead of
 * `str_starts_with($zone->id, 'toolcall:')` and `(int) substr(...)` (where a
 * non-numeric suffix silently became 0), a consumer parses once
 * ({@see parse()}), routes on the namespace ({@see ZoneRouter}) and reads a
 * checked integer key ({@see intKey()}).
 *
 * Grammar: the namespace is a non-empty run of the id charset WITHOUT `:`
 * (letters, digits, `._-`); the key is any run of the id charset, `:`
 * included, so `cell:3:4` is namespace `cell`, key `3:4`. The whole wire id
 * must pass {@see Mark::isValidId()}.
 *
 * Original work (bubblezone ids are plain strings).
 */
final class ZoneId
{
    private function __construct(
        public readonly string $namespace,
        public readonly string $key,
    ) {
    }

    /**
     * Build a typed id.
     *
     * @throws \InvalidArgumentException when the namespace is empty or holds
     *         a byte outside `[A-Za-z0-9._-]`, or the wire id fails
     *         {@see Mark::isValidId()} (charset, or longer than
     *         {@see Mark::MAX_ID_BYTES})
     */
    public static function of(string $namespace, string|int $key = ''): self
    {
        $key = (string) $key;
        if (preg_match('/\A[A-Za-z0-9._-]+\z/', $namespace) !== 1) {
            throw new \InvalidArgumentException(
                Lang::t('zone_id.invalid_namespace', ['namespace' => Mark::escapeForMessage($namespace)])
            );
        }
        $id = new self($namespace, $key);
        $wire = $id->wire();
        if (Mark::isValidId($wire) === false) {
            throw new \InvalidArgumentException(
                Lang::t('zone_id.invalid', ['id' => Mark::escapeForMessage($wire)])
            );
        }
        return $id;
    }

    /**
     * Read a wire id back into its typed form, or null when it is not one a
     * {@see of()} call could have produced (invalid id material, an empty
     * namespace, or a trailing `:` with no key) — such a zone is simply not
     * routable, never an error.
     */
    public static function parse(string $wire): ?self
    {
        if (Mark::isValidId($wire) === false) {
            return null;
        }
        $colon = strpos($wire, ':');
        if ($colon === false) {
            return new self($wire, '');
        }
        if ($colon === 0 || $colon === strlen($wire) - 1) {
            return null;
        }
        return new self(substr($wire, 0, $colon), substr($wire, $colon + 1));
    }

    /** The id {@see Mark} writes and {@see Zone::$id} reads back. */
    public function wire(): string
    {
        return $this->key === '' ? $this->namespace : $this->namespace . ':' . $this->key;
    }

    /**
     * The key as an integer, or null when it is not a canonical decimal
     * integer that fits in an int (`7` and `-3` are; `07`, `7a`, `` and an
     * overflowing run are not). Replaces the `(int) substr(...)` idiom, which
     * turned every malformed suffix into row 0.
     */
    public function intKey(): ?int
    {
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $this->key) !== 1 || $this->key === '-0') {
            return null;
        }
        $int = (int) $this->key;
        return (string) $int === $this->key ? $int : null;
    }

    public function inNamespace(string $namespace): bool
    {
        return $this->namespace === $namespace;
    }

    public function equals(self $other): bool
    {
        return $this->namespace === $other->namespace && $this->key === $other->key;
    }

    /** Wrap $content in zone markup for this id ({@see Mark::zone()}). */
    public function mark(string $content): string
    {
        return Mark::zone($this->wire(), $content);
    }
}
