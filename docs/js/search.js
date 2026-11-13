(function () {
  'use strict';

  /* ── State ──────────────────────────────────────────────────────── */
  let indexData = [];
  let selectedIndex = -1;
  let debounceTimer = null;
  let isOpen = false;

  /* ── Elements ───────────────────────────────────────────────────── */
  var modal = document.getElementById('search-modal');
  var input = document.getElementById('search-input');
  var resultsList = document.getElementById('search-results');

  if (!modal || !input || !resultsList) {
    return;
  }

  /* ── Path resolution ────────────────────────────────────────────── */
  /* Depth counts non-empty dotless pathname segments, so '/lib/x.html' and
     '/cookbook/index.html' climb exactly once and root pages climb zero
     times. Both the index fetch and the result hrefs climb by this number —
     a shim keyed on '/lib/' alone silently 404s every result for any page
     living in another directory (site-code 11-11 indexed the narrative
     pages, one of which lives under /cookbook/). */
  function siteDepth() {
    return window.location.pathname
        .split('/')
        .filter(function (segment) { return segment !== '' && segment.indexOf('.') === -1; })
        .length;
  }

  function getIndexPath() {
    // The index lives next to this script: climb one '../' per directory
    // segment above the site root, so any page depth (root, /lib/, a future
    // nested /cookbook/ tree) resolves the same file.
    return '../'.repeat(siteDepth()) + 'js/search-index.json';
  }

  /* Search-index entries are root-anchored relative hrefs (lib/<slug>.html,
     GETTING-STARTED.html, cookbook/index.html, or index.html#… for cross-page
     anchors). Emitted raw from a page inside a directory they resolve against
     that directory and 404 (→ /lib/lib/<slug>.html), so apply the same depth
     climb getIndexPath() uses for the fetch. Already-escaped ('../…'),
     absolute ('/…') or scheme-ful URLs pass through untouched. */
  function resolveResultHref(url) {
    if (typeof url !== 'string' || url === '') {
      return url;
    }
    var hasScheme = /^[a-z][a-z0-9+.-]*:/i.test(url);
    var isRootAnchored = !hasScheme && !url.startsWith('/') && !url.startsWith('../');
    return isRootAnchored ? '../'.repeat(siteDepth()) + url : url;
  }

  /* Dangerous-scheme refusal — the single source of truth consulted by BOTH
     the href assignment and the click handler, so javascript:/data:/vbscript:
     URLs never land in the DOM at all (middle-click / copy-link included).
     Tested on the trimmed form because browsers trim before scheme detection. */
  function unsafeResultHref(url) {
    return typeof url === 'string' && /^(javascript|data|vbscript):/i.test(url.trim());
  }

  /* ── Fetch index once ───────────────────────────────────────────── */
  function fetchIndex() {
    if (indexData.length > 0) {
      return Promise.resolve(indexData);
    }
    return fetch(getIndexPath())
      .then(function (r) {
        if (!r.ok) {
          throw new Error('Failed to load search index');
        }
        return r.json();
      })
      .then(function (data) {
        indexData = data;
        return indexData;
      });
  }

  /* ── Search ─────────────────────────────────────────────────────── */
  function search(query) {
    if (!query.trim()) {
      return [];
    }
    var q = query.toLowerCase();
    return indexData.filter(function (item) {
      return (
        item.name.toLowerCase().includes(q) ||
        item.description.toLowerCase().includes(q) ||
        item.category.toLowerCase().includes(q) ||
        item.slug.toLowerCase().includes(q)
      );
    });
  }

  /* ── Escape HTML ────────────────────────────────────────────────── */
  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  /* ── Highlight match ────────────────────────────────────────────── */
  /* Contract (SD-S04, SRVFE-001): ALWAYS returns a Node — callers
     appendChild() the result. The two early paths used to hand back a
     String, so an empty query, a space, or a symbol-bearing query like
     "c++" threw a TypeError inside the shared handler and left the
     results list permanently dead for the rest of the page. Raw text
     inside a text node is literal by construction, matching the
     fragment path below (no HTML-escaping needed on a Node). */
  function highlight(text, query) {
    if (!query.trim()) {
      return document.createTextNode(text);
    }
    // Validate query is a safe string before constructing regexp
    var trimmed = query.trim();
    if (!/^[\w\s-]+$/.test(trimmed)) {
      return document.createTextNode(text);
    }
    var escaped = trimmed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var safe = escapeHtml(text);
    /* codacy ignore node_non-literal-reg-expr */
    var parts = safe.split(new RegExp('(' + escaped + ')', 'gi'));
    var result = document.createDocumentFragment();
    parts.forEach(function (part, i) {
      if (i % 2 === 1) {
        var mark = document.createElement('mark');
        mark.textContent = part;
        result.appendChild(mark);
      } else {
        result.appendChild(document.createTextNode(part));
      }
    });
    return result;
  }

  /* ── Render results ─────────────────────────────────────────────── */
  /* SD-S05 (SRVFE-006/WEBUX-03): option semantics live on the <li> — the
     listbox already maps ul[role=listbox] > li children; giving the inner
     anchor its own role="option" double-nested options for screen
     readers. Anchors get tabindex="-1": keyboard interaction is the
     combobox contract (arrows + activedescendant + Enter), focus never
     lands on a result link, and the Tab trap below stays watertight. */
  function renderResults(results, query) {
    resultsList.innerHTML = '';

    if (results.length === 0) {
      var emptyEl = document.createElement('li');
      emptyEl.className = 'search-empty';
      emptyEl.appendChild(document.createTextNode('No results for '));
      var mark = document.createElement('mark');
      mark.appendChild(highlight(query, query));
      emptyEl.appendChild(mark);
      emptyEl.appendChild(document.createTextNode(' — try a different term.'));
      resultsList.appendChild(emptyEl);
      setSelected(-1);
      return;
    }

    results.forEach(function (item, i) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.id = 'search-option-' + i;
      li.setAttribute('aria-selected', 'false');

      var link = document.createElement('a');
      link.className = 'search-result-item';
      link.setAttribute('tabindex', '-1');
      var resultHref = resolveResultHref(item.url);
      /* Scheme-ful danger urls never reach href; the anchor degrades to '#'
         and the click handler's refusal keeps it inert. */
      link.href = unsafeResultHref(resultHref) ? '#' : resultHref;

      var contentDiv = document.createElement('div');
      contentDiv.className = 'search-result-content';

      var nameSpan = document.createElement('span');
      nameSpan.className = 'search-result-name';
      nameSpan.appendChild(highlight(item.name, query));
      contentDiv.appendChild(nameSpan);

      var categorySpan = document.createElement('span');
      categorySpan.className = 'search-result-category';
      categorySpan.textContent = item.category;
      contentDiv.appendChild(categorySpan);

      link.appendChild(contentDiv);

      var descP = document.createElement('p');
      descP.className = 'search-result-description';
      descP.appendChild(highlight(item.description, query));
      link.appendChild(descP);

      li.appendChild(link);
      resultsList.appendChild(li);

      li.addEventListener('mouseenter', function () {
        setSelected(i);
      });

      link.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
        // Navigate via the depth-resolved href (see resolveResultHref): the
        // old arm demanded a scheme before navigating at all, which deadened
        // every root-relative result (i.e. all of them). The XSS refusal
        // lives in unsafeResultHref (shared with the href assignment above)
        // — scheme-ful URLs must never be javascript:/data:/vbscript:.
        // Relative and absolute-path URLs are inherently safe.
        var url = resolveResultHref(item.url);
        if (typeof url === 'string' && !unsafeResultHref(url)) {
          window.location.href = url;
        }
      });
    });

    setSelected(0);
  }

  /* SRVFE-004 (SD-S05): the old chain had no catch on either fetchIndex
     seat — a failed index load (offline, 404 after a bad deploy) left the
     modal an empty void with zero feedback. */
  function renderUnavailable() {
    resultsList.innerHTML = '';
    var row = document.createElement('li');
    row.className = 'search-empty';
    row.appendChild(document.createTextNode('Search unavailable — check your connection and reload.'));
    resultsList.appendChild(row);
    setSelected(-1);
  }

  /* ── Selection ──────────────────────────────────────────────────── */
  /* SD-S05: selection state (class, aria-selected, activedescendant) is
     carried by the <li role=option>; the anchor is presentation only. */
  function setSelected(index) {
    var options = resultsList.querySelectorAll('li[role="option"]');
    options.forEach(function (option, i) {
      if (i === index) {
        option.classList.add('is-selected');
        option.setAttribute('aria-selected', 'true');
        option.scrollIntoView({ block: 'nearest' });
      } else {
        option.classList.remove('is-selected');
        option.setAttribute('aria-selected', 'false');
      }
    });
    selectedIndex = index;
    if (index >= 0 && options[index]) {
      input.setAttribute('aria-activedescendant', options[index].id);
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  }

  /* ── Modal open/close ───────────────────────────────────────────── */
  function openModal() {
    isOpen = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    input.setAttribute('aria-expanded', 'true');
    input.value = '';
    resultsList.innerHTML = '';
    selectedIndex = -1;
    input.focus();
    fetchIndex().catch(renderUnavailable);
  }

  function closeModal() {
    isOpen = false;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    input.blur();
    /* Return focus to the visible trigger when one exists (G8). */
    if (trigger) {
      trigger.focus();
    }
  }

  /* ── Keyboard handler ───────────────────────────────────────────── */
  document.addEventListener('keydown', function (e) {
    /* SD-S05(g): !isOpen hoisted over the WHOLE disjunction — the old
       precedence let '/' re-fire openModal while the dialog was already
       open (resetting state and stealing focus on a stray keystroke). */
    if (
      !isOpen &&
      (
        ((e.metaKey || e.ctrlKey) && e.key === 'k') ||
        (e.key === '/' && !isInputFocused())
      )
    ) {
      e.preventDefault();
      openModal();
      return;
    }

    if (!isOpen) {
      return;
    }

    var options = resultsList.querySelectorAll('li[role="option"]');
    var count = options.length;

    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        closeModal();
        break;

      case 'Tab':
        /* SD-S05(d): focus trap — in the ARIA list-combobox pattern the
           input is the dialog's only tab stop; results are read via
           aria-activedescendant. Tab therefore pins focus to the input
           and never escapes to the inert page behind the overlay. */
        e.preventDefault();
        input.focus();
        break;

      case 'ArrowDown':
        e.preventDefault();
        if (count > 0) {
          setSelected((selectedIndex + 1) % count);
        }
        break;

      case 'ArrowUp':
        e.preventDefault();
        if (count > 0) {
          setSelected((selectedIndex - 1 + count) % count);
        }
        break;

      case 'Enter':
        e.preventDefault();
        /* SD-S05(f): flush the pending debounce so Enter acts on the
           CURRENT input, not a stale intermediate term. On a warm cache
           this is a synchronous re-render; on a cold one the render is
           scheduled and this Enter is spent waking it (next Enter
           activates) rather than opening a wrong match. */
        if (debounceTimer !== null) {
          clearTimeout(debounceTimer);
          debounceTimer = null;
          if (indexData.length > 0) {
            renderResults(search(input.value), input.value);
            options = resultsList.querySelectorAll('li[role="option"]');
            count = options.length;
          } else {
            fetchIndex().then(function () {
              renderResults(search(input.value), input.value);
            }).catch(renderUnavailable);
            break;
          }
        }
        // Ensure selectedIndex is a valid integer array index
        /* codacy ignore detect-object-injection */
        if (Number.isInteger(selectedIndex) && selectedIndex >= 0 && selectedIndex < count && options[selectedIndex]) {
          var activationLink = options[selectedIndex].querySelector('a');
          if (activationLink) {
            activationLink.click();
          }
        }
        break;
    }
  });

  /* ── Input handler with debounce ────────────────────────────────── */
  input.addEventListener('input', function () {
    var query = input.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () {
      debounceTimer = null;
      fetchIndex().then(function () {
        var results = search(query);
        renderResults(results, query);
      }).catch(renderUnavailable);
    }, 150);
  });

  /* ── Trigger button ───────────────────────────────────────────── */
  /* A visible <button id="search-trigger"> (docs-fix-1023 G8) makes the modal
     reachable on touch screens; the "/" and Ctrl-K chords stay as they were.
     Generated lib pages may not carry the button yet; guard, never assume. */
  var trigger = document.getElementById('search-trigger');
  if (trigger) {
    trigger.addEventListener('click', openModal);
  }

  /* ── Click backdrop to close ────────────────────────────────────── */
  var backdrop = modal.querySelector('.search-backdrop');
  if (backdrop) {
    backdrop.addEventListener('click', closeModal);
  }

  /* ── Helper ─────────────────────────────────────────────────────── */
  function isInputFocused() {
    var tag = document.activeElement.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA';
  }
})();
