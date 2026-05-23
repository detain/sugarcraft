<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SugarCraft\Vcr\Encode\TapeToGif;

#[AsCommand(name: 'render-batch', description: 'Render all .tape files in a directory')]
final class RenderBatchCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('dir', InputArgument::REQUIRED, 'Directory containing .tape files')
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Output directory for .gif files (default: same as source dir)')
            ->addOption('font', 'f', InputOption::VALUE_OPTIONAL, 'TTF font family name (default: JetBrainsMono)')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Search recursively')
            ->addOption('backend', 'b', InputOption::VALUE_OPTIONAL, 'Rasterizer backend: gd|imagick (default: gd)', 'gd')
            ->addOption('encoder', 'e', InputOption::VALUE_OPTIONAL, 'GIF encoder: ffmpeg|php (default: ffmpeg)', 'ffmpeg')
            ->addOption('theme', 't', InputOption::VALUE_OPTIONAL, 'Theme name (default: TokyoNight)', 'TokyoNight')
            ->addOption('fps', null, InputOption::VALUE_OPTIONAL, 'Frames per second (default: 30)', '30')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Error on unknown directives instead of skipping');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dirArg = $input->getArgument('dir');
        $dir = is_string($dirArg) ? $dirArg : '';
        $outputDir = $input->getOption('output-dir');
        $outputDir = is_string($outputDir) ? $outputDir : null;
        $recursive = (bool) $input->getOption('recursive');

        $fpsOpt = $input->getOption('fps');
        $fps = is_numeric($fpsOpt) ? (float) $fpsOpt : 30.0;

        $backendOpt = $input->getOption('backend');
        $backend = ($backendOpt === 'gd' || $backendOpt === 'imagick') ? $backendOpt : 'gd';

        $encoderOpt = $input->getOption('encoder');
        $encoderType = ($encoderOpt === 'ffmpeg' || $encoderOpt === 'php') ? $encoderOpt : 'ffmpeg';

        $strict = (bool) $input->getOption('strict');

        $themeOpt = $input->getOption('theme');
        $themeName = is_string($themeOpt) ? $themeOpt : 'TokyoNight';

        $fontOpt = $input->getOption('font');
        $fontFamily = is_string($fontOpt) ? $fontOpt : 'JetBrainsMono';

        if (!is_dir($dir)) {
            $output->writeln("<error>Not a directory: {$dir}</error>");
            return 1;
        }

        $tapeFiles = $this->collectTapeFiles($dir, $recursive);

        if ($tapeFiles === []) {
            $output->writeln("<comment>No .tape files found in {$dir}</comment>");
            return 0;
        }

        sort($tapeFiles, SORT_STRING);

        $total = count($tapeFiles);
        $output->writeln("Rendering {$total} tape(s) in {$dir}");
        if ($outputDir !== null) {
            $output->writeln("Output directory: {$outputDir}");
        }

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
        $progressBar->setMessage('');
        $progressBar->start();

        $renderer = TapeToGif::create([
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

        $successCount = 0;
        $failCount = 0;
        $startTime = microtime(true);

        /** @var array<string, array{status: string, time: float}> $results */
        $results = [];

        foreach ($tapeFiles as $tapeFile) {
            $basename = basename($tapeFile);
            $progressBar->setMessage($basename);

            $targetDir = $outputDir ?? dirname($tapeFile);
            $outputGif = $targetDir . DIRECTORY_SEPARATOR . (preg_replace('/\.tape$/', '.gif', $basename) ?: $basename . '.gif');

            $fileStart = microtime(true);

            try {
                $renderer->render($tapeFile, $outputGif, $renderOptions);

                $elapsed = microtime(true) - $fileStart;
                $results[$basename] = ['status' => 'OK', 'time' => $elapsed];
                $successCount++;
            } catch (\Throwable $e) {
                $elapsed = microtime(true) - $fileStart;
                $results[$basename] = ['status' => 'FAIL: ' . $e->getMessage(), 'time' => $elapsed];
                $failCount++;
            }

            $progressBar->advance();
            $progressBar->display();
        }

        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        $totalTime = microtime(true) - $startTime;

        $output->writeln(sprintf(' %-40s %-10s %s', 'filename', 'status', 'time'));
        $output->writeln(str_repeat('-', 65));

        foreach ($tapeFiles as $tapeFile) {
            $basename = basename($tapeFile);
            $r = $results[$basename] ?? ['status' => 'UNKNOWN', 'time' => 0.0];
            $statusStr = $r['status'];
            $timeStr = sprintf('%.3fs', $r['time']);

            if (str_starts_with($statusStr, 'FAIL')) {
                $output->writeln(sprintf(
                    ' <comment>%-40s</comment> <error>%-10s</error> %s — %s',
                    $basename,
                    'FAIL',
                    $timeStr,
                    substr($statusStr, 6)
                ));
            } else {
                $output->writeln(sprintf(' %-40s %-10s %s', $basename, 'OK', $timeStr));
            }
        }

        $output->writeln(str_repeat('-', 65));
        $output->writeln(sprintf(
            'Total: %d | Succeeded: %d | Failed: %d | Time: %.3fs',
            $total,
            $successCount,
            $failCount,
            $totalTime
        ));

        return $failCount > 0 ? 1 : 0;
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
