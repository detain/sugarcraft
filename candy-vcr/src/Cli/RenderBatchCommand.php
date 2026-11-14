<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Lang;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'render-batch')]
final class RenderBatchCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription(Lang::t('desc.render_batch'))
            ->addArgument('dir', InputArgument::REQUIRED, Lang::t('opt.dir'))
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, Lang::t('opt.output_dir'))
            ->addOption('font', 'f', InputOption::VALUE_OPTIONAL, Lang::t('opt.font'))
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, Lang::t('opt.recursive'))
            ->addOption('backend', 'b', InputOption::VALUE_OPTIONAL, Lang::t('opt.backend'), 'gd')
            ->addOption('encoder', 'e', InputOption::VALUE_OPTIONAL, Lang::t('opt.encoder'), 'ffmpeg')
            ->addOption('theme', 't', InputOption::VALUE_OPTIONAL, Lang::t('opt.theme'), 'TokyoNight')
            ->addOption('fps', null, InputOption::VALUE_OPTIONAL, Lang::t('opt.fps'), '30')
            ->addOption('jobs', 'j', InputOption::VALUE_OPTIONAL, Lang::t('opt.jobs'), '1')
            ->addOption('strict', null, InputOption::VALUE_NONE, Lang::t('opt.strict'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dirArg = $input->getArgument('dir');
        $dir = is_string($dirArg) ? $dirArg : '';
        $outputDir = $input->getOption('output-dir');
        $outputDir = is_string($outputDir) ? $outputDir : null;
        $recursive = (bool) $input->getOption('recursive');

        // Same door as render-tape — a bad --fps aborts the
        // whole batch before the first render starts, never mid-flight.
        try {
            $fps = FpsOption::parse($input->getOption('fps')) ?? 30.0;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . Lang::t('cli.err', ['verb' => 'render-batch', 'message' => $e->getMessage()]) . '</error>');
            return 1;
        }

        // Same closed-set law as render-tape: a misspelled choice is a
        // refusal, never a silent default.
        try {
            $backend = ChoiceOption::parse($input->getOption('backend'), '--backend', ['gd', 'imagick'], 'gd');
            $encoderType = ChoiceOption::parse($input->getOption('encoder'), '--encoder', ['ffmpeg', 'php'], 'ffmpeg');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . Lang::t('cli.err', ['verb' => 'render-batch', 'message' => $e->getMessage()]) . '</error>');
            return 1;
        }

        $strict = (bool) $input->getOption('strict');

        // --jobs=N parallelizes across render-tape child processes. It is
        // refused loudly when not a positive integer; jobs=1 (the default)
        // keeps the in-process serial path byte-identical to before.
        $jobsRaw = (string) $input->getOption('jobs');
        if (!preg_match('/^\d+$/', $jobsRaw) || (int) $jobsRaw < 1) {
            $output->writeln('<error>' . Lang::t('cli.err', ['verb' => 'render-batch', 'message' => Lang::t('batch.jobs_invalid', ['value' => $jobsRaw])]) . '</error>');
            return 1;
        }
        $jobs = (int) $jobsRaw;

        $themeOpt = $input->getOption('theme');
        $themeName = is_string($themeOpt) ? $themeOpt : 'TokyoNight';

        $fontOpt = $input->getOption('font');
        $fontFamily = is_string($fontOpt) ? $fontOpt : 'JetBrainsMono';

        if (!is_dir($dir)) {
            $output->writeln('<error>' . Lang::t('cli.err', ['verb' => 'render-batch', 'message' => Lang::t('batch.not_directory', ['dir' => $dir])]) . '</error>');
            return 1;
        }

        $tapeFiles = $this->collectTapeFiles($dir, $recursive);

        if ($tapeFiles === []) {
            $output->writeln('<comment>' . Lang::t('batch.none_found', ['dir' => $dir]) . '</comment>');
            return 0;
        }

        sort($tapeFiles, SORT_STRING);

        $total = count($tapeFiles);
        $output->writeln(Lang::t('batch.rendering', ['total' => $total, 'dir' => $dir]));
        if ($outputDir !== null) {
            $output->writeln(Lang::t('batch.output_directory', ['dir' => $outputDir]));
        }

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
        $progressBar->setMessage('');
        $progressBar->start();

        $successCount = 0;
        $failCount = 0;
        $startTime = microtime(true);

        /** @var array<string, array{status: string, time: float}> $results keyed by tape path */
        $results = [];

        /** @var array<string, string> $claimedOutputs gif path => tape that claimed it */
        $claimedOutputs = [];

        if ($jobs > 1) {
            [$results, $successCount, $failCount] = $this->renderParallel(
                $tapeFiles,
                $dir,
                $jobs,
                $outputDir,
                $backend,
                $encoderType,
                $themeName,
                $fontFamily,
                $fps,
                $strict,
                $progressBar,
            );
        } else {
            $renderer = TapeToGif::new([
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
                'fontFamily' => $fontFamily,
            ]);

            $renderOptions = [
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
                'theme' => $themeName,
                'fontFamily' => $fontFamily,
                'strict' => $strict,
            ];

            foreach ($tapeFiles as $tapeFile) {
                $label = $this->displayLabel($tapeFile, $dir);
                $progressBar->setMessage($label);

                $outputGif = $this->outputGifFor($tapeFile, $outputDir);

                // Two tapes named alike in different subdirectories, sent to one
                // --output-dir, would silently overwrite each other's GIF. Refuse
                // the second one loudly instead.
                if (isset($claimedOutputs[$outputGif])) {
                    $results[$tapeFile] = [
                        'status' => 'FAIL: ' . Lang::t('batch.claimed', ['gif' => $outputGif, 'tape' => $claimedOutputs[$outputGif]]),
                        'time' => 0.0,
                    ];
                    $failCount++;
                    $progressBar->advance();
                    $progressBar->display();
                    continue;
                }
                $claimedOutputs[$outputGif] = $tapeFile;

                $fileStart = microtime(true);

                try {
                    $renderer->render($tapeFile, $outputGif, $renderOptions);

                    $elapsed = microtime(true) - $fileStart;
                    // Keyed by the full path: with -r two tapes may share a
                    // basename, and a basename key would let the later one
                    // overwrite the earlier verdict (and both rows then print
                    // the same status).
                    $results[$tapeFile] = ['status' => 'OK', 'time' => $elapsed];
                    $successCount++;
                } catch (\Throwable $e) {
                    $elapsed = microtime(true) - $fileStart;
                    $results[$tapeFile] = ['status' => 'FAIL: ' . $e->getMessage(), 'time' => $elapsed];
                    $failCount++;
                }

                $progressBar->advance();
                $progressBar->display();
            }
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        $totalTime = microtime(true) - $startTime;

        $output->writeln(' ' . self::pad(Lang::t('batch.col_filename'), 40) . ' ' . self::pad(Lang::t('batch.col_status'), 10) . ' ' . Lang::t('batch.col_time'));
        $output->writeln(str_repeat('-', 65));

        foreach ($tapeFiles as $tapeFile) {
            $label = $this->displayLabel($tapeFile, $dir);
            $r = $results[$tapeFile] ?? ['status' => 'UNKNOWN', 'time' => 0.0];
            $statusStr = $r['status'];
            $timeStr = sprintf('%.3fs', $r['time']);

            if (str_starts_with($statusStr, 'FAIL')) {
                $output->writeln(sprintf(
                    ' <comment>%-40s</comment> <error>%-10s</error> %s — %s',
                    $label,
                    'FAIL',
                    $timeStr,
                    substr($statusStr, 6)
                ));
            } else {
                $output->writeln(sprintf(' %-40s %-10s %s', $label, 'OK', $timeStr));
            }
        }

        $output->writeln(str_repeat('-', 65));
        $output->writeln(Lang::t('batch.total', [
            'total' => $total,
            'ok' => $successCount,
            'failed' => $failCount,
            'seconds' => sprintf('%.3f', $totalTime),
        ]));

        return $failCount > 0 ? 1 : 0;
    }

    /**
     * Pad by display width, not bytes: translated column headers carry
     * multibyte text, and sprintf's %-Ns would under-pad them.
     */
    private static function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strwidth($text)));
    }

    /**
     * Label for the summary table: the path relative to the batch root when
     * the tape lives under it, so two same-named tapes in different
     * subdirectories remain distinguishable in the report.
     */
    private function displayLabel(string $tapeFile, string $dir): string
    {
        $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strlen($tapeFile) > strlen($prefix) && str_starts_with($tapeFile, $prefix)) {
            return substr($tapeFile, strlen($prefix));
        }
        return $tapeFile;
    }

    /**
     * The .gif path a tape renders to: the batch output dir (or the tape's own
     * directory) plus the basename with its extension swapped.
     */
    private function outputGifFor(string $tapeFile, ?string $outputDir): string
    {
        $targetDir = $outputDir ?? dirname($tapeFile);
        return $targetDir . DIRECTORY_SEPARATOR . (preg_replace('/\.tape$/', '.gif', basename($tapeFile)) ?: basename($tapeFile) . '.gif');
    }

    /**
     * The CLI entry a render-tape child process should run: the current
     * script, with the CANDY_VCR_CLI override for embedders and tests that
     * host the Application in-process (there SCRIPT_FILENAME names the host
     * binary, not candy-vcr). A phar path works unchanged — `php x.phar`
     * executes the archive stub.
     */
    private function cliEntry(): string
    {
        $override = getenv('CANDY_VCR_CLI');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($script !== '' && is_file($script)) {
            return $script;
        }
        $argv0 = (string) ($_SERVER['argv'][0] ?? '');
        return $argv0 !== '' && is_file($argv0) ? $argv0 : 'candy-vcr';
    }

    /**
     * Optional replacement for the render-tape child spawn. Production is
     * null (real Symfony Process); tests inject a factory to observe pool
     * pacing without paying real renders.
     *
     * @var (callable(list<string>): Process)|null
     */
    private $processFactory = null;

    /**
     * @param (callable(list<string>): Process)|null $factory
     */
    public function setProcessFactory(?callable $factory): void
    {
        $this->processFactory = $factory;
    }

    /**
     * Parallel branch: keeps at most $jobs render-tape child processes alive,
     * each rendering exactly one tape to exactly the path the serial loop
     * would have used, so the per-tape claim table refuses second writers
     * with the same wording and order (pending is consumed in sorted order).
     * The parent never renders — it polls, advances the progress bar as
     * children exit, and turns a non-zero child exit into the FAIL row.
     *
     * @param list<string> $tapeFiles
     * @return array{0: array<string, array{status: string, time: float}>, 1: int, 2: int}
     */
    private function renderParallel(
        array $tapeFiles,
        string $dir,
        int $jobs,
        ?string $outputDir,
        string $backend,
        string $encoderType,
        string $themeName,
        string $fontFamily,
        float $fps,
        bool $strict,
        ProgressBar $progressBar,
    ): array {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        /** @var array<string, string> $claimedOutputs */
        $claimedOutputs = [];
        $pending = $tapeFiles;
        /** @var array<int, array{tape: string, process: Process, start: float}> $running */
        $running = [];
        $entry = $this->cliEntry();

        while ($pending !== [] || $running !== []) {
            while (count($running) < $jobs && $pending !== []) {
                $tapeFile = (string) array_shift($pending);
                $progressBar->setMessage($this->displayLabel($tapeFile, $dir));
                $outputGif = $this->outputGifFor($tapeFile, $outputDir);

                if (isset($claimedOutputs[$outputGif])) {
                    $results[$tapeFile] = [
                        'status' => 'FAIL: ' . Lang::t('batch.claimed', ['gif' => $outputGif, 'tape' => $claimedOutputs[$outputGif]]),
                        'time' => 0.0,
                    ];
                    $failCount++;
                    $progressBar->advance();
                    $progressBar->display();
                    continue;
                }
                $claimedOutputs[$outputGif] = $tapeFile;

                $argv = [
                    \PHP_BINARY,
                    $entry,
                    'render-tape',
                    $tapeFile,
                    '-o', $outputGif,
                    '-b', $backend,
                    '-e', $encoderType,
                    '-t', $themeName,
                    '-f', $fontFamily,
                    '--fps', (string) $fps,
                ];
                if ($strict) {
                    $argv[] = '--strict';
                }

                $process = $this->spawnProcess($argv);
                // Serial rendering has no per-tape deadline, so neither may
                // the pool: Symfony's 300s default must not kill a huge tape
                // that the serial path would still be working on.
                $process->setTimeout(0);
                $process->start();
                $running[spl_object_id($process)] = ['tape' => $tapeFile, 'process' => $process, 'start' => microtime(true)];
            }

            foreach ($running as $id => $worker) {
                if ($worker['process']->isRunning()) {
                    continue;
                }
                // isRunning() already reported the exit; wait() reaps without
                // blocking and keeps the Symfony Process bookkeeping honest.
                $exitCode = $worker['process']->wait();
                unset($running[$id]);

                $elapsed = microtime(true) - $worker['start'];
                if ($exitCode === 0) {
                    $results[$worker['tape']] = ['status' => 'OK', 'time' => $elapsed];
                    $successCount++;
                } else {
                    $reason = $this->childFailureReason($worker['process'], $exitCode);
                    $results[$worker['tape']] = ['status' => 'FAIL: ' . $reason, 'time' => $elapsed];
                    $failCount++;
                }
                $progressBar->advance();
                $progressBar->display();
            }

            if ($pending !== [] || $running !== []) {
                usleep(2000);
            }
        }

        return [$results, $successCount, $failCount];
    }

    /**
     * First meaningful line of the child's stderr (de-decorated, capped) so
     * the FAIL row names the real cause; the exit code stays visible when the
     * child said nothing.
     */
    private function childFailureReason(Process $process, int $exitCode): string
    {
        $stderr = $process->getErrorOutput();
        $lines = preg_split('/\R/', trim(strip_tags($stderr)) ?: '') ?: [];
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strimwidth($line, 0, 200, '…');
            }
        }

        return Lang::t('batch.child_failed', ['code' => $exitCode]);
    }

    /**
     * @param list<string> $argv
     */
    private function spawnProcess(array $argv): Process
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($argv);
        }

        return new Process($argv);
    }

    /**
     * @return list<string>
     */
    private function collectTapeFiles(string $dir, bool $recursive): array
    {
        $files = [];

        if (!$recursive) {
            $entries = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.tape') ?: [];
            foreach ($entries as $entry) {
                if (is_file($entry)) {
                    $files[] = $entry;
                }
            }
            return $files;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (strtolower((string) $fileInfo->getExtension()) !== 'tape') {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }
        return $files;
    }
}
