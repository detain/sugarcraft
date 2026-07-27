#!/usr/bin/env python3
"""Generate kawaii-sticker icons for every Sugarcraft lib / app.

Single self-contained script:
  1. Loads a FLUX diffusion pipeline via diffusers (auto-downloads on first run).
  2. Generates a 1024x1024 kawaii sticker per subject in `SUBJECTS`.
  3. Lanczos-resizes to 240x240 and writes to all three repo locations:
        media/icons/<slug>.png
        docs/img/icons/<slug>.png
        <slug>/.assets/icon.png       (only when <slug>/ exists in the repo root)

Usage
-----
    # default: FLUX.1-schnell (Apache 2.0, ungated, ~16GB VRAM, 4 steps)
    python scripts/generate_icons.py

    # higher quality (gated; needs HF_TOKEN and license acceptance)
    python scripts/generate_icons.py --model dev

    # only certain icons
    python scripts/generate_icons.py --only sugar-toast,candy-flip

    # skip those already present
    python scripts/generate_icons.py --skip-existing

    # 4x96GB Blackwell config: 4 prompts in flight, one pipeline per GPU
    python scripts/generate_icons.py --parallel-gpus 4

    # deterministic
    python scripts/generate_icons.py --seed 42

    # see what would happen, don't actually generate
    python scripts/generate_icons.py --dry-run

Hardware
--------
  Single 48GB GPU            : default works fine. ~5-9s/image with schnell, ~25-40s/image with dev.
  4x 96GB RTX 6000 Blackwell : pass --parallel-gpus 4. All 42 icons in ~2-3 min (schnell) / ~7-10 min (dev).
  Tighter VRAM (16-24GB)     : add --cpu-offload to push T5/VAE off-GPU.

Requirements
------------
  Python 3.10+, CUDA 12.1+ runtime, recent NVIDIA driver.
  pip install -r scripts/requirements.txt

  FLUX.1-dev is gated. Either:
    huggingface-cli login  &&  accept license at
      https://huggingface.co/black-forest-labs/FLUX.1-dev
  ...or stick with --model schnell (Apache 2.0, no gating).

Docker (alternative, see scripts/Dockerfile)
--------------------------------------------
    docker build -t sugar-icons scripts/
    docker run --rm --gpus all -v "$PWD":/repo \
        -e HF_TOKEN="$HF_TOKEN" -e HF_HOME=/repo/.cache/huggingface \
        sugar-icons python /repo/scripts/generate_icons.py --parallel-gpus 4
"""
from __future__ import annotations

import argparse
import multiprocessing as mp
import os
import sys
import time
from pathlib import Path

# torch/diffusers imports happen inside main()/worker() so --help works without GPU stack.

ROOT = Path(__file__).resolve().parent.parent
TARGET_SIZE = 240          # final icon size — matches the rest of the catalog
GEN_SIZE = 1024            # FLUX native; smaller drops quality, bigger wastes time

# ---------------------------------------------------------------------------
# Style block — pasted in front of every subject. Tuned to match the existing
# kawaii illustrations (candy-core, sugar-spark, sugar-glow, candy-shell, ...).
# ---------------------------------------------------------------------------
STYLE = (
    "A cute glossy 3D-rendered kawaii sticker illustration of {SUBJECT}. "
    "Kawaii face on the subject: closed-arc smiling eyes, small open smile, "
    "soft pink blush circles on the cheeks. Bright saturated candy colors, "
    "soft studio lighting with gentle highlights, smooth rounded shapes, "
    "chubby proportions. Plain white background. Centered subject filling "
    "roughly 80 percent of a square frame. Sticker / mascot art style. "
    "No text, no letters, no numbers, no logos, no border, no drop shadow on the floor."
)

NEGATIVE = (
    "text, letters, numbers, watermark, signature, logo, border, frame, "
    "ugly, deformed, blurry, low quality, photographic, realistic, photorealistic, "
    "creepy, scary, sad, dark"
)

