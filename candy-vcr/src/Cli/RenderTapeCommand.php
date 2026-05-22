<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Ast\ParseError;

final class RenderTapeCommand extends Command
{
    protected static $defaultName = 'render-tape';
    protected static $defaultDescription = 'Render a .tape file to a .gif';

    public function __construct()
    {
        parent::__construct('render-tape');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Render a .tape file to a .gif')
            ->addArgument('tape', InputArgument::REQUIRED, 'Path to .tape file')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output .gif path (default: same as input with .gif extension)')
            ->addOption('font', 'f', InputOption::VALUE_OPTIONAL, 'TTF font family name (default: JetBrainsMono)')
            ->addOption('theme', 't', InputOption::VALUE_OPTIONAL, 'Theme name (default: TokyoNight)', 'TokyoNight')
            ->addOption('fps', null, InputOption::VALUE_OPTIONAL, 'Frames per second (default: 30)', '30')
            ->addOption('backend', 'b', InputOption::VALUE_OPTIONAL, 'Rasterizer backend: gd|imagick (default: gd)', 'gd')
            ->addOption('encoder', 'e', InputOption::VALUE_OPTIONAL, 'GIF encoder: ffmpeg|php (default: ffmpeg)', 'ffmpeg')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Error on unknown directives instead of skipping');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tapePath = $input->getArgument('tape');
        $outputPath = $input->getOption('output') ?? (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');

        $fps = (float) $input->getOption('fps');
        $backend = $input->getOption('backend') ?? 'gd';
        $encoderType = $input->getOption('encoder') ?? 'ffmpeg';
        $strict = $input->getOption('strict');
        $themeName = $input->getOption('theme') ?? 'TokyoNight';

        $encoder = match ($encoderType) {
            'php' => new PhpGifEncoder(),
            default => new FfmpegGifEncoder(),
        };

        $tapeToGif = TapeToGif::create([
            'fps' => $fps,
            'backend' => $backend,
            'encoder' => $encoderType,
        ]);

        $source = @file_get_contents($tapePath);
        if ($source === false) {
            $output->writeln("<error>Failed: Cannot read tape file: {$tapePath}</error>");
            return 1;
        }

        $progressBar = new ProgressBar($output);
        $progressBar->setFormat(' %current%/%max% %message%');
        $progressBar->setMessage('Parsing tape...');
        $progressBar->start();

        try {
            $lexer = new Lexer();
            $parser = new Parser();
            $compiler = new Compiler();

            $tokens = $lexer->tokenize($source);
            $ast = $parser->parse($tokens);

            if ($strict) {
                $errors = array_filter($ast, static fn($node) => $node instanceof ParseError);
                if ($errors !== []) {
                    /** @var ParseError $firstError */
                    $firstError = reset($errors);
                    $progressBar->finish();
                    $output->writeln('');
                    $output->writeln("<error>Failed: {$firstError->message} (line {$firstError->line})</error>");
                    return 1;
                }
            }

            $progressBar->advance();
            $progressBar->setMessage('Compiling events...');
            $progressBar->display();

            $cassette = $compiler->compile($ast, $tapePath);

            $progressBar->advance();
            $progressBar->setMessage('Rendering frames...');
            $progressBar->display();

            $progressBar->advance();
            $progressBar->setMessage('Encoding GIF...');
            $progressBar->display();

            $tapeToGif->render($tapePath, $outputPath, [
                'fps' => $fps,
                'backend' => $backend,
                'encoder' => $encoderType,
                'theme' => $themeName,
            ]);

            $progressBar->advance();
            $progressBar->setMessage('Done');
            $progressBar->finish();
            $output->writeln('');
            $output->writeln("GIF written to {$outputPath}");

            return 0;
        } catch (\Throwable $e) {
            $progressBar->finish();
            $output->writeln('');
            $output->writeln("<error>Failed: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
