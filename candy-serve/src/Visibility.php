<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

/**
 * Repository visibility (plan item 7.8).
 *
 * Replaces the paired `bool $isPublic` + `bool $private` flags on
 * {@see Repo} with one explicit tri-state. Mirrors
 * charmbracelet/soft-serve repo access semantics: public repos are
 * readable by anyone, private repos only by collaborators/admins;
 * "collaborator-only" is the state the old bools reached with
 * `public=false, private=false` (not listed publicly, not readable
 * anonymously — behaviourally private reads, but push rules that key
 * off explicit privacy can distinguish it).
 */
enum Visibility: string
{
    case Public = 'public';
    case CollaboratorOnly = 'collaborator-only';
    case Private = 'private';

    /**
     * Map the legacy two-boolean model onto the enum. A set `private`
     * flag always wins, matching the old `isPrivate()` accessor.
     */
    public static function fromBools(bool $isPublic, bool $private): self
    {
        return match (true) {
            $private   => self::Private,
            $isPublic  => self::Public,
            default    => self::CollaboratorOnly,
        };
    }

    /** Anyone (including anonymous users) may read the repo. */
    public function isPublic(): bool
    {
        return $this === self::Public;
    }

    /** Explicitly private: collaborators/admins only. */
    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
