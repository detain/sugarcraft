<?php

declare(strict_types=1);

namespace CandyCore\Serve;

/**
 * Access control for repositories and operations.
 *
 * Port of charmbracelet/soft-serve AccessControl.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class AccessControl
{
    // Permission levels
    public const ACCESS_NONE  = 0;
    public const ACCESS_READ  = 1;
    public const ACCESS_WRITE = 2;
    public const ACCESS_ADMIN = 3;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(self $ac): void
    {
        self::$instance = $ac;
    }

    /**
     * Check if a user can read a repo.
     */
    public function canRead(?User $user, Repo $repo): bool
    {
        if ($repo->isPublic && !$repo->isPrivate()) return true;
        if ($user === null) return false;
        if ($user->isAdmin) return true;
        if (!$repo->isPrivate()) return true;
        return $repo->isCollaborator($user->username);
    }

    /**
     * Check if a user can push to a repo.
     */
    public function canWrite(?User $user, Repo $repo): bool
    {
        if ($user === null) return false;
        if ($user->isAdmin) return true;
        if ($repo->allowPush && !$repo->isPrivate()) return true;
        return $repo->isCollaborator($user->username);
    }

    /**
     * Check if a user can administer a repo (change settings, add collaborators).
     */
    public function canAdmin(?User $user, Repo $repo): bool
    {
        if ($user === null) return false;
        if ($user->isAdmin) return true;
        return false;
    }

    /**
     * Check if a user can create repos.
     */
    public function canCreateRepos(?User $user): bool
    {
        if ($user === null) return false;
        return $user->isAdmin;
    }

    /**
     * Check if anonymous access (public key) is allowed for reading.
     */
    public function allowAnonymousRead(): bool
    {
        return true;
    }

    /**
     * Resolve the permission level name.
     */
    public static function permissionName(int $level): string
    {
        return match ($level) {
            self::ACCESS_NONE  => 'none',
            self::ACCESS_READ  => 'read',
            self::ACCESS_WRITE => 'write',
            self::ACCESS_ADMIN => 'admin',
            default => "unknown({$level})",
        };
    }
}
