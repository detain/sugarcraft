/* SugarCraft app carousel — progressive enhancement.
   Without JS the slides render as a static grid row (first six); all
   twenty-two apps stay reachable via the "Browse all apps" link. With JS
   the same slides become a one-at-a-time rotating showcase: auto-advance
   every six seconds, prev/next buttons, dot navigation, pause on hover or
   keyboard focus, and no timers while the tab is hidden or the user has
   asked for reduced motion. */
(function () {
  'use strict';

  var carousel = document.querySelector('.app-carousel');
  if (!carousel) {
    return;
  }

  var slides = Array.prototype.slice.call(carousel.querySelectorAll('.carousel-slide'));
  var dots = Array.prototype.slice.call(carousel.querySelectorAll('.carousel-dot'));
  var track = carousel.querySelector('.carousel-track');
  var prev = carousel.querySelector('.carousel-prev');
  var next = carousel.querySelector('.carousel-next');

  var DELAY_MS = 6000;
  var current = 0;
  var timer = null;
  var hovered = false;
  var focused = false;

  var reducedMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : { matches: false };

  carousel.classList.add('carousel-js');
  if (track) {
    track.setAttribute('aria-live', 'polite');
  }

  function show(index) {
    current = (index + slides.length) % slides.length;
    slides.forEach(function (slide, position) {
      slide.classList.toggle('is-active', position === current);
      slide.setAttribute('aria-hidden', position === current ? 'false' : 'true');
    });
    dots.forEach(function (dot, position) {
      dot.classList.toggle('is-active', position === current);
      dot.setAttribute('aria-current', position === current ? 'true' : 'false');
    });
  }

  function start() {
    stop();
    if (reducedMotion.matches || document.hidden || hovered || focused || slides.length < 2) {
      return;
    }
    timer = window.setInterval(function () {
      show(current + 1);
    }, DELAY_MS);
  }

  function stop() {
    if (timer !== null) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  if (prev) {
    prev.addEventListener('click', function () {
      show(current - 1);
      start();
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      show(current + 1);
      start();
    });
  }
  dots.forEach(function (dot, position) {
    dot.addEventListener('click', function () {
      show(position);
      start();
    });
  });

  carousel.addEventListener('mouseenter', function () {
    hovered = true;
    stop();
  });
  carousel.addEventListener('mouseleave', function () {
    hovered = false;
    start();
  });
  carousel.addEventListener('focusin', function () {
    focused = true;
    stop();
  });
  carousel.addEventListener('focusout', function () {
    focused = false;
    start();
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stop();
    } else {
      start();
    }
  });
  if (reducedMotion.addEventListener) {
    reducedMotion.addEventListener('change', function () {
      start();
    });
  }

  show(0);
  start();
})();
