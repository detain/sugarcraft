<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;

/**
 * Renders a file preview pane.
 *
 * For image files, delegates to {@see \SugarCraft\Mosaic\Renderer} for
 * ANSI-encoded terminal previews. For non-image files, shows file
 * metadata (size, mtime, type) as plain text.
 *
 * Immutable + fluent — every with*() returns a new instance.
 */
final class PreviewPane
{
    /**
     * @param string[] $supportedImageExtensions  e.g. ['png', 'jpg', 'jpeg', 'gif']
     */
    public function __construct(
        public readonly string $filePath = '',
        public readonly int $previewWidth = 40,
        public readonly int $previewHeight = 20,
        public readonly array $supportedImageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'],
        public readonly string $error = '',
    ) {}

    /**
     * Create a preview for a given file path.
     */
    public static function forFile(string $filePath): self
    {
        return new self(filePath: $filePath);
    }

    /**
     * Set the preview width in terminal cells.
     */
    public function withWidth(int $width): self
    {
        if ($width <= 0) {
            return $this->mutate(error: Lang::t('preview.invalid_width'));
        }
        return $this->mutate(previewWidth: $width);
    }

    /**
     * Set the preview height in terminal cells.
     */
    public function withHeight(int $height): self
    {
        if ($height <= 0) {
            return $this->mutate(error: Lang::t('preview.invalid_height'));
        }
        return $this->mutate(previewHeight: $height);
    }

    /**
     * True when the current file is a supported image type.
     */
    public function isImage(): bool
    {
        if ($this->filePath === '' || !is_file($this->filePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));
        return in_array($ext, $this->supportedImageExtensions, true);
    }

    /**
     * True when the current file is a directory.
     */
    public function isDirectory(): bool
    {
        return $this->filePath !== '' && \is_dir($this->filePath);
    }

    /**
     * Render the preview as ANSI bytes.
     *
     * For images, returns the Mosaic-rendered ANSI string.
     * For other files, returns a text metadata block.
     *
     * @return string Raw ANSI bytes or text
     */
    public function render(): string
    {
        if ($this->filePath === '') {
            return $this->renderPlaceholder(Lang::t('preview.no_file'));
        }

        if (!file_exists($this->filePath)) {
            return $this->renderPlaceholder(Lang::t('preview.file_not_found'));
        }

        if ($this->isDirectory()) {
            return $this->renderPlaceholder(Lang::t('preview.is_directory'));
        }

        if ($this->isImage()) {
            return $this->renderImage();
        }

        return $this->renderMetadata();
    }