# ---------------------------------------------------------------------------
# Subjects — one concrete noun phrase per slug. Replace any of these freely;
# the {SUBJECT} substitution is the only variable in STYLE.
# ---------------------------------------------------------------------------
SUBJECTS: dict[str, str] = {
    # --- core / runtime / styling ---
    "candy-core":      "a glossy red-and-white striped peppermint swirl candy disc",
    "candy-sprinkles": "a small joyful pile of glossy rainbow candy sprinkles",
    "candy-zone":      "a glossy pink-and-white concentric target dartboard made of candy with three small gumball orbs floating around it",
    "candy-shell":     "a glossy pink-and-white seashell holding a single iridescent pearl",
    "candy-shine":     "a glossy faceted candy gemstone radiating soft sparkle rays",
    "candy-mold":      "a pink silicone candy mold tray with two small gummy bears nestled inside",
    "candy-kit":       "a small glossy candy toolbox open with a few candy tools peeking out",
    "candy-metrics":   "a chubby glossy candy gauge dial with a sweep needle pointing up",
    "candy-log":       "a stubby chocolate yule-log roll with rainbow sprinkles and creamy frosting on the end",
    "candy-palette":   "a glossy artist's paint palette shaped from candy with five jelly-bean paint blobs in red orange yellow green and blue and a small candy paintbrush",
    "candy-lister":    "a glossy candy clipboard with a checklist of three tiny gumdrop items each with a tick mark beside it",
    "candy-hermit":    "a tiny cute hermit crab peeking out of a glossy pink-and-white striped candy spiral shell",
    "candy-serve":     "a stack of three glossy candy server-rack units shaped like horizontal chocolate bars with small blinking gumdrop status lights",
    "candy-wish":      "a glossy candy shooting star with a long sparkle trail behind it",
    "candy-flip":      "a glossy red-and-white peppermint disc mid-flip in the air with a soft motion-arc trail behind it",
    "candy-mines":     "a chubby round black cartoon bomb made of glossy candy with a lit fuse and a few golden sparkles on top",
    "candy-tetris":    "four glossy interlocking candy tetromino blocks stacked together",
    "candy-query":     "a glossy candy magnifying glass examining a small candy data crystal",
    "candy-freeze":    "a frosted glossy candy ice-pop with delicate frost crystals on its surface",

    # --- physics / games (honey) ---
    "honey-bounce":    "a chubby cartoon honey jar bouncing along a curved honey-drip arc",
    "honey-flap":      "a chubby cartoon bee with tiny flappy wings caught mid-flap",

    # --- sugar libs / apps ---
    "sugar-bits":      "a glass jar full of colorful glossy candy bits with a purple lid on top",
    "sugar-charts":    "a row of four glossy candy bar-chart bars in pink orange green and blue with a striped lollipop perched on top",
    "sugar-prompt":    "a glossy candy speech bubble with three small candy form-input cards inside",
    "sugar-spark":     "a glossy yellow five-point candy star surrounded by bright sparkles",
    "sugar-glow":      "a chubby glossy yellow gummy bear with bright sun rays radiating behind it",
    "sugar-crush":     "a chubby glossy red candy heart with smaller floating heart shards around it",
    "sugar-reel":      "a glossy candy film reel disc in pink and cream with a striped filmstrip ribbon unspooling from it and a small white play-triangle button on the front",
    "sugar-stash":     "a chubby glossy candy treasure chest with the lid open and colorful gumdrops spilling out",
    "sugar-tick":      "a chubby glossy candy pocket watch with a small swinging chain",
    "sugar-wishlist":  "a small glossy candy scroll partly unrolled with three tiny check marks",
    "sugar-stickers":  "a glossy five-point candy star sticker with one corner peeling up to show the white sticker backing surrounded by smaller heart and circle sticker shapes",
    "sugar-toast":     "a chubby glossy slice of golden-brown toast with a square pat of butter melting on top",
    "sugar-table":     "a glossy candy spreadsheet grid floating in the air, three columns by three rows of pastel cells with the header row in pink and tiny gumdrop dots inside two cells",
    "sugar-skate":     "a glossy candy-pink skateboard deck with rainbow-sprinkle grip tape and lollipop wheels at three-quarter angle",
    "sugar-readline":  "a chubby glossy candy speech bubble with a chunky chevron prompt and a blinking cursor block inside",
    "sugar-post":      "a glossy candy-pink envelope with a heart wax seal and a small folded paper airplane darting out of it",
    "sugar-veil":      "a pair of glossy candy-pink theater curtains tied back with satin bows parting to reveal a single bright sparkle in the middle",
    "sugar-crumbs":    "a curving trail of three plump golden cookie crumbs of decreasing size with a few sparkles around them",
    "sugar-calendar":  "a chubby glossy desk-calendar block made of candy showing a single heart shape on its page with two metal rings across the top",
    "sugar-boxer":     "a glossy pastel candy gift box with a big satin ribbon bow on top, lid slightly ajar with sparkles peeking out",

    # --- apps ---
    "super-candy":     "a chubby cartoon candy character wearing a tiny red superhero cape, fists on hips in a heroic pose",

    # --- brand ---
    "sugarcraft":              "a chubby cartoon candy mascot waving cheerfully and holding a small striped lollipop in one hand",
    "sugarcraft.github.io":    "a glossy candy globe with little continents drawn in chocolate and a tiny mouse-cursor pointing at it",
}

