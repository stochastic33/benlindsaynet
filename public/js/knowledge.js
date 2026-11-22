// Builds the Knowledge base cards from the list in js/videos.js.
// Videos only load from YouTube when someone clicks play, which keeps the page fast
// and avoids YouTube cookies for visitors who don't watch.
(() => {
  const wrap = document.getElementById('kb-cards');
  if (!wrap) return;
  const CHANNEL = 'https://www.youtube.com/@pmknowledgebase';
  const videos = (window.PM_VIDEOS || []).filter((v) => v && v.title && v.youtube);

  const esc = (str) => String(str || '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
  const validId = (id) => /^[A-Za-z0-9_-]{6,20}$/.test(id);

  // Let the page's fade-in pick up the new cards
  const reveal = () => {
    const els = wrap.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window)) { els.forEach((el) => el.classList.add('in')); return; }
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.05 });
    els.forEach((el) => io.observe(el));
  };

  if (!videos.length) {
    wrap.innerHTML = `
      <div class="kb-empty reveal">
        <div class="kb-stack" aria-hidden="true">
          <span class="kb-spine">Strategy</span>
          <span class="kb-spine">Discovery</span>
          <span class="kb-spine">Data &amp; AI</span>
          <span class="kb-spine">Delivery</span>
        </div>
        <div>
          <p class="kb-empty-label">Coming soon</p>
          <h3>First book summaries are on the way.</h3>
          <p>Subscribe on YouTube to see them as soon as they're published.</p>
          <a class="card-link" href="${CHANNEL}?sub_confirmation=1" target="_blank" rel="noopener">Visit the channel <span class="arrow" aria-hidden="true">→</span></a>
        </div>
      </div>`;
    reveal();
    return;
  }

  wrap.innerHTML = videos.map((v, i) => {
    const id = validId(v.youtube) ? v.youtube : '';
    return `
      <article class="card kb-card reveal" style="--d:${i * 100}ms">
        <div class="kb-cover" data-id="${esc(id)}">
          <div class="kb-cover-text">
            <span class="kb-cover-num">${String(i + 1).padStart(2, '0')}</span>
            <span class="kb-cover-title">${esc(v.title)}</span>
            <span class="kb-cover-author">${esc(v.author)}</span>
          </div>
          ${id ? `<button class="kb-play" aria-label="Play summary of ${esc(v.title)}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
          </button>` : ''}
        </div>
        <div class="card-top">${v.area ? `<p class="card-tag">${esc(v.area)}</p>` : '<span></span>'}</div>
        <p>${esc(v.takeaway)}</p>
        ${id ? `<a class="card-link" href="https://www.youtube.com/watch?v=${id}" target="_blank" rel="noopener">Watch on YouTube <span class="arrow" aria-hidden="true">→</span></a>` : ''}
      </article>`;
  }).join('') + (videos.length < 3 ? `
      <div class="kb-more reveal" style="--d:${videos.length * 100}ms">
        <p class="kb-empty-label">More on the way</p>
        <h3>New summaries are coming soon.</h3>
        <a class="card-link" href="${CHANNEL}?sub_confirmation=1" target="_blank" rel="noopener">Subscribe on YouTube <span class="arrow" aria-hidden="true">→</span></a>
      </div>` : '');

  reveal();

  // Carousel: every video stays reachable. Arrows page through the cards, and the
  // row advances on its own every few seconds until the visitor takes over.
  const section = wrap.closest('.container');
  const controls = document.createElement('div');
  controls.className = 'kb-controls';
  controls.innerHTML = `
    <button class="kb-arrow" data-dir="-1" aria-label="Previous videos"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></button>
    <span class="kb-count" aria-live="polite"></span>
    <button class="kb-arrow" data-dir="1" aria-label="Next videos"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></button>`;
  wrap.after(controls);
  const count = controls.querySelector('.kb-count');
  const [prevBtn, nextBtn] = controls.querySelectorAll('.kb-arrow');
  const items = () => Array.from(wrap.children);
  const step = () => {
    const kids = items();
    if (kids.length < 2) return wrap.clientWidth;
    return kids[1].offsetLeft - kids[0].offsetLeft;
  };
  const perView = () => Math.max(1, Math.round(wrap.clientWidth / step()));
  const firstIndex = () => Math.round(wrap.scrollLeft / step());
  const atEnd = () => wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 4;
  const update = () => {
    const total = videos.length;
    const scrollable = wrap.scrollWidth > wrap.clientWidth + 4;
    controls.hidden = !scrollable;
    if (!scrollable) return;
    const first = Math.min(firstIndex(), total - 1);
    const last = Math.min(first + perView(), total);
    count.textContent = (last - first > 1 ? `${first + 1}–${last}` : `${first + 1}`) + ` of ${total}`;
    prevBtn.disabled = wrap.scrollLeft <= 4;
    nextBtn.disabled = atEnd();
  };
  const go = (dir) => {
    if (dir > 0 && atEnd()) { wrap.scrollTo({ left: 0, behavior: 'smooth' }); return; }
    wrap.scrollBy({ left: dir * step() * perView(), behavior: 'smooth' });
  };
  let timer = null;
  let stopped = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const paused = () => wrap.matches(':hover') || section.contains(document.activeElement) && document.activeElement !== document.body || document.hidden;
  const stop = () => { stopped = true; clearInterval(timer); };
  if (!stopped) {
    timer = setInterval(() => { if (!paused() && wrap.scrollWidth > wrap.clientWidth + 4) go(1); }, 7000);
  }
  controls.addEventListener('click', (e) => {
    const b = e.target.closest('.kb-arrow');
    if (!b) return;
    stop();
    go(Number(b.dataset.dir));
  });
  wrap.addEventListener('pointerdown', stop, { passive: true });
  wrap.addEventListener('wheel', (e) => { if (Math.abs(e.deltaX) > Math.abs(e.deltaY)) stop(); }, { passive: true });
  let raf = 0;
  wrap.addEventListener('scroll', () => { cancelAnimationFrame(raf); raf = requestAnimationFrame(update); }, { passive: true });
  window.addEventListener('resize', update);
  update();

  // Click to play: swap the cover for the video player
  wrap.querySelectorAll('.kb-play').forEach((btn) => {
    btn.addEventListener('click', () => {
      const cover = btn.closest('.kb-cover');
      const id = cover.dataset.id;
      if (!id) return;
      const iframe = document.createElement('iframe');
      iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`;
      iframe.title = 'Book summary video';
      iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      iframe.allowFullscreen = true;
      stop();
      cover.classList.add('playing');
      cover.replaceChildren(iframe);
    });
  });

})();
