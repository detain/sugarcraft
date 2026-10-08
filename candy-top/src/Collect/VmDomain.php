<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The static facts of one running libvirt/KVM guest, as the VM dashboard
 * needs them (candy-top's own; btop has no VM view).
 *
 * Two sources, best first:
 *  - {@see fromXml()}: libvirt's live status XML,
 *    `/run/libvirt/qemu/<name>.xml` (root-only, 0600): the domain id,
 *    name, `<vcpu>` (its `current=` attribute when vCPUs are hot-plugged),
 *    `<memory>` / `<currentMemory>` in their `unit=`, the `<target dev=>`
 *    of every `<interface>` (the host-side tap, `vnetN`) and the qemu pid
 *    (`<domstatus pid=>`);
 *  - {@see fromCmdline()}: what the emulator's own command line says
 *    (`-name guest=`, `-smp`, `-m` — {@see Vm::fromCmdline()}) plus the
 *    guest NICs' MAC addresses, readable without root. The taps are then
 *    found by MAC ({@see VmFleet}): libvirt gives a tap its guest's MAC
 *    with the first octet set to 0xfe (0xfa if it was 0xfe), a macvtap the
 *    guest MAC itself.
 *
 * `vcpus` / `memBytes` are UNMEASURED_INT when unknown; `interfaces` is
 * null when the taps could not be identified (net shows n/a), [] when the
 * guest has no NIC.
 */
final class VmDomain
{
    /**
     * @param ?list<string> $interfaces host-side tap names
     * @param list<string>  $macs       guest NIC MACs, lower-case
     */
    public function __construct(
        public readonly string $name,
        public readonly int $domainId = Sentinel::UNMEASURED_INT,
        public readonly int $vcpus = Sentinel::UNMEASURED_INT,
        public readonly int $memBytes = Sentinel::UNMEASURED_INT,
        public readonly int $currentMemBytes = Sentinel::UNMEASURED_INT,
        public readonly ?array $interfaces = null,
        public readonly array $macs = [],
        public readonly int $pid = 0,
        public readonly bool $fromXml = false,
    ) {
    }

    /**
     * The facts in a libvirt status (`<domstatus>`) or plain `<domain>`
     * XML; null when it holds no `<domain>` with a `<name>`. A regex pass
     * over the few elements needed, not a DOM parse: these files are
     * libvirt's own output, and ext-simplexml is not a requirement.
     */
    public static function fromXml(string $xml): ?self
    {
        $open = preg_match('/<domain\b([^>]*)>/', $xml, $m, \PREG_OFFSET_CAPTURE);
        if ($open !== 1) {
            return null;
        }
        $body = substr($xml, (int) $m[0][1]);
        if (preg_match('#<name>([^<]*)</name>#', $body, $name) !== 1 || trim($name[1]) === '') {
            return null;
        }
        $id = preg_match('/\bid=[\'"](\d{1,9})[\'"]/', $m[1][0], $idm) === 1 ? (int) $idm[1] : Sentinel::UNMEASURED_INT;
        $pid = preg_match('/<domstatus\b[^>]*\bpid=[\'"](\d{1,9})[\'"]/', $xml, $pm) === 1 ? (int) $pm[1] : 0;

        $vcpus = Sentinel::UNMEASURED_INT;
        if (preg_match('#<vcpu\b([^>]*)>\s*(\d{1,6})\s*</vcpu>#', $body, $vm) === 1) {
            $vcpus = (int) $vm[2];
            if (preg_match('/\bcurrent=[\'"](\d{1,6})[\'"]/', $vm[1], $cm) === 1 && (int) $cm[1] > 0) {
                $vcpus = (int) $cm[1];
            }
        }
        $interfaces = [];
        $macs = [];
        if (preg_match_all('#<interface\b.*?</interface>#s', $body, $ifs) > 0) {
            foreach ($ifs[0] as $if) {
                if (preg_match('/<target\b[^>]*\bdev=[\'"]([^\'"]{1,64})[\'"]/', $if, $t) === 1 && self::safeIface($t[1])) {
                    $interfaces[] = $t[1];
                }
                if (preg_match('/<mac\b[^>]*\baddress=[\'"]([0-9A-Fa-f:]{17})[\'"]/', $if, $mac) === 1) {
                    $macs[] = strtolower($mac[1]);
                }
            }
        }

        return new self(
            html_entity_decode(trim($name[1]), \ENT_QUOTES | \ENT_XML1, 'UTF-8'),
            $id,
            $vcpus > 0 ? $vcpus : Sentinel::UNMEASURED_INT,
            self::memory($body, 'memory'),
            self::memory($body, 'currentMemory'),
            $interfaces,
            $macs,
            $pid,
            true,
        );
    }

    /**
     * The facts the emulator's command line gives (no root needed), named
     * by guest= else `$scopeName`; the taps stay unknown (null) until
     * matched by MAC.
     */
    public static function fromCmdline(?string $cmdline, string $scopeName, int $domainId, int $pid): self
    {
        $info = Vm::fromCmdline($cmdline);
        $name = $info !== null && $info->guestName !== '' ? $info->guestName : $scopeName;

        return new self(
            $name,
            $domainId,
            $info->vcpus ?? Sentinel::UNMEASURED_INT,
            $info->memBytes ?? Sentinel::UNMEASURED_INT,
            Sentinel::UNMEASURED_INT,
            null,
            self::macs($cmdline ?? ''),
            $pid,
        );
    }

    /**
     * Guest NIC MACs in a qemu command line — `-device
     * virtio-net-pci,...,mac=52:54:00:..` or the JSON form
     * `{"driver":"virtio-net-pci",...,"mac":"52:54:00:.."}`.
     *
     * @return list<string>
     */
    public static function macs(string $cmdline): array
    {
        if (preg_match_all('/(?:\bmac=|"mac"\s*:\s*")([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $cmdline, $m) < 1) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** Copy with the taps identified (by MAC). */
    public function withInterfaces(array $interfaces): self
    {
        return new self($this->name, $this->domainId, $this->vcpus, $this->memBytes, $this->currentMemBytes, array_values($interfaces), $this->macs, $this->pid, $this->fromXml);
    }

    /** A sysfs-safe interface name: what the kernel allows, no path tricks. */
    public static function safeIface(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.:@-]{1,15}$/', $name) === 1 && $name !== '.' && $name !== '..';
    }

    /** `<memory unit='KiB'>N</memory>` (any libvirt unit) in bytes, UNMEASURED_INT when absent. */
    private static function memory(string $body, string $element): int
    {
        if (preg_match('#<' . $element . '\b([^>]*)>\s*(\d{1,20})\s*</' . $element . '>#', $body, $m) !== 1) {
            return Sentinel::UNMEASURED_INT;
        }
        $unit = preg_match('/\bunit=[\'"]([A-Za-z]+)[\'"]/', $m[1], $u) === 1 ? $u[1] : 'KiB';
        $mult = match ($unit) {
            'b', 'bytes' => 1,
            'KB' => 1000,
            'k', 'KiB' => 1024,
            'MB' => 1000 ** 2,
            'M', 'MiB' => 1024 ** 2,
            'GB' => 1000 ** 3,
            'G', 'GiB' => 1024 ** 3,
            'TB' => 1000 ** 4,
            'T', 'TiB' => 1024 ** 4,
            default => 0,
        };
        $bytes = (float) $m[2] * $mult;

        return $mult > 0 && $bytes >= 1 && $bytes < 9.2e18 ? (int) $bytes : Sentinel::UNMEASURED_INT;
    }
}
