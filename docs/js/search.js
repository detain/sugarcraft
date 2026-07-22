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
  function getIndexPath() {
    var isLibPage = window.location.pathname.includes('/lib/');
    return isLibPage ? '../js/search-index.json' : 'js/search-index.json';
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
    var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var safe = escapeHtml(text);
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
      link.href = item.url;

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
        window.location.href = item.url;
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
        if (typeof selectedIndex === 'number' && selectedIndex >= 0 && selectedIndex < count && items[selectedIndex]) {
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
