<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * `candy-mdview` entry point. Registers a single default command so users
 * can invoke `candy-mdview <file>` without a subcommand name.
 */
final class Application extends SymfonyApplication
{
    /** The composer-mode answer — the pre-phar literal, kept byte-true. */
    public const COMPOSER_FALLBACK = '0.1.0';

    /** Stamps no release tag was reachable for at build time. */
    public const WORKING_TREE_STAMP = 'dev-build';

    public function __construct()
    {
        parent::__construct('candy-mdview', self::versionString());
        $command = new RenderCommand();
        $this->add($command);
        $this->setDefaultCommand($command->getName(), true);
    }

    /**
     * The version Symfony's `--version` will print, phar-aware (the DST-1
     * lesson from candy-code): inside an archive the build manifest wins —
     * the hardcoded literal would otherwise freeze a number no release
     * process produced, while the manifest names the exact tag the artifact
     * was cut from. A bare `dev-build` stamp is working-tree noise, not a
     * version, and falls through. Outside an archive nothing is consulted
     * and the historical `0.1.0` answers byte-true (pinned by
     * ApplicationTest and by the precedence test here).
     */
    public static function versionString(): string
    {
        $archive = self::hostArchive();
        if ($archive !== '') {
            try {
                $metadata = (new \Phar($archive))->getMetadata();
            } catch (\Throwable) {
                $metadata = null;
            }
            if (is_array($metadata)) {
                $stamp = $metadata['version'] ?? null;
                if (
                    is_string($stamp)
                    && trim($stamp) !== ''
                    && trim($stamp) !== self::WORKING_TREE_STAMP
                ) {
                    return trim($stamp);
                }
            }
        }

        return self::COMPOSER_FALLBACK;
    }

    /**
     * The real-filesystem path of the phar archive hosting this install, or ''
     * when this code is not running from an archive. Three identities, first
     * answer wins (measured on PHP 8.3.6 while porting the candy-code DST-1
     * guard): Phar::running() names the archive for code executing inside it;
     * in the stub region running() answers '' and `__FILE__` names the
     * self-naming archive; a `phar://` prefix on this file's own `__FILE__`
     * covers shapes where a class was loaded from the archive but running()
     * stayed empty.
     */
    public static function hostArchive(): string
    {
        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        $self = __FILE__;
        if (str_starts_with($self, 'phar://')) {
            if (preg_match('#^phar://(.+\.phar)(?:/|$)#', $self, $m) === 1) {
                return $m[1];
            }

            return '';
        }

        return str_ends_with($self, '.phar') ? $self : '';
    }
}
