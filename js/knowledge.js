// Builds the Knowledge base cards from the list in js/videos.js.
// Videos only load from YouTube when someone clicks play, which keeps the page fast
// and avoids YouTube cookies for visitors who don't watch.
(() => {
  const wrap = document.getElementById('kb-cards');
  if (!wrap) return;
  const CHANNEL = 'https://www.youtube.com/@pmknowledgebase';
  const videos = (window.PM_VIDEOS || []).filter((v) => v && v.title && v.youtube).slice(0, 3);

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
  }).join('');

  reveal();

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
      cover.classList.add('playing');
      cover.replaceChildren(iframe);
    });
  });

})();
