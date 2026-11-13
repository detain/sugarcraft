/*
 * Dropdown navigation enhancement.
 *
 * The panels themselves open via CSS (:hover and :focus-within at ≥641px).
 * This script is progressive enhancement only — with JS disabled, hovering
 * and keyboard focus still open every panel. Here we add:
 *
 *   - click/tap toggling of .nav-open on the owning <li> (the mobile path,
 *     and a convenience on desktop),
 *   - aria-expanded kept in sync on each .nav-drop-toggle — including with
 *     the pure-CSS :focus-within reveal, which the old class-based sync
 *     left disagreeing with the visible panel (SD-S12),
 *   - close-all on Escape and on any click outside a .nav-item, with Escape
 *     parking focus on the owning trigger instead of dropping it on the
 *     page floor; a .nav-locked class suppresses the CSS focus reveal while
 *     parked, so visual, aria and focus always agree (the lock clears on a
 *     re-open click, once focus moves into the panel, or when focus leaves
 *     the item entirely).
 *
 * No frameworks, no build step; identical markup ships on every page.
 */
(function () {
    'use strict';

    var items = document.querySelectorAll('.nav-item');
    if (items.length === 0) {
        return;
    }

    function collapse(item) {
        item.classList.remove('nav-open');
        item.classList.remove('nav-locked');
        var toggle = item.querySelector('.nav-drop-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    function closeAll(except) {
        Array.prototype.forEach.call(items, function (item) {
            if (item !== except) {
                collapse(item);
            }
        });
    }

    Array.prototype.forEach.call(items, function (item) {
        var toggle = item.querySelector('.nav-drop-toggle');
        if (!toggle) {
            return;
        }
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            // Re-open after an Escape park always works: the click clears
            // the lock before deciding the new state.
            item.classList.remove('nav-locked');
            var willOpen = !item.classList.contains('nav-open');
            closeAll(item);
            if (willOpen) {
                item.classList.add('nav-open');
                toggle.setAttribute('aria-expanded', 'true');
            } else {
                collapse(item);
            }
        });

        // Keep aria-expanded honest with the CSS :focus-within reveal.
        item.addEventListener('focusin', function (event) {
            if (item.classList.contains('nav-locked')) {
                // Escape parked focus on the trigger and the CSS lock hides
                // the panel, so a locked focusin on anything but the trigger
                // should not happen; if it ever does, treat it as the user
                // entering the panel and lift the lock. Focus on the parked
                // trigger itself must NOT re-arm aria-expanded.
                if (event.target !== toggle) {
                    item.classList.remove('nav-locked');
                    toggle.setAttribute('aria-expanded', 'true');
                }
                return;
            }
            if (event.target === toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
        item.addEventListener('focusout', function (event) {
            var next = event.relatedTarget;
            if (next instanceof Element && item.contains(next)) {
                return;
            }
            // Focus left the item: the CSS reveal is gone; tear the whole
            // open state down so class, aria and visuals agree again.
            collapse(item);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var parked = null;
        var active = document.activeElement;
        if (active instanceof Element) {
            parked = active.closest('.nav-item');
        }
        closeAll(null);
        if (parked) {
            var toggle = parked.querySelector('.nav-drop-toggle');
            if (toggle) {
                // Suppress the :focus-within reveal while focus sits on the
                // trigger, then park focus there: closed + focused + aria
                // false is a truthful triple (SD-S12 acceptance probe).
                parked.classList.add('nav-locked');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (!target.closest('.nav-item')) {
            closeAll(null);
        }
    });
})();