# ---------------------------------------------------------------------------
# Model registry
# ---------------------------------------------------------------------------
MODELS = {
    # name        repo_id                                 steps  guidance
    "schnell":    ("black-forest-labs/FLUX.1-schnell",     4,    0.0),
    "dev":        ("black-forest-labs/FLUX.1-dev",        30,    3.5),
    "krea":       ("black-forest-labs/FLUX.1-Krea-dev",   30,    3.5),
}

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
def repo_targets(slug: str) -> list[Path]:
    """Where each generated icon must land inside the repo."""
    out = [
        ROOT / "media" / "icons" / f"{slug}.png",
        ROOT / "docs" / "img" / "icons" / f"{slug}.png",
    ]
    if (ROOT / slug).is_dir():
        out.append(ROOT / slug / ".assets" / "icon.png")
    return out


def downscale(pil_img, target: int):
    """Square Lanczos downscale; assumes input is already square."""
    from PIL import Image
    return pil_img.resize((target, target), Image.LANCZOS)


def write_targets(img, slug: str):
    for p in repo_targets(slug):
        p.parent.mkdir(parents=True, exist_ok=True)
        img.save(p, "PNG", optimize=True)


# ---------------------------------------------------------------------------
# Offline badge fallback — deterministic Pillow monogram tiles.
#
# The FLUX path above needs a CUDA GPU plus a multi-GB model download, so it
# cannot run in CI or an offline sandbox. `--badges` renders a reproducible
# rounded-square monogram badge per slug using only Pillow: the Candy-/Sugar-/
# Honey- prefix selects a hue family and a hash of the remainder jitters the
# hue, so every lib gets a distinct-but-on-brand accent. Use it to backfill a
# real (KB-range, non-stub) icon for a lib still awaiting bespoke kawaii art.
# ---------------------------------------------------------------------------
_HUE_FAMILY = {
    "candy": 336.0,   # rose / pink   — foundation/system libs
    "sugar": 275.0,   # violet        — components / data / apps
    "honey":  40.0,   # amber / gold  — math / physics
    "super": 210.0,   # hero blue     — showcase apps
}
_BRAND_HUE = 200.0    # sugarcraft brand fallback


def _slug_accent(slug: str):
    """Deterministic (hue, sat, val) for a slug's badge, keyed by prefix family."""
    import hashlib
    prefix = slug.split("-", 1)[0]
    base = _HUE_FAMILY.get(prefix, _BRAND_HUE)
    tail = slug.split("-", 1)[1] if "-" in slug else slug
    h = int(hashlib.sha256(tail.encode()).hexdigest(), 16)
    hue = (base + ((h % 57) - 28)) % 360           # +/-28 deg jitter within family
    sat = 0.58 + (h >> 8 & 0xFF) / 255.0 * 0.14    # 0.58 .. 0.72
    return hue, sat, 0.90


def _hsv_rgb(h, s, v):
    import colorsys
    r, g, b = colorsys.hsv_to_rgb((h % 360) / 360.0, s, v)
    return (int(r * 255), int(g * 255), int(b * 255))


def _badge_font(px: int):
    from PIL import ImageFont
    for path in ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"):
        try:
            return ImageFont.truetype(path, px)
        except OSError:
            continue
    return ImageFont.load_default()


