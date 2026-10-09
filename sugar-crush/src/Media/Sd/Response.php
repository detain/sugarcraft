<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Media\MediaResponse;

/**
 * Decoder from raw sdapi JSON envelopes into lane a's MediaResponse /
 * MediaArtifact shapes — the "parsing the actual sdapi JSON into this shape
 * is W1.4's Response builder" seam MediaResponse's docblock names.
 *
 * THE GRID-PREPEND LAW (mystage §1.3, crush_media §4.2): a generation
 * response's images[] may start with the batch GRID, not a sample. The tell
 * is info.index_of_first_image === 1 — then images[0] is the grid and the
 * real samples follow it. artifacts and grid are exposed as SEPARATE slots
 * precisely so no consumer ever mistakes a grid for a generated image; when
 * the index key is missing or 0, NO grid is assumed (all images are samples)
 * — the parse never invents a grid it cannot see evidence for.
 *
 * THE INFOTEXT SHIFT LAW (plan W1.4, upstream ui.js infotexts.unshift): the
 * companions do NOT share one alignment. info.all_seeds / info.all_subseeds
 * are per-REAL-IMAGE lists that align to the sample slot; but when the grid
 * is prepended the server also prepends the GRID's infotext at infotexts[0],
 * mirroring images[0] = grid — so sample s's parameters live at
 * infotexts[s + 1]. Reading infotexts[s] unshifted mislabels every batch
 * (R1 review MAJOR-1). A length mismatch leaves the companion unset rather
 * than shifting rows off by one (map, never guess).
 */
final class Response
{
    private function __construct(
        public readonly MediaResponse $response,
        public readonly ?MediaArtifact $grid,
        /** @var array<string, mixed> decoded info JSON ([] when absent/unparseable) */
        public readonly array $info,
        /** @var list<mixed> */
        public readonly array $allSeeds,
        /** @var list<mixed> */
        public readonly array $allSubseeds,
        public readonly ?int $indexOfFirstImage,
    ) {
    }

    /**
     * txt2img/img2img envelope: {images:[b64 PNG…], parameters, info:<JSON string>}.
     *
     * @throws SdException when the payload is not the generation envelope.
     */
    public static function generation(SdTransportResult $result, string $context = 'generation'): self
    {
        $decoded = $result->decodedJson();
        if ($decoded === null || !array_key_exists('images', $decoded)) {
            throw SdException::protocol($context, 'generation response is not a JSON object with an images[] key');
        }

        $images = $decoded['images'];
        if (!is_array($images)) {
            throw SdException::protocol($context, 'generation response images is not a list');
        }

        $infoRaw = $decoded['info'] ?? null;
        $info = is_string($infoRaw) ? (json_decode($infoRaw, true) ?? []) : [];
        if (!is_array($info)) {
            $info = [];
        }

        $parameters = $decoded['parameters'] ?? [];
        if (!is_array($parameters)) {
            $parameters = [];
        }

        $indexOfFirstImage = isset($info['index_of_first_image']) && is_int($info['index_of_first_image'])
            ? $info['index_of_first_image']
            : null;

        $infotexts = isset($info['infotexts']) && is_array($info['infotexts']) ? array_values($info['infotexts']) : [];
        $allSeeds = isset($info['all_seeds']) && is_array($info['all_seeds']) ? array_values($info['all_seeds']) : [];
        $allSubseeds = isset($info['all_subseeds']) && is_array($info['all_subseeds']) ? array_values($info['all_subseeds']) : [];

        $entries = [];
        foreach (array_values($images) as $index => $entry) {
            $entries[] = self::decodePng($entry, $context, $index);
        }

        $grid = null;
        $samples = $entries;
        if ($indexOfFirstImage === 1 && count($entries) > 0) {
            $grid = array_shift($samples);
        }

        $response = MediaResponse::new([])->withParameters($parameters);
        if (is_string($infoRaw)) {
            $response = $response->withInfo($infoRaw);
        }

        foreach ($samples as $slot => $artifact) {
            // The grid prepends its own infotext row (infotexts[0] = grid),
            // so sample companions sit one index higher whenever a grid was
            // extracted. seeds/subseeds below stay per-sample (no shift).
            $infoSlot = $grid !== null ? $slot + 1 : $slot;
            if (isset($infotexts[$infoSlot]) && is_string($infotexts[$infoSlot])) {
                $artifact = $artifact->withInfotext(Infotext::parse($infotexts[$infoSlot]));
            }
            // Filename-token carriers (plan W4.5): per-sample seed rides the
            // tokenValues bag; only attached when the list lines up with the
            // sample count, so a desynced server can never mislabel an image.
            if ($allSeeds !== [] && count($allSeeds) === count($samples)) {
                $artifact = $artifact->withTokenValue('seed', $allSeeds[$slot]);
            }
            if ($allSubseeds !== [] && count($allSubseeds) === count($samples)) {
                $artifact = $artifact->withTokenValue('subseed', $allSubseeds[$slot]);
            }
            $response = $response->withArtifact($artifact);
        }

        return new self($response, $grid, $info, $allSeeds, $allSubseeds, $indexOfFirstImage);
    }

