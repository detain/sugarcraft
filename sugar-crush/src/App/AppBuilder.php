<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tui\Pane;

/**
 * Fluent builder for App.
 *
 * @method self withProvider(ProviderInterface $provider)
 * @method self withModel(string $model)
 * @method self withMessages(array $messages)
 * @method self withTools(array $tools)
 * @method self withPane(Pane $pane)
 * @method self withError(?string $error)
 * @method self withStatus(?string $status)
 * @method self withSessionId(?string $sessionId)
 * @method self withContextFiles(array $contextFiles)
 * @method self withEnabledSkills(array $enabledSkills)
 * @method self withActiveHooks(array $activeHooks)
 */
final class AppBuilder
{
    private ?ProviderInterface $provider = null;
    private string $model = 'claude-sonnet-4-6';
    private array $messages = [];
    private array $tools = [];
    private Pane $pane = Pane::Chat;
    private ?string $error = null;
    private ?string $status = null;
    private ?string $sessionId = null;
    private array $contextFiles = [];
    private array $enabledSkills = [];
    private array $activeHooks = [];
    private ?SkillRegistry $availableSkills = null;
    private ?string $projectRoot = null;
    private array $disabledSkills = [];

    public function withProvider(ProviderInterface $provider): self
    {
        $clone = clone $this;
        $clone->provider = $provider;
        return $clone;
    }

    public function withModel(string $model): self
    {
        $clone = clone $this;
        $clone->model = $model;
        return $clone;
    }

    public function withMessages(array $messages): self
    {
        $clone = clone $this;
        $clone->messages = $messages;
        return $clone;
    }

    public function withTools(array $tools): self
    {
        $clone = clone $this;
        $clone->tools = $tools;
        return $clone;
    }

    public function withPane(Pane $pane): self
    {
        $clone = clone $this;
        $clone->pane = $pane;
        return $clone;
    }

    public function withError(?string $error): self
    {
        $clone = clone $this;
        $clone->error = $error;
        return $clone;
    }

    public function withStatus(?string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }

    public function withSessionId(?string $sessionId): self
    {
        $clone = clone $this;
        $clone->sessionId = $sessionId;
        return $clone;
    }

    public function withContextFiles(array $contextFiles): self
    {
        $clone = clone $this;
        $clone->contextFiles = $contextFiles;
        return $clone;
    }

    public function withEnabledSkills(array $enabledSkills): self
    {
        $clone = clone $this;
        $clone->enabledSkills = $enabledSkills;
        return $clone;
    }

    public function withActiveHooks(array $activeHooks): self
    {
        $clone = clone $this;
        $clone->activeHooks = $activeHooks;
        return $clone;
    }

    /**
     * Hand the builder an already-populated skill registry.
     *
     * Takes precedence over {@see withProjectRoot()}: a caller that already
     * discovered skills once (the CLI does, for the engine backend) must be
     * able to pass THAT instance in rather than paying for a second
     * filesystem scan and ending up with two registries whose disabled sets
     * can drift apart.
     */
    public function withAvailableSkills(SkillRegistry $availableSkills): self
    {
        $clone = clone $this;
        $clone->availableSkills = $availableSkills;
        return $clone;
    }

    /**
     * Root the built App's skill discovery at $projectRoot.
     *
     * Without this (and without {@see withAvailableSkills()}) build() has no
     * directory to scan, so the App gets the empty default registry — which
     * is exactly the production defect crush_feat.md section 7 E1 reports:
     * every skill-driven code path (picker, findSkillsForTask(), fork
     * dispatch) reads App::$availableSkills, and nothing ever filled it.
     *
     * Also lands on {@see App::$root}, so a builder-constructed App reports
     * the same directory to the environment block and hook contexts that it
     * scanned for skills — a builder that rooted discovery here while the
     * prompt still said `getcwd()` would reintroduce exactly the split
     * crush_code.md Phase 0 item 6 describes.
     */
    public function withProjectRoot(string $projectRoot): self
    {
        $clone = clone $this;
        $clone->projectRoot = $projectRoot;
        return $clone;
    }

    /**
     * Names to mark disabled on the built registry (section 7 E1's
     * `disableFromConfig($config->disabledSkills)` step), applied after
     * discovery so a config entry can suppress a skill that was found on
     * disk.
     *
     * @param array<string> $disabledSkills
     */
    public function withDisabledSkills(array $disabledSkills): self
    {
        $clone = clone $this;
        $clone->disabledSkills = $disabledSkills;
        return $clone;
    }

    public function build(): App
    {
        if ($this->provider === null) {
            throw new \LogicException('provider is required');
        }

        // App's constructor is private; assemble through the public
        // factory + with*() chain so availableSkills gets its default
        // SkillRegistry and the immutable contract is preserved.
        return App::new($this->provider, $this->model)
            ->withAvailableSkills($this->skillRegistry())
            ->withMessages($this->messages)
            ->withTools($this->tools)
            ->withPane($this->pane)
            ->withError($this->error)
            ->withStatus($this->status)
            ->withSessionId($this->sessionId)
            ->withContextFiles($this->contextFiles)
            ->withEnabledSkills($this->enabledSkills)
            ->withActiveHooks($this->activeHooks)
            ->withRoot($this->projectRoot);
    }

    /**
     * Resolve the registry the built App sees: the injected one when given,
     * otherwise a fresh registry filled by SkillManager from $projectRoot.
     *
     * SkillManager is used even for an injected registry so the disabled-set
     * step goes through the same API in both cases.
     */
    private function skillRegistry(): SkillRegistry
    {
        $registry = $this->availableSkills ?? new SkillRegistry();
        $manager = new SkillManager(new SkillLoader(), $registry);

        // An injected registry is already loaded; re-running loadAll() on it
        // would re-register every skill and undo any caller-side filtering.
        if ($this->availableSkills === null && $this->projectRoot !== null) {
            $manager->loadAll($this->projectRoot);
        }

        $manager->disableFromConfig($this->disabledSkills);

        return $registry;
    }
}
