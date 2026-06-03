<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\EventLoop\LoopInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;

/**
 * Executes database queries in a background process, non-blocking.
 *
 * Uses proc_open + event loop read streams to avoid blocking the ReactPHP
 * event loop while waiting for query results.
 */
final class ProcessQueryExecutor
{
    private ?LoopInterface $loop;
    private ?\React\Promise\Deferred $currentDeferred = null;
    /** @var array<resource> */
    private array $pipes = [];
    private mixed $currentProc = null;
    private string $tmpFile = '';
    private string $stdoutBuffer = '';

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop ?? Loop::get();
    }

    /**
     * Execute a query in a background process, non-blocking.
     *
     * @return PromiseInterface<list<array<string,mixed>>> Rows as assoc arrays
     */
    public function query(string $dsn, string $username, string $password, string $query): PromiseInterface
    {
        $this->tmpFile = sys_get_temp_dir() . '/pqe_' . uniqid() . '.json';

        $childCode = sprintf(
            '<?php
            declare(strict_types=1);
            try {
                $pdo = new PDO(%s, %s, %s);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $pdo->query(%s);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                file_put_contents(%s, json_encode($result));
            } catch (Throwable $e) {
                file_put_contents(%s, json_encode(["error" => $e->getMessage()]));
            }
            ',
            var_export($dsn, true),
            var_export($username, true),
            var_export($password, true),
            var_export($query, true),
            var_export($this->tmpFile, true),
            var_export($this->tmpFile, true),
        );

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $tmpPhpFile = sys_get_temp_dir() . '/pqe_' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($tmpPhpFile, '<?php ' . $childCode . "\n");
        $proc = proc_open('php ' . escapeshellarg($tmpPhpFile), $spec, $this->pipes);
        register_shutdown_function(static fn () => @unlink($tmpPhpFile));

        if (!$proc) {
            return \React\Promise\reject(new RuntimeException('Failed to spawn process'));
        }

        fclose($this->pipes[0]);
        unset($this->pipes[0]);

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->stdoutBuffer = '';
        $deferred = new Deferred();

        $this->currentDeferred = $deferred;
        $this->currentProc = $proc;

        $this->loop->addReadStream($this->pipes[1], function($stream) use ($deferred): void {
            $chunk = fread($stream, 8192);
            if ($chunk !== false && $chunk !== '') {
                $this->stdoutBuffer .= $chunk;
            }
            if (feof($stream)) {
                $this->cleanup();
                if (file_exists($this->tmpFile)) {
                    $content = \file_get_contents($this->tmpFile);
                    @unlink($this->tmpFile);
                    $data = json_decode($content, true);
                    if (isset($data['error'])) {
                        $deferred->reject(new RuntimeException($data['error']));
                    } else {
                        $deferred->resolve($data);
                    }
                } else {
                    $deferred->reject(new RuntimeException('No result file'));
                }
            }
        });

        $this->loop->addReadStream($this->pipes[2], function($stream): void {
            fread($stream, 8192);
            if (feof($stream)) {
                // stderr closed silently
            }
        });

        $this->loop->addTimer(30, function() use ($deferred): void {
            if ($this->currentDeferred === $deferred) {
                $this->cleanup();
                @unlink($this->tmpFile);
                $deferred->reject(new RuntimeException('Query timed out after 30s'));
            }
        });

        return $deferred->getPromise();
    }

    private function cleanup(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        if ($this->currentProc !== null && is_resource($this->currentProc)) {
            proc_close($this->currentProc);
        }
        $this->currentProc = null;
        $this->currentDeferred = null;
    }
}
