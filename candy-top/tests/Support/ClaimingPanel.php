<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\View\Region;

/**
 * Test double for the input-precedence and config seams: claims the listed
 * key names (only while the `$claimWhen` bool option is on, when given),
 * reports modal while the `$modalWhen` bool option is on, records every Msg
 * it receives with the context it was handed, and answers a key listed in
 * `$sets` with a SYNCHRONOUS config write ({@see PanelResult::$set}, the
 * shape of btop's proc `e` / net `a` toggles) or one listed in `$emits` with
 * a Cmd emitting that SetOptionMsg (the asynchronous path).
 */
final class ClaimingPanel implements Panel
{
    /**
     * @param list<string>                                                   $claims   key names ({@see KeyMsg::string()}) to capture
     * @param list<Msg>                                                      $seen
     * @param array<string, \Closure(Config): SetOptionMsg>                  $emits    key name => async option change built from the current config
     * @param list<Config>                                                   $configs  the Config passed alongside each entry of $seen
     * @param array<string, \Closure(Config): array<string, bool|int|string>> $sets     key name => synchronous writes built from the current config
     * @param list<PanelContext>                                             $contexts the context passed alongside each entry of $seen
     */
    public function __construct(
        private readonly string $box,
        private readonly array $claims,
        public readonly array $seen = [],
        private readonly ?string $claimWhen = null,
        private readonly array $emits = [],
        public readonly array $configs = [],
        private readonly array $sets = [],
        private readonly ?string $modalWhen = null,
        public readonly array $contexts = [],
    ) {
    }

    public function box(): string
    {
        return $this->box;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        return null;
    }

    public function modal(PanelContext $context): bool
    {
        return $this->modalWhen !== null && $context->config->bool($this->modalWhen);
    }

    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        if ($this->claimWhen !== null && !$context->config->bool($this->claimWhen)) {
            return false;
        }

        return in_array($key->string(), $this->claims, true);
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        $config = $context->config;
        $next = new self(
            $this->box,
            $this->claims,
            [...$this->seen, $msg],
            $this->claimWhen,
            $this->emits,
            [...$this->configs, $config],
            $this->sets,
            $this->modalWhen,
            [...$this->contexts, $context],
        );
        $name = $msg instanceof KeyMsg ? $msg->string() : null;
        $emit = $name === null ? null : ($this->emits[$name] ?? null);
        $set = $name === null ? null : ($this->sets[$name] ?? null);

        return new PanelResult(
            $next,
            $emit === null ? null : static fn (): Msg => $emit($config),
            $set === null ? [] : $set($config),
        );
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
    }
}
