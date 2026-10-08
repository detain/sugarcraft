<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * What a KVM/QEMU guest process says about its VM (plan Wave U1b), carried
 * on a ContainerRef whose engine is Vm::ENGINE.
 *
 *  - `domainId`: libvirt's running-domain id from the cgroup scope
 *    (`machine-qemu\x2d<id>\x2d<name>.scope`), UNMEASURED_INT when the
 *    layout carries none (bare qemu, pre-1.3 libvirt names);
 *  - `scopeName`: the guest name as the cgroup scope spells it, raw and
 *    unescaped — libvirt sanitises it (hostname alphabet only) and
 *    truncates it to fit a 64-byte machine name, so it is a cross-check,
 *    not the display name;
 *  - `guestName`: `-name guest=<name>` from the cmdline, raw — the
 *    authoritative name;
 *  - `uuid`: `-uuid`, lower-cased, Sentinel::UNAVAILABLE when absent or
 *    malformed;
 *  - `vcpus`: boot vCPUs from `-smp` (the implied/`cpus=` value, else the
 *    topology product), UNMEASURED_INT when absent;
 *  - `memBytes`: guest RAM from `-m`, UNMEASURED_INT when absent.
 *
 * The two names are '' (not 'n/a') when unknown: they are raw identifiers,
 * and "n/a" is itself a valid `-name` for a bare qemu.
 */
final class VmInfo
{
    public function __construct(
        public readonly int $domainId = Sentinel::UNMEASURED_INT,
        public readonly string $scopeName = '',
        public readonly string $guestName = '',
        public readonly string $uuid = Sentinel::UNAVAILABLE,
        public readonly int $vcpus = Sentinel::UNMEASURED_INT,
        public readonly int $memBytes = Sentinel::UNMEASURED_INT,
    ) {
    }

    /** The raw name to identify the VM by: guest= when known, else the scope's. */
    public function name(): string
    {
        return $this->guestName !== '' ? $this->guestName : $this->scopeName;
    }

    /** True when the cgroup layout identified this as a libvirt domain. */
    public function libvirt(): bool
    {
        return $this->scopeName !== '';
    }

    /**
     * Whether the scope name is consistent with guest= — equal to it, or a
     * prefix of it or of libvirt's sanitised machine name (truncation).
     * Null when either side is unknown.
     */
    public function nameAgrees(): ?bool
    {
        if ($this->scopeName === '' || $this->guestName === '') {
            return null;
        }

        return str_starts_with($this->guestName, $this->scopeName)
            || str_starts_with(Vm::machineName($this->guestName), $this->scopeName);
    }
}
