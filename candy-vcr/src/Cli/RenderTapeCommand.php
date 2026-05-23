<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;

#[AsCommand(name: 'render-tape', description: 'Render a .tape file to a .gif')]
final class RenderTapeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('tape', InputArgument::REQUIRED, 'Path to .tape file')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output .gif path (default: same as input with .gif extension)')
            ->addOption('font', 'f', InputOption::VALUE_OPTIONAL, 'TTF font family name (default: JetBrainsMono)')
            ->addOption('theme', 't', InputOption::VALUE_OPTIONAL, 'Theme name (default: TokyoNight)', 'TokyoNight')
            ->addOption('fps', null, InputOption::VALUE_OPTIONAL, 'Frames per second (default: 30)', '30')
            ->addOption('backend', 'b', InputOption::VALUE_OPTIONAL, 'Rasterizer backend: gd|imagick (default: gd)', 'gd')
            ->addOption('encoder', 'e', InputOption::VALUE_OPTIONAL, 'GIF encoder: ffmpeg|php (default: ffmpeg)', 'ffmpeg')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Error on unknown directives instead of skipping')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the compiled event stream as JSONL instead of writing a GIF');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tapeArg = $input->getArgument('tape');
        $tapePath = is_string($tapeArg) ? $tapeArg : '';
        $outputOpt = $input->getOption('output');
        $outputPath = is_string($outputOpt)
            ? $outputOpt
            : (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');

        $fpsOpt = $input->getOption('fps');
        $fps = is_numeric($fpsOpt) ? (float) $fpsOpt : 30.0;

        $backendOpt = $input->getOption('backend');
        $backend = ($backendOpt === 'gd' || $backendOpt === 'imagick') ? $backendOpt : 'gd';

        $encoderOpt = $input->getOption('encoder');
        $encoderType = ($encoderOpt === 'ffmpeg' || $encoderOpt === 'php') ? $encoderOpt : 'ffmpeg';

        $strict = (bool) $input->getOption('strict');
        $dryRun = (bool) $input->getOption('dry-run');

        $themeOpt = $input->getOption('theme');
        $themeName = is_string($themeOpt) ? $themeOpt : 'TokyoNight';

        if (!is_file($tapePath)) {
            $output->writeln("<error>Failed: tape file not found: {$tapePath}</error>");
            return 1;
        }

        if ($dryRun) {
            return $this->dryRun($tapePath, $output);
        }

        try {
            TapeToGif::create([
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
            ])->render($tapePath, $outputPath, [
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
                'theme' => $themeName,
                'strict' => $strict,
            ]);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        $output->writeln("GIF written to {$outputPath}");
        return 0;
    }

    /**
     * Compile the tape and emit one JSON-line per event to stdout. The first
     * line carries the cassette header tagged with `"_header"` so downstream
     * tooling can pick it out. No Renderer / Rasterizer / Encoder runs, so
     * this is the fastest way to inspect what a `.tape` source compiles to.
     */
    private function dryRun(string $tapePath, OutputInterface $output): int
    {
        try {
            $source = @file_get_contents($tapePath);
            if ($source === false) {
                $output->writeln("<error>Failed: cannot read tape file: {$tapePath}</error>");
                return 1;
            }
            $tokens = (new Lexer())->tokenize($source);
            $ast = (new Parser())->parse($tokens);
            $cassette = (new Compiler())->compile($ast, $tapePath);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        $header = $cassette->header;
        $headerLine = [
            '_header' => [
                'v' => $header->version,
                'cols' => $header->cols,
                'rows' => $header->rows,
                'runtime' => $header->runtime,
                'theme' => $header->theme,
                'typingSpeed' => $header->typingSpeed,
                'timestampMode' => $header->timestampMode,
                'env' => $header->env,
                'eventCount' => $cassette->eventCount(),
                'duration' => $cassette->duration(),
            ],
        ];
        $output->writeln((string) json_encode($headerLine, JSON_UNESCAPED_SLASHES));

        foreach ($cassette->events as $event) {
            $line = [
                't' => round($event->t, 3),
                'kind' => $event->kind->value,
                'payload' => $event->payload,
            ];
            $output->writeln((string) json_encode($line, JSON_UNESCAPED_SLASHES));
        }

        return 0;
    }
}
