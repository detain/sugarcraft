<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\Ipmi\Ipmi;
use SugarCraft\Top\Collect\Ipmi\IpmiReader;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Ipmi\IpmiData;
use SugarCraft\Top\Panel\Ipmi\IpmiView;
use SugarCraft\Top\Source\Fake\FakeIpmi;
use SugarCraft\Top\View\Region;

/**
 * The `ipmi` box: the machine's BMC — power draw, fans, temperatures,
 * voltages, power supplies, chassis faults and the event log — read with
 * ipmitool (no btop counterpart; candy-top's own box).
 *
 * Data: {@see collect()} asks the shared reader for a round. The live
 * {@see Ipmi} reader runs ipmitool on the loop, one child at a time: while
 * a round is in flight collect() returns null (the in-flight round
 * answers its own Cmd); otherwise a settled round answers at once and a
 * pending one through an AsyncCmd. The expensive reads run on the slow
 * cadence `ipmi_update_ms` (default {@see SLOW_MS}), the power reading
 * every data tick. Like every box, it is sampled only while shown
 * (#1858) — a hidden ipmi box costs nothing.
 *
 * Every round is merged into {@see IpmiData}, which keeps what a partial
 * or failed round did not read (#1008).
 */
final class IpmiPanel implements Panel
{
    /** Default ms between the expensive reads (SDR values, chassis, SEL). */
    public const SLOW_MS = 10000;

    private function __construct(
        private readonly ?IpmiReader $reader,
        private readonly IpmiData $data,
    ) {
    }

    public static function new(?IpmiReader $reader): self
    {
        return new self($reader, IpmiData::new());
    }

    /** The roster entry: the live reader, or the ASUS/AMI capture under `--fake`. */
    public static function standard(bool $fake = false): self
    {
        return self::new($fake ? FakeIpmi::demo() : Ipmi::new());
    }

    /** `ipmi_update_ms` when the schema has it, else {@see SLOW_MS}. */
    public static function slowMs(Config $config): int
    {
        return $config->has('ipmi_update_ms') ? max(1000, $config->int('ipmi_update_ms')) : self::SLOW_MS;
    }

    /** Whether FRU serials may be drawn (`ipmi_show_serials`, off by default). */
    public static function showSerials(Config $config): bool
    {
        return $config->has('ipmi_show_serials') && $config->bool('ipmi_show_serials');
    }

    public function box(): string
    {
        return 'ipmi';
    }

    public function data(): IpmiData
    {
        return $this->data;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $reader = $this->reader;
        if ($reader === null) {
            return null;
        }
        $slow = self::slowMs($context->config);

        return static function () use ($reader, $slow): ?Msg {
            $round = $reader->poll($slow);
            if ($round === null) {
                return null; // a round is in flight; it answers its own Cmd
            }
            [$settled, $snapshot] = Settled::peek($round);
            if ($settled) {
                return new SampledMsg('ipmi', $snapshot, $reader);
            }

            return Cmd::promise(static fn () => $round->then(static fn (IpmiSnapshot $s): Msg => new SampledMsg('ipmi', $s, $reader)))();
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg && $msg->box === 'ipmi' && $msg->snapshot instanceof IpmiSnapshot) {
            $width = $context->box?->width ?? 0;
            $cap = $width > 0 ? max(IpmiData::HISTORY, 2 * $width) : IpmiData::HISTORY;
            $reader = $msg->next instanceof IpmiReader ? $msg->next : $this->reader;

            return new PanelResult(new self($reader, $this->data->merged($msg->snapshot, $cap)));
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        IpmiView::paint($region, $frame, $this->data);
    }
}
