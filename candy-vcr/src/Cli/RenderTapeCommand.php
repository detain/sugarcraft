<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Lang;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'render-tape')]
final class RenderTapeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription(Lang::t('desc.render_tape'))
            ->addArgument('tape', InputArgument::REQUIRED, Lang::t('opt.tape'))
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, Lang::t('opt.output_gif'))
            ->addOption('font', 'f', InputOption::VALUE_OPTIONAL, Lang::t('opt.font'))
            ->addOption('theme', 't', InputOption::VALUE_OPTIONAL, Lang::t('opt.theme'), 'TokyoNight')
            ->addOption('fps', null, InputOption::VALUE_OPTIONAL, Lang::t('opt.fps'), '30')
            ->addOption('backend', 'b', InputOption::VALUE_OPTIONAL, Lang::t('opt.backend'), 'gd')
            ->addOption('encoder', 'e', InputOption::VALUE_OPTIONAL, Lang::t('opt.encoder'), 'ffmpeg')
            ->addOption('strict', null, InputOption::VALUE_NONE, Lang::t('opt.strict'))
            ->addOption('shell', null, InputOption::VALUE_REQUIRED, Lang::t('opt.shell'))
            ->addOption('exec', null, InputOption::VALUE_NONE, Lang::t('opt.exec'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, Lang::t('opt.dry_run'));
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
            $output->writeln('<error>' . Lang::t('render.failed', ['message' => $e->getMessage()]) . '</error>');
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
            $output->writeln('<error>' . Lang::t('render.failed', ['message' => $e->getMessage()]) . '</error>');
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
            $cwdHint = str_starts_with($tapePath, '/') ? '' : Lang::t('render.cwd_hint', ['cwd' => (string) getcwd()]);
            $output->writeln('<error>' . Lang::t('render.failed', ['message' => Lang::t('render.tape_not_found', ['path' => $tapePath, 'hint' => $cwdHint])]) . '</error>');
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
        $errorOutput->writeln(Lang::t('render.rendering', ['name' => basename($tapePath)]));
        $progress = static function (int $frames, int $cellW, int $cellH) use ($errorOutput): void {
            $errorOutput->writeln(Lang::t('render.progress', ['frames' => $frames, 'w' => $cellW, 'h' => $cellH]));
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
            $output->writeln('<error>' . Lang::t('render.failed', ['message' => $e->getMessage()]) . '</error>');
            return 1;
        }

        foreach ($tapeToGif->warnings() as $warning) {
            $output->writeln('<comment>' . Lang::t('render.warning', ['warning' => $warning]) . '</comment>');
        }

        $output->writeln(Lang::t('render.gif_written', ['path' => $writtenPath]));
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
                $output->writeln('<error>' . Lang::t('render.failed', ['message' => Lang::t('render.tape_too_large', ['path' => $tapePath, 'cap' => Lexer::MAX_TAPE_BYTES])]) . '</error>');
                return 1;
            }
            $source = @file_get_contents($tapePath);
            if ($source === false) {
                $output->writeln('<error>' . Lang::t('render.failed', ['message' => Lang::t('render.cannot_read_tape', ['path' => $tapePath])]) . '</error>');
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
            $output->writeln('<error>' . Lang::t('render.failed', ['message' => $e->getMessage()]) . '</error>');
            return 1;
        }

        // Route Source-include warnings to stderr so they never pollute the
        // JSONL event stream this path writes to stdout for downstream tooling.
        // Without a real stderr (non-console output), stay silent rather than
        // corrupt the machine-readable stream.
        if ($output instanceof ConsoleOutputInterface) {
            $errOut = $output->getErrorOutput();
            foreach ($compiler->warnings() as $warning) {
                $errOut->writeln('<comment>' . Lang::t('render.warning', ['warning' => $warning]) . '</comment>');
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
