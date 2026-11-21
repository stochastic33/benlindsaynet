// Product Manager Simulator: browser side.
// All rules, scoring and the AI call happen on the server (api/pm-sim.php).
(() => {
  const API = '/api/pm-sim.php';
  const $ = (id) => document.getElementById(id);
  const screens = { start: $('sim-start'), game: $('sim-game'), end: $('sim-end') };
  const log = $('sim-log');
  const form = $('sim-form');
  const input = $('sim-text');
  const send = $('sim-send');
  const thinking = $('sim-thinking');
  const errorBox = $('sim-error');
  const sugg = $('sim-suggestions');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const LABELS = { customers: 'Customers', team: 'Team', stakeholders: 'Stakeholders', business: 'Business value', techDebt: 'Tech debt' };
  let busy = false;

  const show = (name) => {
    Object.entries(screens).forEach(([k, el]) => { el.hidden = k !== name; });
  };

  const call = async (body) => {
    const res = await fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-PmSim': '1' },
      body: JSON.stringify(body),
    });
    let data;
    try { data = await res.json(); } catch (e) { data = { ok: false, message: 'The simulator is unavailable right now. Please try again later.' }; }
    return data;
  };

  const setBusy = (on) => {
    busy = on;
    thinking.hidden = !on;
    send.disabled = on;
    input.disabled = on;
    sugg.querySelectorAll('button').forEach((b) => { b.disabled = on; });
  };

  const showError = (msg) => {
    errorBox.textContent = msg || '';
    errorBox.hidden = !msg;
  };

  const scrollLog = () => { log.scrollTop = log.scrollHeight; };

  // Typewriter effect; click the log to finish instantly
  let skipTyping = false;
  log.addEventListener('click', () => { skipTyping = true; });
  const typeInto = (el, text) => new Promise((resolve) => {
    if (reduceMotion || !text) { el.textContent = text; scrollLog(); resolve(); return; }
    skipTyping = false;
    let i = 0;
    const step = () => {
      if (skipTyping) { el.textContent = text; scrollLog(); resolve(); return; }
      i = Math.min(text.length, i + 2);
      el.textContent = text.slice(0, i);
      if (i % 20 === 0) scrollLog();
      if (i < text.length) setTimeout(step, 14); else { scrollLog(); resolve(); }
    };
    step();
  });

  const addLine = (cls, label) => {
    const p = document.createElement('div');
    p.className = 'sim-line ' + cls;
    if (label) {
      const l = document.createElement('span');
      l.className = 'sim-line-label';
      l.textContent = label;
      p.appendChild(l);
    }
    const t = document.createElement('p');
    p.appendChild(t);
    log.appendChild(p);
    scrollLog();
    return t;
  };

  const addDeltas = (deltas) => {
    if (!deltas) return;
    const wrap = document.createElement('div');
    wrap.className = 'sim-chips';
    Object.entries(deltas).forEach(([k, v]) => {
      if (!v) return;
      const good = k === 'techDebt' ? v < 0 : v > 0;
      const c = document.createElement('span');
      c.className = 'sim-chip ' + (good ? 'up' : 'down');
      c.textContent = (v > 0 ? '+' : '') + v + ' ' + LABELS[k];
      wrap.appendChild(c);
    });
    if (wrap.children.length) { log.appendChild(wrap); scrollLog(); }
  };

  const renderState = (s, deltas) => {
    if (!s) return;
    $('sim-week').textContent = Math.min(s.week, s.weeks);
    $('sim-weeks').textContent = s.weeks;
    $('sim-week-bar').style.width = (Math.min(s.week - (s.done ? 0 : 1), s.weeks) / s.weeks * 100) + '%';
    $('sim-title').textContent = 'pm-simulator — ' + s.product.toLowerCase();
    document.querySelectorAll('#sim-meters li').forEach((li) => {
      const k = li.dataset.m;
      const v = s.metrics[k];
      li.querySelector('b').textContent = v;
      li.querySelector('.sim-meter i').style.width = v + '%';
      const d = deltas ? deltas[k] : 0;
      const em = li.querySelector('.sim-delta');
      if (d) {
        const good = k === 'techDebt' ? d < 0 : d > 0;
        em.textContent = (d > 0 ? '+' : '') + d;
        em.className = 'sim-delta show ' + (good ? 'up' : 'down');
        setTimeout(() => { em.className = 'sim-delta'; }, 2600);
      }
    });
    if (typeof s.score === 'number') {
      const target = s.target || 70;
      const status = s.score >= target ? 'track' : (s.score >= target - 10 ? 'close' : 'risk');
      const perf = $('sim-perf');
      const prev = Number($('sim-score').textContent);
      perf.dataset.status = status;
      $('sim-score').textContent = s.score;
      $('sim-target').textContent = target;
      $('sim-perf-status').textContent = s.done
        ? (s.score >= target ? 'Renewed' : 'Not renewed')
        : ({ track: 'On track', close: 'Close', risk: 'At risk' })[status];
      $('sim-perf-fill').style.width = s.score + '%';
      $('sim-perf-mark').style.left = target + '%';
      if (deltas && prev !== s.score) {
        perf.classList.remove('pulse-up', 'pulse-down'); void perf.offsetWidth;
        perf.classList.add(s.score > prev ? 'pulse-up' : 'pulse-down');
      }
    }
    const h = $('sim-hidden');
    h.classList.toggle('found', !!s.hiddenFound);
    $('sim-hidden-status').textContent = s.hiddenFound ? 'Found it!' : (s.clues > 0 ? 'Getting warmer…' : 'Not found yet');
    $('sim-hidden-hint').textContent = s.hiddenFound ? 'A big boost to business value.' : 'Ask questions. Follow the data.';
  };

  const renderSuggestions = (list) => {
    sugg.innerHTML = '';
    (list || []).forEach((s) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'sim-suggestion';
      b.textContent = s;
      b.addEventListener('click', () => { if (!busy) { input.value = s; submit(); } });
      sugg.appendChild(b);
    });
  };

  const showEvent = async (event, week) => {
    if (!event) return;
    await typeInto(addLine('event', 'Week ' + week), event);
  };

  // ---- Sharing ----
  const GAME_URL = 'https://benlindsay.net/play.html';
  let currentProduct = '';
  const shareText = (r) => {
    const where = currentProduct ? ' at ' + currentProduct : '';
    const lead = r.won
      ? `I earned a new contract${where} in the Product Manager Simulator: ${r.score}/100.`
      : (r.score >= r.target - 10
        ? `I came within ${r.target - r.score} point${r.target - r.score === 1 ? '' : 's'} of a contract renewal${where} in the Product Manager Simulator: ${r.score}/100.`
        : `My contract${where} wasn't renewed in the Product Manager Simulator: ${r.score}/100.`);
    const title = r.title ? ` My review called me "${r.title}".` : '';
    return `${lead}${title} Can you do better in 8 weeks?`;
  };
  const toast = (msg) => {
    const t = $('share-toast');
    t.textContent = msg;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.textContent = ''; }, 3500);
  };
  const copyText = async (text) => {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) { /* fall back below */ }
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  };
  let lastShare = '';
  $('share-copy').addEventListener('click', async () => {
    toast(await copyText(lastShare + ' ' + GAME_URL) ? 'Copied! Paste it anywhere.' : 'Select the text above to copy it.');
  });
  $('share-linkedin').addEventListener('click', async () => {
    // LinkedIn only accepts a link, so put the result text on the clipboard to paste into the post
    const copied = await copyText(lastShare + ' ' + GAME_URL);
    window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(GAME_URL), '_blank', 'noopener,width=620,height=680');
    toast(copied ? 'Your result is copied. Paste it into the LinkedIn post.' : 'Opening LinkedIn…');
  });
  if (navigator.share) {
    $('share-native').hidden = false;
    $('share-native').addEventListener('click', () => {
      navigator.share({ title: 'Product Manager Simulator', text: lastShare, url: GAME_URL }).catch(() => {});
    });
  }

  const deltaText = (deltas) => Object.entries(deltas || {})
    .filter(([, v]) => v)
    .map(([k, v]) => (v > 0 ? '+' : '') + v + ' ' + LABELS[k])
    .join(' · ');

  const renderReview = (r, finalNarration, finalDeltas) => {
    $('rv-final').textContent = finalNarration || '';
    $('rv-final-deltas').textContent = deltaText(finalDeltas);
    $('rv-final-wrap').hidden = !finalNarration;
    lastShare = shareText(r);
    $('share-text').textContent = lastShare;
    $('rv-outcome').textContent = r.won ? 'You earned a new contract!' : 'Your contract was not renewed.';
    $('rv-rating').textContent = r.rating;
    $('rv-target').textContent = 'Renewal target: ' + r.target;
    $('rv-title').textContent = r.title;
    $('rv-score').textContent = r.score;
    $('rv-strengths').textContent = r.strengths;
    $('rv-growth').textContent = r.growth;
    $('rv-hidden-wrap').hidden = !r.hidden;
    $('rv-hidden').textContent = r.hidden || '';
    $('rv-watch-wrap').hidden = !r.watch;
    if (r.watch) {
      $('rv-watch-reason').textContent = r.watch.reason;
      const link = $('rv-watch');
      link.href = /^https:\/\/www\.youtube\.com\/watch\?v=[A-Za-z0-9_-]{11}$/.test(r.watch.url) ? r.watch.url : 'https://www.youtube.com/@pmknowledgebase';
      link.textContent = 'Watch: ' + (r.watch.book || r.watch.title) + ' in minutes →';
    }
    $('sim-end').dataset.rating = r.won ? 'high' : 'low';
  };

  const newBtn = $('sim-new');
  const newGo = $('sim-new-go');
  const start = async () => {
    if (busy || newBtn.disabled) return;
    showError('');
    newBtn.disabled = true;
    newGo.textContent = 'Writing your scenario…';
    let data;
    try { data = await call({ action: 'start' }); } catch (e) { data = { ok: false, message: 'Connection lost. Please try again.' }; }
    newBtn.disabled = false;
    newGo.textContent = 'Start →';
    if (!data.ok) { alert(data.message || 'Something went wrong. Please try again.'); return; }
    log.innerHTML = '';
    $('sim-mode').hidden = !data.mock;
    show('game');
    currentProduct = data.state.product || '';
    form.hidden = false;
    renderState(data.state);
    renderSuggestions([]);
    screens.game.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    await showEvent(data.event, data.state.week);
    renderSuggestions(data.suggestions);
    input.focus({ preventScroll: true });
  };

  const submit = async () => {
    const text = input.value.trim();
    if (!text || busy) return;
    showError('');
    addLine('player').textContent = text;
    input.value = '';
    renderSuggestions([]);
    setBusy(true);
    let data;
    try { data = await call({ action: 'turn', text }); } catch (e) { data = { ok: false, message: 'Connection lost. Please try again.' }; }
    setBusy(false);

    if (!data.ok) {
      showError(data.message || 'Something went wrong. Please try again.');
      if (data.restart) renderSuggestions([]);
      input.focus({ preventScroll: true });
      return;
    }

    await typeInto(addLine(data.offTopic ? 'aside' : 'outcome'), data.narration);
    if (!data.offTopic) {
      addDeltas(data.deltas);
      renderState(data.state, data.deltas);
      if (data.found) addLine('found').textContent = 'You uncovered the hidden value! Business value and stakeholder trust jump.';
    }

    if (data.review) {
      // Final week: let the player read the outcome, then continue when ready
      renderReview(data.review, data.narration, data.deltas);
      addLine('event', 'End of week ' + data.state.weeks).textContent = 'Your contract is up. Leadership has finished your performance review.';
      form.hidden = true;
      sugg.innerHTML = '';
      const go = document.createElement('button');
      go.type = 'button';
      go.className = 'btn btn-primary sim-review-go';
      go.innerHTML = 'See your performance review <span class="arrow" aria-hidden="true">→</span>';
      go.addEventListener('click', () => {
        show('end');
        screens.end.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
      });
      sugg.appendChild(go);
      scrollLog();
      go.focus({ preventScroll: true });
      return;
    }
    if (!data.offTopic) await showEvent(data.event, data.state.week);
    renderSuggestions(data.suggestions);
    input.focus({ preventScroll: true });
  };

  newBtn.addEventListener('click', start);
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  $('sim-again').addEventListener('click', () => {
    show('start');
    screens.start.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
  });
})();