    /**
     * Render an image preview via Mosaic.
     *
     * @return string Raw ANSI bytes; falls back to metadata on error
     */
    public function renderImage(): string
    {
        if (!$this->isImage()) {
            return $this->renderMetadata();
        }

        try {
            $image = ImageSource::fromFile($this->filePath);
            $mosaic = Mosaic::probe();
            return $mosaic->render($image, $this->previewWidth, $this->previewHeight);
        } catch (\Throwable $e) {
            return $this->renderPlaceholder(Lang::t('preview.image_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Render file metadata as plain text.
     *
     * @return string Formatted text block
     */
    public function renderMetadata(): string
    {
        if ($this->filePath === '') {
            return '';
        }

        $lines = [];
        $lines[] = '  ' . Lang::t('preview.metadata');
        $lines[] = '';

        $stat = @stat($this->filePath);
        if ($stat === false) {
            $lines[] = '  ' . Lang::t('preview.stat_failed');
            return implode("\n", $lines);
        }

        $lines[] = '  ' . Lang::t('preview.size') . ': ' . $this->formatSize($stat['size']);
        $lines[] = '  ' . Lang::t('preview.mtime') . ': ' . date('Y-m-d H:i:s', $stat['mtime']);
        $lines[] = '  ' . Lang::t('preview.mode') . ': ' . $this->formatMode($stat['mode']);
        $lines[] = '  ' . Lang::t('preview.type') . ': ' . $this->resolveFileType();

        if (is_link($this->filePath)) {
            $target = @readlink($this->filePath);
            if ($target !== false) {
                $lines[] = '  ' . Lang::t('preview.link_target') . ': ' . $target;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the MIME type / file type description.
     */
    public function resolveFileType(): string
    {
        $ext = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = @$finfo->file($this->filePath);
            if ($mime !== false && $mime !== 'application/octet-stream') {
                return $mime;
            }
        }

        return match ($ext) {
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
            'mp4', 'avi', 'mov', 'mkv' => 'video/' . $ext,
            'mp3', 'wav', 'flac', 'ogg', 'm4a' => 'audio/' . $ext,
            'zip', 'tar', 'gz', 'bz2', 'xz', '7z' => 'archive/' . $ext,
            'php', 'js', 'ts', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'hpp' => 'text/plain',
            'txt', 'md', 'rst', 'log' => 'text/plain',
            'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'conf', 'cfg' => 'text/plain',
            'html', 'htm', 'css', 'scss', 'sass', 'less' => 'text/plain',
            'pdf' => 'application/pdf',
            'doc', 'docx', 'odt', 'rtf' => 'application/document',
            'xls', 'xlsx', 'ods', 'csv' => 'application/spreadsheet',
            'ppt', 'pptx', 'odp' => 'application/presentation',
            default => 'application/octet-stream',
        };
    }

    /**
     * Format byte size to human-readable string.
     */
    public function formatSize(int $bytes): string
    {
        if ($bytes < 0) {
            return Lang::t('preview.unknown');
        }

        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Format Unix file mode to rwxrwxrwx string.
     */
    public function formatMode(int $mode): string
    {
        $perms = '';
        $mask = 0o777;

        $perms .= ($mode & 0o400) ? 'r' : '-';
        $perms .= ($mode & 0o200) ? 'w' : '-';
        $perms .= ($mode & 0o100) ? 'x' : '-';
        $perms .= ($mode & 0o040) ? 'r' : '-';
        $perms .= ($mode & 0o020) ? 'w' : '-';
        $perms .= ($mode & 0o010) ? 'x' : '-';
        $perms .= ($mode & 0o004) ? 'r' : '-';
        $perms .= ($mode & 0o002) ? 'w' : '-';
        $perms .= ($mode & 0o001) ? 'x' : '-';

        // File type
        if (($mode & 0o170000) === 0o120000) {
            $type = 'l';
        } elseif (($mode & 0o170000) === 0o040000) {
            $type = 'd';
        } elseif (($mode & 0o170000) === 0o060000) {
            $type = 'b';
        } elseif (($mode & 0o170000) === 0o020000) {
            $type = 'c';
        } elseif (($mode & 0o170000) === 0o010000) {
            $type = 'p';
        } else {
            $type = '-';
        }

        return $type . $perms;
    }

    /**
     * Get last error message.
     */
    public function lastError(): string
    {
        return $this->error;
    }

    /**
     * Render a placeholder message.
     */
    private function renderPlaceholder(string $message): string
    {
        return "\n  {$message}\n";
    }

    /**
     * @param string|null $filePath
     * @param int|null    $previewWidth
     * @param int|null    $previewHeight
     * @param array|null  $supportedImageExtensions
     * @param string|null $error
     */
    private function mutate(
        ?string $filePath = null,
        ?int $previewWidth = null,
        ?int $previewHeight = null,
        ?array $supportedImageExtensions = null,
        ?string $error = null,
    ): self {
        return new self(
            $filePath ?? $this->filePath,
            $previewWidth ?? $this->previewWidth,
            $previewHeight ?? $this->previewHeight,
            $supportedImageExtensions ?? $this->supportedImageExtensions,
            $error ?? $this->error,
        );
    }
}
