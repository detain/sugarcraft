<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

use SugarCraft\Dash\Module\LegacyModule;

/**
 * Wraps an external binary as a Module.
 *
 * Spawns the binary via proc_open, communicates using line-delimited JSON
 * over stdin/stdout, and schedules periodic updates if Interval > 0.
 *
 * Mirrors the lattice ExternalModule pattern.
 *
 * Uses the legacy array-state update pattern kept for backwards compat.
 */
final class ExternalModule implements LegacyModule
{
    private array $state = [];
    private int $interval = 0;
    private $process = null;
    private bool $running = false;

    public function __construct(
        private readonly string $name,
        private readonly string $command,
        private readonly array $args = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function init(): array
    {
        $this->startProcess();
        $this->sendRequest(Request::init());
        $response = $this->readResponse();

        if ($response->type !== 'init') {
            throw new \RuntimeException("Expected init response, got: {$response->type}");
        }

        $this->interval = $response->data['interval'] ?? 0;

        return [
            'name' => $response->data['name'] ?? $this->name,
            'minSize' => $response->data['minSize'] ?? [30, 4],
            'interval' => $this->interval,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $state): array
    {
        if (!$this->running) {
            return $state;
        }

        $this->sendRequest(Request::update($state));
        $response = $this->readResponse();

        if ($response->type === 'update') {
            return $response->data['state'] ?? $state;
        }

        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function view(array $state, int $width, int $height): string
    {
        if (!$this->running) {
            return '';
        }

        $this->sendRequest(Request::view($width, $height, $state));
        $response = $this->readResponse();

        if ($response->type === 'view') {
            return $response->data['content'] ?? '';
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function minSize(): array
    {
        return [30, 4];
    }

    /**
     * Start the external process.
     */
    private function startProcess(): void
    {
        $cmd = array_merge([$this->command], $this->args);

        $this->process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        $this->running = true;
    }

    /**
     * Send a request to the plugin.
     */
    private function sendRequest(Request $request): void
    {
        if (!$this->running || $this->process === null) {
            return;
        }

        $pipes = proc_get_status($this->process)['pipes'];
        fwrite($pipes[0], $request->toJson() . "\n");
        fflush($pipes[0]);
    }

    /**
     * Read a response from the plugin.
     */
    private function readResponse(): Response
    {
        if (!$this->running || $this->process === null) {
            return Response::error('Process not running');
        }

        $pipes = proc_get_status($this->process)['pipes'];
        $line = fgets($pipes[1]);

        if ($line === false) {
            $this->running = false;
            return Response::error('EOF from process');
        }

        return Response::fromJson(trim($line));
    }

    /**
     * Destructor to clean up the process.
     */
    public function __destruct()
    {
        if ($this->process !== null && is_resource($this->process)) {
            $this->running = false;
            proc_close($this->process);
        }
    }
}
