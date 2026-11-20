// Hero visual: a slowly turning sphere of data points with orbiting rings.
// Drawn on a <canvas> in plain JavaScript, so there are no libraries or image files to load.
(() => {
  const canvas = document.querySelector('.orb');
  if (!canvas || !canvas.getContext) return;
  const ctx = canvas.getContext('2d');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Points spread evenly over a sphere (Fibonacci lattice)
  const N = 1100;
  const pts = [];
  const golden = Math.PI * (3 - Math.sqrt(5));
  for (let i = 0; i < N; i++) {
    const y = 1 - (i / (N - 1)) * 2;
    const r = Math.sqrt(1 - y * y);
    const th = golden * i;
    pts.push([Math.cos(th) * r, y, Math.sin(th) * r]);
  }

  // Three tilted rings of points, each with a small "satellite"
  const rings = [
    { tiltX: 1.15, tiltZ: 0.35, radius: 1.32, speed: 0.25 },
    { tiltX: -0.6, tiltZ: -0.9, radius: 1.5, speed: -0.18 },
    { tiltX: 0.2, tiltZ: 1.3, radius: 1.68, speed: 0.12 },
  ];
  const RING_PTS = 160;

  let colorA = '122,162,255';
  let colorB = '236,238,242';
  const readColors = () => {
    const cs = getComputedStyle(document.documentElement);
    colorA = cs.getPropertyValue('--orb-rgb').trim() || colorA;
    colorB = cs.getPropertyValue('--orb-rgb-2').trim() || colorB;
  };
  readColors();
  document.addEventListener('themechange', () => { readColors(); if (!running) draw(lastT); });

  let W = 0, H = 0, dpr = 1;
  const resize = () => {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    W = Math.max(1, Math.round(rect.width * dpr));
    H = Math.max(1, Math.round(rect.height * dpr));
    canvas.width = W; canvas.height = H;
    if (!running) draw(lastT);
  };

  // Pointer tilt (eased)
  let tx = 0, ty = 0, cx = 0, cy = 0;
  window.addEventListener('pointermove', (e) => {
    tx = (e.clientY / window.innerHeight - 0.5) * 0.5;
    ty = (e.clientX / window.innerWidth - 0.5) * 0.7;
  }, { passive: true });

  const rotate = (x, y, z, ax, ay) => {
    // rotate around Y, then X
    const cosY = Math.cos(ay), sinY = Math.sin(ay);
    let x1 = x * cosY + z * sinY;
    let z1 = -x * sinY + z * cosY;
    const cosX = Math.cos(ax), sinX = Math.sin(ax);
    const y1 = y * cosX - z1 * sinX;
    z1 = y * sinX + z1 * cosX;
    return [x1, y1, z1];
  };

  let lastT = 0;
  function draw(t) {
    lastT = t;
    const time = t / 1000;
    cx += (tx - cx) * 0.04;
    cy += (ty - cy) * 0.04;
    const ax = -0.35 + cx;
    const ay = time * 0.12 + cy;

    ctx.clearRect(0, 0, W, H);
    const R = Math.min(W, H) * 0.3;
    const ox = W / 2, oy = H / 2;
    const persp = 3.2;

    const plot = (x, y, z, size, alpha, rgb) => {
      const s = persp / (persp - z);
      const px = ox + x * R * s;
      const py = oy + y * R * s;
      const d = size * s * dpr;
      ctx.globalAlpha = alpha;
      ctx.fillStyle = `rgb(${rgb})`;
      ctx.fillRect(px - d / 2, py - d / 2, d, d);
    };

    // Sphere: points ripple gently, like a signal moving through the data
    for (let i = 0; i < N; i++) {
      const p = pts[i];
      const wave = Math.sin(p[0] * 4 + time * 1.1) * Math.sin(p[1] * 3.5 - time * 0.8) * Math.cos(p[2] * 3 + time * 0.6);
      const k = 1 + wave * 0.07;
      const [x, y, z] = rotate(p[0] * k, p[1] * k, p[2] * k, ax, ay);
      const depth = (z + 1) / 2; // 0 = back, 1 = front
      const hot = wave > 0.55;
      plot(x, y, z, 0.9 + depth * 1.5 + (hot ? 0.8 : 0), 0.08 + Math.pow(depth, 1.6) * 0.8, hot ? colorA : (depth > 0.55 ? colorB : colorA));
    }

    // Orbit rings
    rings.forEach((ring, ri) => {
      const cosT = Math.cos(ring.tiltX), sinT = Math.sin(ring.tiltX);
      const cosZ = Math.cos(ring.tiltZ), sinZ = Math.sin(ring.tiltZ);
      const orient = (a) => {
        let x = Math.cos(a) * ring.radius, y = 0, z = Math.sin(a) * ring.radius;
        let y1 = y * cosT - z * sinT; let z1 = y * sinT + z * cosT; y = y1; z = z1;
        let x1 = x * cosZ - y * sinZ; y1 = x * sinZ + y * cosZ; x = x1; y = y1;
        return rotate(x, y, z, ax, ay);
      };
      for (let j = 0; j < RING_PTS; j++) {
        const [x, y, z] = orient((j / RING_PTS) * Math.PI * 2);
        const depth = (z / ring.radius + 1) / 2;
        plot(x, y, z, 0.9, 0.06 + depth * 0.3, colorB);
      }
      const sat = orient(time * ring.speed * Math.PI + ri * 2);
      const depth = (sat[2] / ring.radius + 1) / 2;
      plot(sat[0], sat[1], sat[2], 4 + depth * 2, 0.5 + depth * 0.5, colorA);
    });
    ctx.globalAlpha = 1;
  }

  // Animate only while visible on screen
  let running = false, visible = true, raf = 0;
  const loop = (t) => { draw(t); raf = requestAnimationFrame(loop); };
  const update = () => {
    const should = visible && !document.hidden && !reduceMotion;
    if (should && !running) { running = true; raf = requestAnimationFrame(loop); }
    if (!should && running) { running = false; cancelAnimationFrame(raf); }
  };
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => { visible = e.isIntersecting; update(); }).observe(canvas);
  }
  document.addEventListener('visibilitychange', update);

  if ('ResizeObserver' in window) new ResizeObserver(resize).observe(canvas);
  else window.addEventListener('resize', resize);
  resize();
  if (reduceMotion) draw(4000);
  update();
})();