def render_badge(slug: str, size: int = TARGET_SIZE):
    """Glossy rounded-square monogram badge for `slug` (RGB, white background)."""
    from PIL import Image, ImageChops, ImageDraw

    ss = 4                                    # supersample for crisp anti-aliasing
    s = size * ss
    hue, sat, val = _slug_accent(slug)
    top = _hsv_rgb(hue, max(0.0, sat - 0.16), min(1.0, val + 0.08))
    bot = _hsv_rgb(hue, sat, val * 0.80)

    img = Image.new("RGB", (s, s), (255, 255, 255))

    # vertical gloss gradient (light top -> saturated bottom)
    grad = Image.new("RGB", (1, s))
    gpx = grad.load()
    for y in range(s):
        t = y / (s - 1)
        gpx[0, y] = tuple(int(top[i] + (bot[i] - top[i]) * t) for i in range(3))
    grad = grad.resize((s, s))

    margin = int(s * 0.085)
    radius = int(s * 0.235)
    mask = Image.new("L", (s, s), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        [margin, margin, s - margin, s - margin], radius=radius, fill=255)
    img.paste(grad, (0, 0), mask)

    # soft glossy highlight across the top half, clipped to the badge
    hl = Image.new("L", (s, s), 0)
    ImageDraw.Draw(hl).ellipse(
        [margin, margin - int(s * 0.10), s - margin, int(s * 0.52)], fill=70)
    img.paste(Image.new("RGB", (s, s), (255, 255, 255)), (0, 0),
              ImageChops.multiply(hl, mask))

    draw = ImageDraw.Draw(img, "RGBA")
    tail = slug.split("-", 1)[1] if "-" in slug else slug
    letter = tail[0].upper()
    big = _badge_font(int(s * 0.44))
    small = _badge_font(int(s * 0.115))
    cx = s // 2
    off = int(s * 0.006)
    draw.text((cx + off, int(s * 0.44) + off), letter, font=big,
              fill=(0, 0, 0, 60), anchor="mm")
    draw.text((cx, int(s * 0.44)), letter, font=big,
              fill=(255, 255, 255, 255), anchor="mm")
    draw.text((cx, int(s * 0.78)), tail, font=small,
              fill=(255, 255, 255, 235), anchor="mm")

    return img.resize((size, size), Image.LANCZOS)


def run_badges(args):
    """Offline badge path: no torch, no network — deterministic Pillow tiles."""
    if args.only:
        slugs = [s.strip() for s in args.only.split(",") if s.strip()]
    else:
        slugs = list(SUBJECTS)
    if args.skip_existing:
        slugs = [s for s in slugs
                 if not (ROOT / "media" / "icons" / f"{s}.png").exists()]
    if not slugs:
        print("nothing to do.")
        return
    print(f"badge mode : {len(slugs)} icon(s) at {args.size}x{args.size}")
    for slug in slugs:
        img = render_badge(slug, args.size)
        write_targets(img, slug)
        print(f"  {slug:24s} {img.size[0]}x{img.size[1]}  ok")


def load_pipe(model_id: str, dtype, device: str, cpu_offload: bool):
    """Load and configure a FluxPipeline."""
    from diffusers import FluxPipeline
    pipe = FluxPipeline.from_pretrained(model_id, torch_dtype=dtype)
    pipe.set_progress_bar_config(disable=True)
    if cpu_offload:
        pipe.enable_model_cpu_offload()
    else:
        pipe.to(device)
    return pipe


def generate(pipe, subject: str, steps: int, guidance: float, seed: int, size: int):
    import torch
    prompt = STYLE.format(SUBJECT=subject)
    g = torch.Generator(device="cuda").manual_seed(seed) if torch.cuda.is_available() \
        else torch.Generator().manual_seed(seed)
    kwargs = dict(
        prompt=prompt,
        height=size, width=size,
        num_inference_steps=steps,
        guidance_scale=guidance,
        generator=g,
    )
    # FLUX.1-dev / Krea support negative_prompt via diffusers >= 0.32
    if guidance > 0:
        kwargs["negative_prompt"] = NEGATIVE
    return pipe(**kwargs).images[0]


