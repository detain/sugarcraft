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

  /* ── Highlight match ────────────────────────────────────────────── */
  function highlight(text, query) {
    if (!query.trim()) {
      return text;
    }
    var escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return text.replace(new RegExp('(' + escaped + ')', 'gi'), '<mark>$1</mark>');
  }

  /* ── Render results ─────────────────────────────────────────────── */
  function renderResults(results, query) {
    resultsList.innerHTML = '';

    if (results.length === 0) {
      var emptyEl = document.createElement('li');
      emptyEl.className = 'search-empty';
      emptyEl.innerHTML =
        'No results for <mark>' +
        highlight(query, query) +
        '</mark> — try a different term.';
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

      link.innerHTML =
        '<div class="search-result-content">' +
        '<span class="search-result-name">' +
        highlight(item.name, query) +
        '</span>' +
        '<span class="search-result-category">' +
        item.category +
        '</span>' +
        '</div>' +
        '<p class="search-result-description">' +
        highlight(item.description, query) +
        '</p>';

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
        if (selectedIndex >= 0 && items[selectedIndex]) {
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
