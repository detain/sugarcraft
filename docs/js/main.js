// Click-to-copy on every <pre class="code"> (or anything with a .copy-btn).
document.querySelectorAll('pre.code').forEach((pre) => {
    if (pre.querySelector('.copy-btn')) return;
    const btn = document.createElement('button');
    btn.className = 'copy-btn';
    btn.type = 'button';
    btn.textContent = 'copy';
    btn.addEventListener('click', () => {
        const text = pre.querySelector('code')?.innerText ?? pre.innerText;
        navigator.clipboard.writeText(text.replace(/copy$/m, '').trim()).then(() => {
            btn.textContent = 'copied!';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.textContent = 'copy';
                btn.classList.remove('copied');
            }, 1400);
        });
    });
    pre.appendChild(btn);
});

// Stable random rotation on library cards (no layout reflow per render).
document.querySelectorAll('.lib-card .glyph-tag').forEach((g) => {
    const r = (Math.random() * 14 - 7).toFixed(1);
    g.style.transform = `rotate(${r}deg)`;
});

// Inner pill-links inside the outer lib-card anchor: nested <a> is invalid
// HTML, so most browsers flatten it and navigate only to the outer href.
// Intercept clicks on the inner pills and follow their hrefs explicitly,
// honouring modifier keys / middle-click for "open in new tab".
document.querySelectorAll('.lib-card .links a').forEach((pill) => {
    pill.addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        const href = pill.getAttribute('href');
        if (!href) return;
        const newTab = e.metaKey || e.ctrlKey || e.button === 1
            || pill.target === '_blank';
        if (newTab) {
            window.open(href, '_blank', 'noopener');
        } else {
            window.location.href = href;
        }
    });
});

// ── Lightbox: click any demo-card image to see it full-size.
//    Click off the image, hit Escape, or click the close button to dismiss.
(function initLightbox() {
    const cards = document.querySelectorAll('.demo-card img');
    if (cards.length === 0) return;

    const overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <button type="button" class="lightbox-close" aria-label="Close">×</button>
        <figure class="lightbox-figure">
            <img alt="">
            <figcaption></figcaption>
        </figure>
    `;
    document.body.appendChild(overlay);

    const lightboxImg     = overlay.querySelector('img');
    const lightboxCaption = overlay.querySelector('figcaption');
    const lightboxClose   = overlay.querySelector('.lightbox-close');

    const open = (src, alt, caption) => {
        lightboxImg.src = src;
        lightboxImg.alt = alt || '';
        lightboxCaption.textContent = caption || '';
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };
    const close = () => {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Drop the src so the GIF stops animating in the background.
        lightboxImg.removeAttribute('src');
    };

    cards.forEach((img) => {
        const card = img.closest('.demo-card');
        // Demo cards are <a> elements linking to the lib's detail page;
        // intercept the click so the lightbox opens instead, but only
        // if the user clicked the image itself (let the title/caption
        // links continue to navigate).
        img.style.cursor = 'zoom-in';
        img.addEventListener('click', (e) => {
            e.preventDefault();
            const captionEl = card?.querySelector('.demo-caption, h3');
            open(img.src, img.alt, captionEl ? captionEl.textContent.trim() : '');
        });
    });

    // Click anywhere on the overlay (but not the figure's image) closes.
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay || e.target === lightboxClose
            || e.target.classList.contains('lightbox-figure')) {
            close();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
            close();
        }
    });
})();
