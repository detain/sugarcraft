<?php

declare(strict_types=1);

/**
 * Headless render walkthrough of the media generation form, recorded by the
 * tape at .vhs/media-form.tape.
 *
 * The live form only exists inside the running TUI, so this script shows what
 * the same render model produces when nothing is typing: each beat clears the
 * screen, prints a one-line caption, and echoes the exact frame
 * MediaForm::view() answers at the capture grid - a 117-column, 31-row
 * terminal at FontSize 14 - with short sleeps so the GIF moves through the
 * states at a readable pace. The tape drives SLEEP cadence only; this file
 * consumes no keystrokes, and every state change happens by driving the
 * update()/click()/fromSections() model here in-process.
 *
 * The generation beat is canned on purpose. No server is dialled: the PNG is
 * rebuilt from fixed integer math on every run (no rand(), no clocks), wrapped
 * in a real MediaResponse/MediaArtifact pair so the DTO path is exercised, and
 * painted inline through sugar-image in halfblock truecolor. Kitty and sixel
 * are deliberately NOT demoed - VHS renders through a ttyd that does not
 * forward those passthrough protocols, so the captured frames would show
 * garbage bytes instead of the picture.
 *
 * Nothing touches the filesystem. MediaForm::open() with all defaults performs
 * zero I/O, no MediaStore is constructed, and imagepng() is called with one
 * argument so the bytes land in an output buffer rather than a path.
 *
 * @see .vhs/media-form.tape
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Code\Tui\MediaForm\Sections\HiresSection;
use SugarCraft\Code\Media\MediaArtifact;
use SugarCraft\Code\Media\MediaKind;
use SugarCraft\Code\Media\MediaResponse;

if (!extension_loaded('gd')) {
    fwrite(STDERR, "media-demo needs ext-gd to synthesize and decode the sample PNG\n");
    exit(1);
}

const DEMO_COLS = 117;
const DEMO_FORM_ROWS = 30;
const DEMO_BEAT_SECONDS = 1_100_000;

/**
 * One keystroke, exactly as the TUI would deliver it through the input pump.
 */
function demoKey(\SugarCraft\Core\KeyType $type, string $rune = ''): \SugarCraft\Core\Msg\KeyMsg
{
    return new \SugarCraft\Core\Msg\KeyMsg($type, $rune);
}

/**
 * Repaint the whole screen with a caption above a form frame.
 *
 * The frame occupies rows 2..31 of the grid, so the footer key-hint line sits
 * on the last row exactly where the live pane draws it.
 */
function demoShow(string $caption, MediaForm $form): void
{
    demoPaint($caption . "\n" . $form->view(Theme::default(), DEMO_COLS, DEMO_FORM_ROWS));
}

/**
 * Same beat shape without a form - used for the painted image and the outro.
 */
function demoPaint(string $block): void
{
    fwrite(STDOUT, "\033[2J\033[H" . $block . "\n");
    usleep(DEMO_BEAT_SECONDS);
}

// Beat 1: the form opens on Prompt with every field unset.
$form = MediaForm::open();
demoShow('media form opens - Prompt tab, every field at its default', $form);

// Beat 2: a prompt is typed the way the keyboard pump feeds it - Enter opens
// the row editor, the characters stream in, Enter commits into the draft.
$prompt = 'crystallized sugar gourd on a moonlit cake';
[$editing, $ignoredAction] = $form->update(demoKey(\SugarCraft\Core\KeyType::Enter));
$editing = $editing ?? $form;
foreach (str_split($prompt) as $character) {
    [$typed, $typedAction] = $editing->update(demoKey(\SugarCraft\Core\KeyType::Char, $character));
    $editing = $typed ?? $editing;
}
[$committed, $commitAction] = $editing->update(demoKey(\SugarCraft\Core\KeyType::Enter));
$form = $committed ?? $editing;
demoShow('prompt committed - draft()->prompt() = "' . $form->draft()->prompt() . '"', $form);

// Beat 3: Tab moves the strip focus to the generation parameters.
$form = $form->click(MediaForm::TAB_ZONE . '1');
demoShow('Tab -> Generation params (steps, sampler, size live here)', $form);

// Beat 4: Hires. fix is switched on through the same field-fold the checkbox
// key handler performs, so the accordion opens its sub-rows.
$sections = $form->sections();
$sections[3] = $sections[3]->withField(Confirm::new(HiresSection::ROW_ENABLE, true));
$form = MediaForm::fromSections($sections, $form->draft())->click(MediaForm::TAB_ZONE . '3');
demoShow('Hires. fix enabled - the accordion reveals upscaler and denoise rows', $form);

// Beat 5: the canned round trip. The pixels are fixed arithmetic, never a
// request; the DTO chain below is the one a live sdapi reply would take.
$picture = demoSynthesizePng();
$response = MediaResponse::new([
    MediaArtifact::new(MediaKind::Image)->withBytes($picture),
]);
$artifact = $response->artifacts()[0];
$form = $form->withStatus(sprintf(
    'sdapi txt2img answered - %d %s artifact(s), %d PNG bytes',
    count($response->artifacts()),
    $artifact->kind()->value,
    strlen((string) $artifact->bytes()),
));
demoShow('canned generation returns through MediaResponse (no dial was made)', $form);

// Beat 6: the returned bytes painted inline at halfblock truecolor, the mode
// every terminal in the VHS matrix can show. Kitty and sixel would need a
// passthrough the capture ttyd does not offer, so they stay off the GIF.
$painted = Mosaic::halfBlock()->render(ImageSource::fromString($picture), 64, 16);
fwrite(STDOUT, "\033[2J\033[H");
fwrite(STDOUT, "the bytes above painted through sugar-image - halfblock truecolor inline\n");
fwrite(STDOUT, $painted . "\n");
usleep(DEMO_BEAT_SECONDS);

// Beat 7: close. Nothing was ever written to disk.
demoShow('Esc closes the form - this demo created no files and dialed no server', $form);

/**
 * A deterministic 128x64 PNG built from integer arithmetic only.
 *
 * The scene is a diagonal dusk gradient with a moon disc and three hill
 * bands; identical inputs, so identical bytes on every run of one GD build.
 * imagepng() gets a single argument, so the payload goes to the buffer and
 * not to any path.
 */
function demoSynthesizePng(): string
{
    $width = 128;
    $height = 64;
    $canvas = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $red = intdiv(60 * $x + 90 * $y, $width + $height);
            $green = intdiv(30 * $x + 70 * $y, $width + $height);
            $blue = 150 + intdiv(105 * ($width - $x), $width);
            imagesetpixel($canvas, $x, $y, imagecolorallocate($canvas, $red, $green, $blue));
        }
    }

    $moon = imagecolorallocate($canvas, 245, 238, 214);
    imagefilledellipse($canvas, 98, 14, 18, 18, $moon);

    $hillNear = imagecolorallocate($canvas, 24, 22, 48);
    $hillMid = imagecolorallocate($canvas, 42, 38, 76);
    imagefilledpolygon($canvas, [0, 64, 0, 46, 42, 38, 86, 50, 128, 42, 128, 64], $hillMid);
    imagefilledpolygon($canvas, [0, 64, 0, 56, 54, 47, 100, 58, 128, 52, 128, 64], $hillNear);

    ob_start();
    imagepng($canvas);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}
