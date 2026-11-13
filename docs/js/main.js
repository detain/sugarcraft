// Click-to-copy on every <pre class="code"> (or anything with a .copy-btn).
document.querySelectorAll('pre.code').forEach((pre) => {
    if (pre.querySelector('.copy-btn')) return;
    const btn = document.createElement('button');
    btn.className = 'copy-btn';
    btn.type = 'button';
    btn.textContent = 'copy';
    // SRVFE-010 (SD-S13): cache the code text NOW, before the button joins
    // the <pre>. The old click-time read had to un-splice the button label
    // with /copy$/m, which silently ate the word "copy" from snippets that
    // genuinely ended with a line called `copy`.
    const codeText = (pre.querySelector('code')?.innerText ?? pre.innerText).trim();
    const flash = (label, cls) => {
        btn.textContent = label;
        btn.classList.add(cls);
        setTimeout(() => {
            btn.textContent = 'copy';
            btn.classList.remove(cls);
        }, 1400);
    };
    // SRVFE-003 (SD-S13): navigator.clipboard only exists in secure
    // contexts; the old bare call threw a TypeError on http origins with
    // no .catch, so the copy button silently killed the handler. Fallback:
    // hidden textarea + execCommand, and a visible failure state if even
    // that is refused.
    const fallbackCopy = () => {
        const ta = document.createElement('textarea');
        ta.value = codeText;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        document.body.removeChild(ta);
        if (ok) flash('copied!', 'copied');
        else flash('copy failed', 'copy-failed');
    };
    btn.addEventListener('click', () => {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(codeText).then(
                () => flash('copied!', 'copied'),
                () => fallbackCopy()
            );
        } else {
            fallbackCopy();
        }
    });
    pre.appendChild(btn);
});

// Stable random rotation on library cards (no layout reflow per render).
document.querySelectorAll('.lib-card .glyph-tag').forEach((g) => {
    const r = (Math.random() * 14 - 7).toFixed(1);
    g.style.transform = `rotate(${r}deg)`;
});

// Pointer convenience for catalogue cards (SD-S02 restructured them to
// div + real anchors): clicking the card body follows the title link,
// ctrl/meta-click and middle-click open it in a new tab — the same
// mouse affordance the old whole-card <a> gave. Keyboard and
// screen-reader users need none of this: title and GitHub pill are
// genuine anchors.
document.querySelectorAll('div.lib-card').forEach((card) => {
    const hrefOf = () => { const t = card.querySelector('a.lib-card-title'); return t ? t.getAttribute('href') : null; };
    card.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;
        const href = hrefOf();
        if (!href) return;
        if (e.metaKey || e.ctrlKey) window.open(href, '_blank', 'noopener');
        else window.location.href = href;
    });
    card.addEventListener('auxclick', (e) => {
        if (e.button === 1) { const href = hrefOf(); if (href) { e.preventDefault(); window.open(href, '_blank', 'noopener'); } }
    });
});

// ── Lightbox: click any demo-card image to see it full-size.
//    Click off the image, hit Escape, or click the close button to dismiss.
//    SD-S13 (WEBUX-04): the overlay is a real modal for keyboard users —
//    Enter/Space on a demo card opens it (instead of navigating), focus
//    starts on the close button, Tab cycles inside the dialog, and closing
//    restores focus to the trigger (the search.js closeModal pattern). The
//    card's package-page link moves into the dialog while it is open, so
//    capturing Enter does not cost keyboard users the navigation.
(function initLightbox() {
    const cards = document.querySelectorAll('.demo-card img');
    if (cards.length === 0) return;

    const overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-label', 'Demo image viewer');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = `
        <button type="button" class="lightbox-close" aria-label="Close">×</button>
        <figure class="lightbox-figure">
            <img alt="">
            <figcaption></figcaption>
            <a class="lightbox-page" href="#">Package page →</a>
        </figure>
    `;
    document.body.appendChild(overlay);

    const lightboxImg     = overlay.querySelector('img');
    const lightboxCaption = overlay.querySelector('figcaption');
    const lightboxClose   = overlay.querySelector('.lightbox-close');
    const lightboxPage    = overlay.querySelector('.lightbox-page');

    let lastFocused = null;   // trigger to restore on close
    let pageHref = null;      // current card link, mirrored into the dialog

    const open = (src, alt, caption, href, trigger) => {
        lastFocused = trigger || document.activeElement;
        pageHref = href || null;
        lightboxImg.src = src;
        lightboxImg.alt = alt || '';
        lightboxCaption.textContent = caption || '';
        if (pageHref) {
            lightboxPage.setAttribute('href', pageHref);
            lightboxPage.hidden = false;
        } else {
            lightboxPage.removeAttribute('href');
            lightboxPage.hidden = true;
        }
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        lightboxClose.focus();
    };
    const close = () => {
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Drop the src so the GIF stops animating in the background.
        lightboxImg.removeAttribute('src');
        if (lastFocused && document.contains(lastFocused)) {
            lastFocused.focus();
        }
        lastFocused = null;
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
            open(img.src, img.alt, captionEl ? captionEl.textContent.trim() : '',
                 card ? card.getAttribute('href') : null, img);
        });
    });

    // Keyboard route into the lightbox: Enter or Space on a focused demo
    // card opens the viewer. The card's own href is offered inside the
    // dialog, so navigation stays reachable without the mouse.
    document.querySelectorAll('a.demo-card').forEach((card) => {
        const img = card.querySelector('img');
        if (!img) return;
        card.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            const captionEl = card.querySelector('.demo-caption, h3');
            open(img.src, img.alt, captionEl ? captionEl.textContent.trim() : '',
                 card.getAttribute('href'), card);
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
        if (!overlay.classList.contains('is-open')) return;
        if (e.key === 'Escape') { close(); return; }
        if (e.key === 'Tab') {
            // Focus trap: cycle close button ⇄ package-page link.
            const foci = pageHref ? [lightboxClose, lightboxPage] : [lightboxClose];
            const i = foci.indexOf(document.activeElement);
            e.preventDefault();
            if (e.shiftKey) foci[(i <= 0 ? foci.length : i) - 1].focus();
            else foci[(i + 1) % foci.length].focus();
        }
    });
})();
