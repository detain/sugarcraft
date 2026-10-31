<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Pty\PtyException;

/**
 * The ConPTY "slave": not a device, the attachment recipe.
 *
 * A POSIX slave is a tty path you can reopen from a child; a pseudo console
 * is a process attribute, so this adapter's job is to produce the
 * STARTUPINFOEXW attribute list and the job object that a CreateProcessW
 * call needs, hand them to {@see ConPtyApi::createProcess()}, and wrap the
 * result. {@see path()} answers with a label rather than something
 * openable — the honest shape of what ConPTY offers.
 *
 * Mirrors portable-pty's ConPty::spawn_command and the
 * STARTUPINFOEXW procedure in the OpenConsole source
 * (@see https://learn.microsoft.com/en-us/windows/console/creating-a-pseudoconsole-session).
 */
final class ConPtySlavePty implements SlavePty
{
    public function __construct(private readonly ConPtyMasterPty $master)
    {
    }

    /**
     * "conpty:<HPCON handle>" — a stable identity, not a path.
     *
     * A caller that treats it as openable (the way
     * {@see \SugarCraft\Pty\Posix\PosixSlavePty::path()} invites) is
     * already outside the contract: the contract documents it as an
     * identity string.
     */
    public function path(): string
    {
        return 'conpty:' . (string) $this->master->pseudoConsole();
    }

    /**
     * Attach a child to the pseudo console.
     *
     * Geometry follows {@see \SugarCraft\Pty\Posix\PosixSlavePty::spawn()}'s
     * widened rule: a null $cols/$rows keeps the pair's current size, any
     * explicit value resizes the console before the child is created, so
     * the first frame the program draws is already the right shape.
     *
     * $controllingTerminal cannot be honoured — ConPTY has no ctty — and it
     * is refused loudly rather than silently dropped, because a caller who
     * asked for Ctrl+C semantics on the wrong backend needs to know NOW.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env null inherits the parent env;
     *     a map REPLACES it wholesale (same law as the POSIX side)
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        ?int $cols = null,
        ?int $rows = null,
        bool $controllingTerminal = false,
    ): Child {
        if ($cmd === []) {
            throw new \InvalidArgumentException('spawn requires at least one argv element (the program)');
        }

        if ($controllingTerminal) {
            throw new PtyException(
                'ConPTY has no controlling terminal; Ctrl+C is delivered as console input by the pseudo '
                . 'console itself, so controllingTerminal cannot be honoured (capabilities() says so)',
            );
        }

        if ($this->master->isClosed()) {
            throw new PtyException('cannot spawn: the ConPTY master is closed');
        }

        if ($cols !== null || $rows !== null) {
            $size = $this->master->size();
            $this->master->resize($cols ?? $size['cols'], $rows ?? $size['rows']);
        }

        $api = $this->master->api();

        // The kill-on-close job goes first: every later step can leave a
        // created-but-unattached process behind, and an assigned process
        // dies with the job. A failure past CreateProcess tears the job
        // down; a success transfers job ownership to the child adapter.
        $job = $api->createJob();

        try {
            $attributeList = $api->initializeAttributeList(1);

            try {
                $api->setPseudoConsoleAttribute($attributeList, $this->master->pseudoConsole());

                $result = $api->createProcess(
                    '',
                    CommandLine::fromArgv($cmd),
                    $attributeList,
                    $env ?? [],
                    '',
                    ConPtyApi::CREATE_NEW_PROCESS_GROUP | ConPtyApi::CREATE_NO_WINDOW,
                    $job,
                );
            } finally {
                $api->deleteAttributeList($attributeList);
            }
        } catch (\Throwable $e) {
            $api->closeJob($job);

            throw $e;
        }

        return new ConPtyChild($api, $result['process'], $result['processId'], $job);
    }
}
