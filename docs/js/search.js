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
  function onLibPage() {
    return window.location.pathname.includes('/lib/');
  }

  function getIndexPath() {
    return onLibPage() ? '../js/search-index.json' : 'js/search-index.json';
  }

  /* Search-index entries are root-anchored relative hrefs (lib/<slug>.html,
     or index.html#… for cross-page anchors). Emitted raw on a /lib/* page
     they resolve against /lib/ and 404 (→ /lib/lib/<slug>.html), so apply
     the same depth shim getIndexPath() uses for the fetch: on a /lib page,
     root-anchored relative hrefs get a '../' hop. Already-escaped ('../…'),
     absolute ('/…') or scheme-ful URLs pass through untouched. */
  function resolveResultHref(url) {
    if (typeof url !== 'string' || url === '') {
      return url;
    }
    var hasScheme = /^[a-z][a-z0-9+.-]*:/i.test(url);
    var isRootAnchored = !hasScheme && !url.startsWith('/') && !url.startsWith('../');
    return onLibPage() && isRootAnchored ? '../' + url : url;
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
  function highlight(text, query) {
    if (!query.trim()) {
      return escapeHtml(text);
    }
    // Validate query is a safe string before constructing regexp
    var trimmed = query.trim();
    if (!/^[\w\s-]+$/.test(trimmed)) {
      return escapeHtml(text);
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
      selectedIndex = -1;
      return;
    }

    results.forEach(function (item, i) {
      var li = document.createElement('li');

      var link = document.createElement('a');
      link.className = 'search-result-item';
      link.setAttribute('role', 'option');
      link.setAttribute('aria-selected', 'false');
      link.href = resolveResultHref(item.url);

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

      link.addEventListener('mouseenter', function () {
        setSelected(i);
      });

      link.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
        // Navigate via the depth-resolved href (see resolveResultHref): the
        // old arm demanded a scheme before navigating at all, which deadened
        // every root-relative result (i.e. all of them). The XSS refusal
        // stays — scheme-ful URLs must never be javascript:/data:/vbscript:
        // (tested on the trimmed form, because browsers trim before scheme
        // detection). Relative and absolute-path URLs are inherently safe.
        var url = resolveResultHref(item.url);
        if (typeof url === 'string' && !/^(javascript|data|vbscript):/i.test(url.trim())) {
          window.location.href = url;
        }
      });

      li.appendChild(link);
      resultsList.appendChild(li);
    });

    setSelected(0);
  }

  /* ── Selection ──────────────────────────────────────────────────── */
  function setSelected(index) {
    var items = resultsList.querySelectorAll('.search-result-item');
    items.forEach(function (item, i) {
      if (i === index) {
        item.classList.add('is-selected');
        item.setAttribute('aria-selected', 'true');
        item.scrollIntoView({ block: 'nearest' });
      } else {
        item.classList.remove('is-selected');
        item.setAttribute('aria-selected', 'false');
      }
    });
    selectedIndex = index;
  }

  /* ── Modal open/close ───────────────────────────────────────────── */
  function openModal() {
    isOpen = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    input.value = '';
    resultsList.innerHTML = '';
    selectedIndex = -1;
    input.focus();
    fetchIndex();
  }

  function closeModal() {
    isOpen = false;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    input.blur();
    /* Return focus to the visible trigger when one exists (G8). */
    if (trigger) {
      trigger.focus();
    }
  }

  /* ── Keyboard handler ───────────────────────────────────────────── */
  document.addEventListener('keydown', function (e) {
    /* Cmd-K / Ctrl-K or "/" to open */
    if (
      !isOpen &&
      ((e.metaKey || e.ctrlKey) && e.key === 'k') ||
      (e.key === '/' && !isInputFocused())
    ) {
      e.preventDefault();
      openModal();
      return;
    }

    if (!isOpen) {
      return;
    }

    var items = resultsList.querySelectorAll('.search-result-item');
    var count = items.length;

    switch (e.key) {
      case 'Escape':
        e.preventDefault();
        closeModal();
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
        // Ensure selectedIndex is a valid integer array index
        /* codacy ignore detect-object-injection */
        if (Number.isInteger(selectedIndex) && selectedIndex >= 0 && selectedIndex < count && items[selectedIndex]) {
          items[selectedIndex].click();
        }
        break;
    }
  });

  /* ── Input handler with debounce ────────────────────────────────── */
  input.addEventListener('input', function () {
    var query = input.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function () {
      fetchIndex().then(function () {
        var results = search(query);
        renderResults(results, query);
      });
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