# ---------------------------------------------------------------------------
# Multi-GPU worker (one process per GPU, each with its own pipeline)
# ---------------------------------------------------------------------------
def worker(gpu_id, model_id, dtype_str, steps, guidance, seed_base, size,
           cpu_offload, in_q, out_q):
    os.environ["CUDA_VISIBLE_DEVICES"] = str(gpu_id)
    import torch
    dtype = torch.bfloat16 if dtype_str == "bf16" else torch.float16
    pipe = load_pipe(model_id, dtype, "cuda", cpu_offload)
    while True:
        item = in_q.get()
        if item is None:
            break
        idx, slug, subject = item
        seed = seed_base + idx
        t0 = time.time()
        try:
            img_big = generate(pipe, subject, steps, guidance, seed, GEN_SIZE)
            img = downscale(img_big, size)
            write_targets(img, slug)
            out_q.put((slug, time.time() - t0, None))
        except Exception as e:  # noqa: BLE001
            out_q.put((slug, time.time() - t0, repr(e)))


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    ap = argparse.ArgumentParser(formatter_class=argparse.RawDescriptionHelpFormatter,
                                 description=__doc__)
    ap.add_argument("--model", choices=list(MODELS), default="schnell",
                    help="schnell (default, fast, ungated), dev (gated, higher quality), krea (gated, painterly)")
    ap.add_argument("--only", default="",
                    help="comma-separated slug list to limit generation")
    ap.add_argument("--skip-existing", action="store_true",
                    help="skip slugs whose media/icons/<slug>.png already exists")
    ap.add_argument("--parallel-gpus", type=int, default=1,
                    help="number of GPUs to run in parallel (one pipeline each)")
    ap.add_argument("--seed", type=int, default=12345,
                    help="base seed; per-icon seed = base + index")
    ap.add_argument("--size", type=int, default=TARGET_SIZE,
                    help=f"final icon edge length in px (default {TARGET_SIZE})")
    ap.add_argument("--dtype", choices=["bf16", "fp16"], default="bf16",
                    help="bf16 (default; needs Ampere+) or fp16")
    ap.add_argument("--cpu-offload", action="store_true",
                    help="offload T5/VAE to CPU; cuts VRAM at the cost of speed")
    ap.add_argument("--dry-run", action="store_true",
                    help="print plan, don't load model or generate")
    ap.add_argument("--badges", action="store_true",
                    help="offline mode: render deterministic Pillow monogram "
                         "badges (no GPU/network). --only names the slugs; "
                         "they need not appear in SUBJECTS.")
    args = ap.parse_args()

    if args.badges:
        run_badges(args)
        return

    model_id, steps, guidance = MODELS[args.model]

    slugs = list(SUBJECTS)
    if args.only:
        wanted = {s.strip() for s in args.only.split(",") if s.strip()}
        unknown = wanted - set(slugs)
        if unknown:
            sys.exit(f"unknown slugs: {sorted(unknown)}")
        slugs = [s for s in slugs if s in wanted]
    if args.skip_existing:
        slugs = [s for s in slugs
                 if not (ROOT / "media" / "icons" / f"{s}.png").exists()]

    if not slugs:
        print("nothing to do.")
        return

    print(f"model    : {model_id}  ({steps} steps, guidance {guidance})")
    print(f"output   : {args.size}x{args.size} (generated at {GEN_SIZE}x{GEN_SIZE})")
    print(f"icons    : {len(slugs)}")
    print(f"parallel : {args.parallel_gpus} GPU(s)")
    print(f"seed     : {args.seed} (per-icon = seed + index)")
    print()

    if args.dry_run:
        for i, s in enumerate(slugs):
            targets = ", ".join(str(p.relative_to(ROOT)) for p in repo_targets(s))
            print(f"  [{i:02d}] {s:24s} -> {targets}")
        return

    # ---- single-GPU sequential ----
    if args.parallel_gpus <= 1:
        import torch
        if not torch.cuda.is_available() and not args.cpu_offload:
            print("WARNING: CUDA not available; this will be glacial on CPU.",
                  file=sys.stderr)
        dtype = torch.bfloat16 if args.dtype == "bf16" else torch.float16
        device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"loading pipeline on {device} ...")
        pipe = load_pipe(model_id, dtype, device, args.cpu_offload)
        for i, slug in enumerate(slugs):
            t0 = time.time()
            try:
                img_big = generate(pipe, SUBJECTS[slug], steps, guidance,
                                   args.seed + i, GEN_SIZE)
                img = downscale(img_big, args.size)
                write_targets(img, slug)
                print(f"  [{i+1:02d}/{len(slugs):02d}] {slug:24s} {time.time()-t0:5.1f}s  ok")
            except Exception as e:  # noqa: BLE001
                print(f"  [{i+1:02d}/{len(slugs):02d}] {slug:24s} {time.time()-t0:5.1f}s  FAIL: {e!r}")
        return

    # ---- multi-GPU pool ----
    mp.set_start_method("spawn", force=True)
    in_q: mp.Queue = mp.Queue()
    out_q: mp.Queue = mp.Queue()
    for i, slug in enumerate(slugs):
        in_q.put((i, slug, SUBJECTS[slug]))
    for _ in range(args.parallel_gpus):
        in_q.put(None)

    procs = []
    for gpu in range(args.parallel_gpus):
        p = mp.Process(
            target=worker,
            args=(gpu, model_id, args.dtype, steps, guidance, args.seed,
                  args.size, args.cpu_offload, in_q, out_q),
            name=f"flux-gpu{gpu}",
        )
        p.start()
        procs.append(p)

    done = 0
    fails: list[tuple[str, str]] = []
    for _ in range(len(slugs)):
        slug, dt, err = out_q.get()
        done += 1
        status = "ok" if err is None else f"FAIL: {err}"
        print(f"  [{done:02d}/{len(slugs):02d}] {slug:24s} {dt:5.1f}s  {status}")
        if err:
            fails.append((slug, err))

    for p in procs:
        p.join()

    if fails:
        print(f"\n{len(fails)} failure(s):")
        for s, e in fails:
            print(f"  {s}: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
