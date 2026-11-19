// Mobile menu
const toggle = document.querySelector('.nav-toggle');
const links = document.querySelector('.nav-links');
if (toggle && links) {
  toggle.addEventListener('click', () => {
    const open = links.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  links.querySelectorAll('a').forEach((a) =>
    a.addEventListener('click', () => {
      links.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    })
  );
}

// Light / dark theme switch (dark by default, remembers the visitor's choice)
const themeBtn = document.querySelector('.theme-toggle');
if (themeBtn) {
  themeBtn.addEventListener('click', () => {
    const root = document.documentElement;
    const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('theme', next); } catch (e) {}
    document.dispatchEvent(new CustomEvent('themechange'));
  });
}

// Header border once the page scrolls
const header = document.querySelector('.site-header');
if (header) {
  const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 8);
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });
}

// Headline: reveal word by word (screen readers still get the full sentence)
document.querySelectorAll('.split').forEach((el) => {
  const text = el.textContent.trim();
  el.setAttribute('aria-label', text);
  el.innerHTML = text.split(/\s+/).map((word, i) =>
    `<span class="w" aria-hidden="true"><span style="--i:${i}">${word}</span></span>`
  ).join(' ');
});

// Eyebrow typewriter: every few seconds the cursor deletes the phrase and types the next one.
// Edit the list in the data-words attribute in index.html.
document.querySelectorAll('.typed[data-words]').forEach((el) => {
  let words;
  try { words = JSON.parse(el.dataset.words); } catch (e) { return; }
  if (!words.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const box = el.closest('.eyebrow');
  const HOLD = 2800, DELETE_MS = 40, TYPE_MS = 80, GAP = 350;
  let i = Math.max(0, words.indexOf(el.textContent));
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  (async () => {
    await wait(HOLD + 1200);
    for (;;) {
      if (document.hidden) { await wait(500); continue; }
      box && box.classList.add('typing');
      let text = el.textContent;
      while (text.length) { text = text.slice(0, -1); el.textContent = text; await wait(DELETE_MS); }
      await wait(GAP);
      i = (i + 1) % words.length;
      const next = words[i];
      for (let n = 1; n <= next.length; n++) { el.textContent = next.slice(0, n); await wait(TYPE_MS + Math.random() * 50); }
      box && box.classList.remove('typing');
      await wait(HOLD);
    }
  })();
});

// Fade sections in as they scroll into view
const revealEls = document.querySelectorAll('.reveal');
if ('IntersectionObserver' in window) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
  revealEls.forEach((el) => io.observe(el));
} else {
  revealEls.forEach((el) => el.classList.add('in'));
}

// Soft spotlight that follows the pointer on work cards
document.querySelectorAll('.card').forEach((card) => {
  card.addEventListener('pointermove', (e) => {
    const r = card.getBoundingClientRect();
    card.style.setProperty('--mx', `${e.clientX - r.left}px`);
    card.style.setProperty('--my', `${e.clientY - r.top}px`);
  });
});

// Footer year
const year = document.getElementById('year');
if (year) year.textContent = new Date().getFullYear();

// Hidden admin entrance: press ↑ ↑ ↓ ↓ (within a few seconds) anywhere outside a text box
(() => {
  const SEQ = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown'];
  let pos = 0;
  let last = 0;
  document.addEventListener('keydown', async (e) => {
    const t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) { pos = 0; return; }
    const now = Date.now();
    if (now - last > 3000) pos = 0;
    last = now;
    pos = e.key === SEQ[pos] ? pos + 1 : (e.key === SEQ[0] ? 1 : 0);
    if (pos < SEQ.length) return;
    pos = 0;
    try {
      const res = await fetch('/api/pmsim-admin.php?unlock=1', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-PmSim-Gate': '1' },
      });
      if (res.ok) window.location.href = '/api/pmsim-admin.php';
    } catch (err) { /* stay quiet */ }
  });
})();
