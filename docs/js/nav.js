/*
 * Dropdown navigation enhancement.
 *
 * The panels themselves open via CSS (:hover and :focus-within at ≥641px).
 * This script is progressive enhancement only — with JS disabled, hovering
 * and keyboard focus still open every panel. Here we add:
 *
 *   - click/tap toggling of .nav-open on the owning <li> (the mobile path,
 *     and a convenience on desktop),
 *   - aria-expanded kept in sync on each .nav-drop-toggle,
 *   - close-all on Escape and on any click outside a .nav-item.
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
            var willOpen = !item.classList.contains('nav-open');
            closeAll(item);
            if (willOpen) {
                item.classList.add('nav-open');
                toggle.setAttribute('aria-expanded', 'true');
            } else {
                collapse(item);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll(null);
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