    /**
     * extra-single-image envelope: {image, html_info}.
     */
    public static function extrasSingle(SdTransportResult $result): self
    {
        $decoded = $result->decodedJson();
        if ($decoded === null || !isset($decoded['image'])) {
            throw SdException::protocol('extras-single', 'extras response lacks the image key');
        }

        $artifact = self::decodePng($decoded['image'], 'extras-single', 0);
        $response = MediaResponse::new([$artifact]);
        if (isset($decoded['html_info']) && is_string($decoded['html_info'])) {
            $response = $response->withInfo($decoded['html_info']);
        }

        return new self($response, null, is_array($decoded) ? $decoded : [], [], [], null);
    }

    /**
     * extra-batch-images envelope: {images:[{data,name},…]}.
     */
    public static function extrasBatch(SdTransportResult $result): self
    {
        $decoded = $result->decodedJson();
        if ($decoded === null || !isset($decoded['images']) || !is_array($decoded['images'])) {
            throw SdException::protocol('extras-batch', 'extras batch response lacks an images[] list');
        }

        $response = MediaResponse::new([]);
        foreach (array_values($decoded['images']) as $index => $entry) {
            if (!is_array($entry) || !isset($entry['data'])) {
                throw SdException::protocol('extras-batch', "images[{$index}] is not a {data,name} object");
            }
            $artifact = self::decodePng($entry['data'], 'extras-batch', $index);
            if (isset($entry['name']) && is_string($entry['name'])) {
                $artifact = $artifact->withPath($entry['name']);
            }
            $response = $response->withArtifact($artifact);
        }

        return new self($response, null, $decoded, [], [], null);
    }

    /**
     * png-info envelope: {info, items, parameters} — no image bytes; the
     * extracted text lands in the response info slot.
     */
    public static function pngInfo(SdTransportResult $result): self
    {
        $decoded = $result->decodedJson();
        if ($decoded === null || !isset($decoded['info'])) {
            throw SdException::protocol('png-info', 'png-info response lacks the info key');
        }

        $info = is_string($decoded['info']) ? $decoded['info'] : '';
        $response = MediaResponse::new([])->withInfo($info);
        if (isset($decoded['parameters']) && is_array($decoded['parameters'])) {
            $response = $response->withParameters($decoded['parameters']);
        }

        return new self($response, null, ['info' => $info], [], [], null);
    }

    /**
     * interrogate envelope: {caption} — text out of an image, no artifacts.
     */
    public static function interrogate(SdTransportResult $result): self
    {
        $decoded = $result->decodedJson();
        $caption = is_array($decoded) && isset($decoded['caption']) && is_string($decoded['caption'])
            ? $decoded['caption']
            : null;
        if ($caption === null) {
            throw SdException::protocol('interrogate', 'interrogate response lacks a caption string');
        }

        return new self(MediaResponse::new([])->withInfo($caption), null, ['caption' => $caption], [], [], null);
    }

    /**
     * artifacts() without ceremony — the samples only (the grid is never in
     * this list; read ->grid for it).
     *
     * @return list<MediaArtifact>
     */
    public function artifacts(): array
    {
        return $this->response->artifacts();
    }

    public function response(): MediaResponse
    {
        return $this->response;
    }

    public function grid(): ?MediaArtifact
    {
        return $this->grid;
    }

    /**
     * One b64 image entry → MediaArtifact with decoded bytes. Strict decode:
     * a server that returns non-base64 in an image slot is a protocol break,
     * not something to launder into corrupt bytes.
     */
    private static function decodePng(mixed $entry, string $context, int $index): MediaArtifact
    {
        if (!is_string($entry) || $entry === '') {
            throw SdException::protocol($context, "images[{$index}] is not a base64 string");
        }

        $bytes = base64_decode($entry, true);
        if ($bytes === false || $bytes === '') {
            throw SdException::protocol($context, "images[{$index}] failed strict base64 decoding");
        }

        return MediaArtifact::new(MediaKind::Image)->withBytes($bytes);
    }
}
