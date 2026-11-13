<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
            ->addOption('shell', null, InputOption::VALUE_REQUIRED, 'Execute typed commands in this shell under a real PTY and capture the program output (exec mode). Overrides the tape\'s Set Shell.')
            ->addOption('exec', null, InputOption::VALUE_NONE, 'Enable exec mode with a default shell ($SHELL or /bin/sh) when the tape sets no Set Shell')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the compiled event stream as JSONL instead of writing a GIF');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tapeArg = $input->getArgument('tape');
        $tapePath = is_string($tapeArg) ? $tapeArg : '';
        // Leave the output path null when --output is absent so the tape's own
        // (confined) `Output <path>` directive is honored; TapeToGif falls back
        // to the tape name with a `.gif` extension when neither is present.
        $outputOpt = $input->getOption('output');
        $explicitOutput = is_string($outputOpt) ? $outputOpt : null;

        // Parse --fps at the door. Previously any is_numeric
        // string passed — "--fps 0" reached FrameStream's 1.0/$fps (float
        // DivisionByZeroError mid-render) and integer 0 the encoder's 1000/$fps
        // (ArithmeticError/hang). Fail loud here instead.
        try {
            $fps = FpsOption::parse($input->getOption('fps')) ?? 30.0;
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        // --backend/--encoder used to coerce any misspelling to the
        // default, so `--backend=imagickk` quietly rendered through gd. The
        // flag takes a closed value set; an unknown spelling is a usage
        // refusal that names every accepted value.
        try {
            $backend = ChoiceOption::parse($input->getOption('backend'), '--backend', ['gd', 'imagick'], 'gd');
            $encoderType = ChoiceOption::parse($input->getOption('encoder'), '--encoder', ['ffmpeg', 'php'], 'ffmpeg');
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        $strict = (bool) $input->getOption('strict');
        $dryRun = (bool) $input->getOption('dry-run');

        $shellOpt = $input->getOption('shell');
        $shell = is_string($shellOpt) && $shellOpt !== '' ? $shellOpt : null;
        $exec = (bool) $input->getOption('exec');

        $themeOpt = $input->getOption('theme');
        $themeName = is_string($themeOpt) ? $themeOpt : 'TokyoNight';

        if (!is_file($tapePath)) {
            $cwdHint = str_starts_with($tapePath, '/') ? '' : ' (cwd: ' . (string) getcwd() . ')';
            $output->writeln("<error>Failed: tape file not found: {$tapePath}{$cwdHint}</error>");
            return 1;
        }

        if ($dryRun) {
            return $this->dryRun($tapePath, $output, (bool) $input->getOption('strict'));
        }

        $fontOpt = $input->getOption('font');
        $fontFamily = is_string($fontOpt) ? $fontOpt : 'JetBrainsMono';

        // A multi-minute render used to say nothing at all until the finished
        // file was named. Progress is a count, not a percentage (the frame
        // stream is lazy, so the total is not knowable up front), and it rides
        // stderr so stdout stays the machine-readable channel.
        $errorOutput = $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
        $errorOutput->writeln(sprintf('Rendering %s ...', basename($tapePath)));
        $progress = static function (int $frames, int $cellW, int $cellH) use ($errorOutput): void {
            $errorOutput->writeln(sprintf('  %d frames rendered (%dx%d px cells)', $frames, $cellW, $cellH));
        };

        $tapeToGif = TapeToGif::create([
            'fps' => $fps,
            'backend' => $backend,
            'encoder' => $encoderType,
            'fontFamily' => $fontFamily,
        ]);

        try {
            $writtenPath = $tapeToGif->render($tapePath, $explicitOutput, [
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
                'theme' => $themeName,
                'fontFamily' => $fontFamily,
                'strict' => $strict,
                'shell' => $shell,
                'exec' => $exec,
                'onProgress' => $progress,
            ]);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        foreach ($tapeToGif->warnings() as $warning) {
            $output->writeln("<comment>Warning: {$warning}</comment>");
        }

        $output->writeln("GIF written to {$writtenPath}");
        return 0;
    }

    /**
     * Compile the tape and emit one JSON-line per event to stdout. The first
     * line carries the cassette header tagged with `"_header"` so downstream
     * tooling can pick it out. No Renderer / Rasterizer / Encoder runs, so
     * this is the fastest way to inspect what a `.tape` source compiles to.
     */
    private function dryRun(string $tapePath, OutputInterface $output, bool $strict = false): int
    {
        try {
            // Size law (finding 032): the stat gate refuses an oversized
            // tape by name before it is ever slurped.
            $size = @filesize($tapePath);
            if ($size !== false && $size > Lexer::MAX_TAPE_BYTES) {
                $output->writeln('<error>Failed: tape file ' . $tapePath . ' exceeds the ' . Lexer::MAX_TAPE_BYTES . '-byte tape cap</error>');
                return 1;
            }
            $source = @file_get_contents($tapePath);
            if ($source === false) {
                $output->writeln("<error>Failed: cannot read tape file: {$tapePath}</error>");
                return 1;
            }
            $tokens = (new Lexer())->tokenize($source);
            $ast = (new Parser())->parse($tokens);
            $compiler = new Compiler();
            // Strict pass-through (finding 024): `--dry-run --strict` must
            // abort on the same UNKNOWN class the GIF path aborts on, not
            // print a clean stream with the bad directive quietly removed.
            $cassette = $compiler->compile($ast, $tapePath, $strict);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }

        // Route Source-include warnings to stderr so they never pollute the
        // JSONL event stream this path writes to stdout for downstream tooling.
        // Without a real stderr (non-console output), stay silent rather than
        // corrupt the machine-readable stream.
        if ($output instanceof ConsoleOutputInterface) {
            $errOut = $output->getErrorOutput();
            foreach ($compiler->warnings() as $warning) {
                $errOut->writeln("<comment>Warning: {$warning}</comment>");
            }
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
        // SUBSTITUTE on the dry-run lines mirrors the recorder's contract: a
        // cast payload with undisplayable bytes must degrade to U+FFFD, not
        // silently vanish (json_encode returns false, the cast gives '').
        $output->writeln((string) json_encode($headerLine, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        foreach ($cassette->events as $event) {
            $line = [
                't' => round($event->t, 3),
                'kind' => $event->kind->value,
                'payload' => $event->payload,
            ];
            $output->writeln((string) json_encode($line, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return 0;
    }
}
