<?php
/**
 * Private admin page for the Product Manager Simulator's video library.
 * Included by public_html/api/pmsim-admin.php. Protected by the password
 * in config.php ('admin_password'); switched off while that is empty.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use PmSim\Knowledge;
use const PmSim\AREAS;
use const PmSim\CARD_TYPES;

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; form-action 'self'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow');

$config = pmsim_config();
$password = (string)($config['admin_password'] ?? '');
if (strlen($password) < 10) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

session_name('pmsim_admin');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/api/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, 'samesite' => 'Strict',
]);
session_start();

/* ---------------- Hidden entrance ----------------
 * The page answers "Not found" unless the visitor first pressed the secret
 * key sequence on the site (js/main.js calls ?unlock), which opens it for
 * 10 minutes. This only hides the page; the password is the real lock.
 */
if (isset($_GET['unlock'])) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $okOrigin = $origin === '' || in_array($origin, (array)($config['allowed_origins'] ?? []), true);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_PMSIM_GATE'] ?? '') === '1' && $okOrigin) {
        $_SESSION['gate'] = time();
        header('Content-Type: application/json');
        echo '{"ok":true}';
        exit;
    }
    http_response_code(404);
    echo 'Not found.';
    exit;
}
if (empty($_SESSION['admin']) && (int)($_SESSION['gate'] ?? 0) < time() - 600) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$kb = pmsim_knowledge();
$self = strtok($_SERVER['REQUEST_URI'] ?? 'pmsim-admin.php', '?');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function admin_redirect(string $to, ?string $flash = null): void
{
    if ($flash !== null) $_SESSION['flash'] = $flash;
    header('Location: ' . $to, true, 303);
    exit;
}

/* ---------------- Login, with lockout after repeated failures ---------------- */
$lockFile = __DIR__ . '/data/admin-lock-' . pmsim_ip_key() . '.json';
$lock = is_file($lockFile) ? (json_decode((string)file_get_contents($lockFile), true) ?: []) : [];
$fails = array_values(array_filter($lock['fails'] ?? [], fn($t) => $t > time() - 900));
$locked = count($fails) >= 5;

if (($_POST['do'] ?? '') === 'login') {
    if ($locked) admin_redirect($self, 'Too many attempts. Try again in 15 minutes.');
    if (hash_equals($password, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        @unlink($lockFile);
        admin_redirect($self);
    }
    $fails[] = time();
    @file_put_contents($lockFile, json_encode(['fails' => $fails]), LOCK_EX);
    usleep(800000);
    admin_redirect($self, 'Wrong password.');
}

$authed = !empty($_SESSION['admin']);

/* ---------------- Actions (logged in, CSRF-checked) ---------------- */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) admin_redirect($self, 'Your session expired. Please try again.');
    $do = $_POST['do'] ?? '';

    if ($do === 'logout') {
        $_SESSION = [];
        session_destroy();
        admin_redirect($self);
    }

    if ($do === 'build') {
        $videoId = Knowledge::videoIdFromUrl((string)($_POST['video'] ?? ''));
        $meta = [
            'videoId' => (string)$videoId,
            'title'   => trim((string)($_POST['title'] ?? '')),
            'book'    => trim((string)($_POST['book'] ?? '')),
            'author'  => trim((string)($_POST['author'] ?? '')),
        ];
        $transcript = (string)($_POST['transcript'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) && $_FILES['file']['size'] < 1_000_000) {
            $transcript .= "\n" . (string)file_get_contents($_FILES['file']['tmp_name']);
        }
        if (!$videoId) admin_redirect($self, 'That doesn\'t look like a YouTube link or video ID.');
        if ($meta['title'] === '' || $meta['book'] === '') admin_redirect($self, 'Add the video title and the book title.');
        $result = Knowledge::build($config, $meta, $transcript);
        if (!$result['ok']) admin_redirect($self, $result['error']);
        $kb->saveDraft(['meta' => $meta, 'cards' => $result['cards'], 'cost' => $result['cost'] ?? 0, 'mock' => !empty($result['mock']), 'built' => gmdate('c')]);
        admin_redirect($self . '?review=' . urlencode($meta['videoId']));
    }

    if ($do === 'approve') {
        $videoId = (string)($_POST['videoId'] ?? '');
        $draft = $kb->draft($videoId);
        if (!$draft) admin_redirect($self, 'Draft not found.');
        $cards = [];
        foreach ((array)($_POST['cards'] ?? []) as $c) {
            if (empty($c['include'])) continue;
            $cards[] = $c;
        }
        $cards = Knowledge::normalize($cards, $draft['meta']);
        $n = $kb->approve($videoId, $cards);
        admin_redirect($self, "Approved {$n} cards for \"{$draft['meta']['title']}\". They're live in the game now.");
    }

    if ($do === 'discard') {
        @unlink(__DIR__ . '/knowledge/drafts/' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['videoId'] ?? '')) . '.json');
        admin_redirect($self, 'Draft discarded.');
    }

    if ($do === 'remove') {
        $kb->removeVideo((string)($_POST['videoId'] ?? ''));
        admin_redirect($self, 'Removed that video\'s cards from the game.');
    }
}

/* ---------------- Views ---------------- */
$gameLog = $authed && isset($_GET['game']) ? pmsim_game()->readLog((string)$_GET['game']) : null;
$review = $authed && isset($_GET['review']) ? $kb->draft((string)$_GET['review']) : null;
$drafts = [];
if ($authed) {
    foreach (glob(__DIR__ . '/knowledge/drafts/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) $drafts[] = $d['meta'];
    }
}
$usage = $authed ? pmsim_game()->todayUsage() : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Simulator admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --bg:#0b0d10; --surface:#13171d; --alt:#101318; --text:#eceef2; --muted:#8d95a3; --border:#22272f; --accent:#7aa2ff; --good:#5fd38d; --bad:#ff8a7a; color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.6 Inter, system-ui, sans-serif; }
  main { max-width: 980px; margin: 0 auto; padding: 32px 16px 80px; }
  h1 { font-size: 1.8rem; margin: 0 0 4px; } h2 { font-size: 1.2rem; margin: 36px 0 12px; }
  .mono, label, th { font-family: "JetBrains Mono", monospace; font-size: .75rem; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
  .muted { color: var(--muted); }
  .box { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
  .flash { border-color: var(--accent); margin: 16px 0; }
  input[type=text], input[type=password], input[type=url], textarea, select { width: 100%; font: inherit; color: var(--text); background: var(--alt); border: 1px solid var(--border); border-radius: 8px; padding: 9px 11px; }
  textarea { min-height: 80px; resize: vertical; }
  textarea.big { min-height: 260px; font-family: "JetBrains Mono", monospace; font-size: .85rem; }
  label { display: block; margin: 14px 0 6px; }
  .row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0 16px; }
  button, .btn { font: inherit; font-weight: 600; border-radius: 999px; padding: 9px 18px; border: 1px solid var(--border); background: transparent; color: var(--text); cursor: pointer; text-decoration: none; display: inline-block; }
  .primary { background: var(--accent); color: #0b0d10; border-color: var(--accent); }
  .danger { color: var(--bad); }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; align-items: center; }
  table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
  .card { border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin: 14px 0; background: var(--surface); }
  .card.off { opacity: .45; }
  .card-head { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
  .tag { font-family: "JetBrains Mono", monospace; font-size: .7rem; text-transform: uppercase; border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; color: var(--accent); }
  .include { display: flex; align-items: center; gap: 8px; margin: 0; text-transform: none; font-family: Inter, sans-serif; font-size: .9rem; color: var(--text); letter-spacing: 0; }
  header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
  .loading { display: none; margin-top: 12px; color: var(--accent); }
  form.busy .loading { display: block; }
  .turn { border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin: 14px 0; background: var(--surface); }
  .turn.off { border-style: dashed; }
  .who { font-family: "JetBrains Mono", monospace; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 10px 0 2px; }
  .player { color: var(--accent); font-weight: 600; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
  .chip { font-family: "JetBrains Mono", monospace; font-size: .72rem; border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px; }
  .up { color: var(--good); } .down { color: var(--bad); }
  .scroll { overflow-x: auto; }
</style>
</head>
<body>
<main>
<?php if ($flash): ?><div class="box flash"><?= $h($flash) ?></div><?php endif; ?>

<?php if (!$authed): ?>
  <h1>Simulator admin</h1>
  <p class="muted">Private page for managing the Product Manager Simulator's video library.</p>
  <form method="post" class="box" style="max-width:420px">
    <input type="hidden" name="do" value="login">
    <label for="pw">Password</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" required <?= $locked ? 'disabled' : '' ?>>
    <div class="actions"><button class="primary" <?= $locked ? 'disabled' : '' ?>>Sign in</button></div>
    <?php if ($locked): ?><p class="muted">Too many attempts. Try again in 15 minutes.</p><?php endif; ?>
  </form>

<?php elseif ($gameLog): $g = $gameLog; $res = $g['result'] ?? null;
  $labels = ['customers' => 'Customers', 'team' => 'Team', 'stakeholders' => 'Stakeholders', 'business' => 'Business', 'techDebt' => 'Tech debt']; ?>
  <header>
    <div><p class="mono">Game log · <?= $h(str_replace('T', ' ', substr((string)($g['started'] ?? ''), 0, 16))) ?> UTC · <?= $g['source'] === 'ai' ? 'AI scenario' : 'Built-in scenario' ?></p>
      <h1><?= $h($g['product']) ?></h1><p class="muted"><?= $h(ucfirst((string)$g['pitch'])) ?></p></div>
    <a class="btn" href="<?= $h($self) ?>">← Back</a>
  </header>
  <div class="box">
    <?php if (!empty($g['ingredients'])): ?><p class="mono">Ingredients</p><p><?= $h(implode(' · ', $g['ingredients'])) ?></p><?php endif; ?>
    <p class="mono">Secret opportunity</p><p><?= $h($g['hidden']) ?></p>
    <p class="mono">Result</p>
    <p><?= $res ? $h($res['rating']) . ' · score ' . (int)$res['score'] . ($res['hiddenFound'] ? ' · found the secret' : ' · secret not found') : 'Unfinished' ?> (started at <?= (int)($g['startScore'] ?? 0) ?>)</p>
  </div>
  <div class="turn"><p class="who">Week 1 situation</p><p><?= $h($g['opening']) ?></p><p class="muted">Suggested: <?= $h(implode(' · ', $g['suggestions'] ?? [])) ?></p></div>
  <?php foreach ($g['turns'] ?? [] as $t): ?>
    <div class="turn<?= !empty($t['offTopic']) ? ' off' : '' ?>">
      <p class="who">Week <?= (int)$t['week'] ?> · player<?= !empty($t['offTopic']) ? ' · off-topic, week not used' : '' ?></p>
      <p class="player"><?= $h($t['action']) ?></p>
      <p class="who">Game master</p><p><?= $h($t['narration']) ?></p>
      <?php if (empty($t['offTopic'])): ?>
        <div class="chips">
          <?php foreach ($labels as $k => $label): $d = (int)($t['deltas'][$k] ?? 0); $good = $k === 'techDebt' ? $d < 0 : $d > 0; ?>
            <span class="chip <?= $d === 0 ? '' : ($good ? 'up' : 'down') ?>"><?= $h($label) ?> <?= $d > 0 ? '+' : '' ?><?= $d ?></span>
          <?php endforeach; ?>
          <span class="chip">Score <?= (int)$t['score'] ?></span>
          <?php if (!empty($t['clue'])): ?><span class="chip up">Clue given</span><?php endif; ?>
          <?php if (!empty($t['found'])): ?><span class="chip up">Secret found</span><?php endif; ?>
        </div>
        <?php if (!empty($t['cards'])): ?><p class="muted" style="margin-top:10px">Knowledge used: <?= $h(implode(' · ', $t['cards'])) ?></p><?php endif; ?>
        <?php if (!empty($t['nextEvent'])): ?><p class="who">Next week's situation</p><p><?= $h($t['nextEvent']) ?></p><p class="muted">Suggested: <?= $h(implode(' · ', $t['suggestions'] ?? [])) ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($res): ?>
    <div class="box"><p class="mono">Performance review</p><h2 style="margin-top:4px"><?= $h($res['title']) ?></h2>
      <p><strong>Strengths:</strong> <?= $h($res['strengths']) ?></p><p><strong>Room to grow:</strong> <?= $h($res['growth']) ?></p></div>
  <?php endif; ?>

<?php elseif ($review): $m = $review['meta']; ?>
  <header>
    <div><p class="mono">Review cards</p><h1><?= $h($m['title']) ?></h1>
      <p class="muted"><?= $h($m['book']) ?><?= $m['author'] ? ' by ' . $h($m['author']) : '' ?> · <a href="<?= $h(Knowledge::watchUrl($m['videoId'])) ?>" target="_blank" rel="noopener" style="color:var(--accent)">Watch</a>
      <?= !empty($review['mock']) ? ' · <strong>Demo cards (no API key set)</strong>' : ' · Build cost about $' . $h(number_format((float)$review['cost'], 3)) ?></p></div>
    <a class="btn" href="<?= $h($self) ?>">← Back</a>
  </header>
  <p>Edit anything that isn't quite right. Untick cards you don't want. Only approved cards reach players, and approving replaces any earlier cards for this video.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <input type="hidden" name="videoId" value="<?= $h($m['videoId']) ?>">
    <?php foreach ($review['cards'] as $i => $c): ?>
      <div class="card">
        <div class="card-head">
          <span class="tag"><?= $h($c['type']) ?> · <?= $h($c['area']) ?></span>
          <label class="include"><input type="checkbox" name="cards[<?= $i ?>][include]" value="1" checked> Include</label>
        </div>
        <div class="row">
          <div><label>Type</label><select name="cards[<?= $i ?>][type]"><?php foreach (CARD_TYPES as $t): ?><option <?= $t === $c['type'] ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></div>
          <div><label>Area</label><select name="cards[<?= $i ?>][area]"><?php foreach (AREAS as $a): ?><option <?= $a === $c['area'] ? 'selected' : '' ?>><?= $h($a) ?></option><?php endforeach; ?></select></div>
        </div>
        <label>Title</label><input type="text" name="cards[<?= $i ?>][title]" value="<?= $h($c['title']) ?>">
        <label>Summary</label><textarea name="cards[<?= $i ?>][summary]"><?= $h($c['summary']) ?></textarea>
        <div class="row">
          <div><label>Good looks like</label><textarea name="cards[<?= $i ?>][good]"><?= $h($c['good']) ?></textarea></div>
          <div><label>Traps</label><textarea name="cards[<?= $i ?>][traps]"><?= $h($c['traps']) ?></textarea></div>
        </div>
        <label>Keywords (comma-separated)</label><input type="text" name="cards[<?= $i ?>][keywords]" value="<?= $h(implode(', ', $c['keywords'])) ?>">
      </div>
    <?php endforeach; ?>
    <div class="actions">
      <button class="primary" name="do" value="approve">Approve and publish</button>
      <button class="danger" name="do" value="discard" formnovalidate>Discard draft</button>
    </div>
  </form>

<?php else: ?>
  <header>
    <div><p class="mono">Product Manager Simulator</p><h1>Video library</h1></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><button name="do" value="logout">Sign out</button></form>
  </header>
  <p class="muted">Today: <?= (int)($usage['calls'] ?? 0) ?> game turns, about $<?= $h(number_format((float)($usage['cost'] ?? 0), 3)) ?> spent (cap $<?= $h(number_format((float)($config['daily_budget_usd'] ?? 2), 2)) ?>). Mode: <?= pmsim_game()->isMock() ? 'demo (no API key)' : 'live' ?>.</p>

  <h2>Add a video</h2>
  <form method="post" enctype="multipart/form-data" class="box" onsubmit="this.classList.add('busy'); this.querySelector('button').disabled = true;">
    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
    <input type="hidden" name="do" value="build">
    <div class="row">
      <div><label for="v">YouTube link</label><input id="v" type="text" name="video" placeholder="https://www.youtube.com/watch?v=..." required></div>
      <div><label for="t">Video title</label><input id="t" type="text" name="title" placeholder="How Top Tech Companies Build Products Customers Love" required></div>
    </div>
    <div class="row">
      <div><label for="b">Book</label><input id="b" type="text" name="book" placeholder="Inspired" required></div>
      <div><label for="a">Author</label><input id="a" type="text" name="author" placeholder="Marty Cagan"></div>
    </div>
    <label for="tr">Transcript or script</label>
    <textarea id="tr" class="big" name="transcript" placeholder="Paste the transcript from YouTube Studio (Subtitles → your video → Download), or your script."></textarea>
    <label for="f">…or upload a transcript file (.txt, .srt or .vtt)</label>
    <input id="f" type="file" name="file" accept=".txt,.srt,.vtt,text/plain">
    <div class="actions"><button class="primary">Build cards</button><span class="muted">Takes up to a minute or two.</span></div>
    <p class="loading">Reading the transcript and writing cards… please keep this page open.</p>
  </form>

  <?php if ($drafts): ?>
    <h2>Waiting for review</h2>
    <table><tr><th>Video</th><th>Book</th><th></th></tr>
    <?php foreach ($drafts as $d): ?>
      <tr><td><?= $h($d['title']) ?></td><td><?= $h($d['book']) ?></td><td><a class="btn" href="<?= $h($self . '?review=' . urlencode($d['videoId'])) ?>">Review</a></td></tr>
    <?php endforeach; ?></table>
  <?php endif; ?>

  <h2>In the game</h2>
  <?php $videos = $kb->videos(); if (!$videos): ?>
    <p class="muted">No videos yet. Add your first one above.</p>
  <?php else: ?>
    <table><tr><th>Video</th><th>Book</th><th>Cards</th><th></th></tr>
    <?php foreach ($videos as $v): ?>
      <tr>
        <td><a href="<?= $h($v['url']) ?>" target="_blank" rel="noopener" style="color:var(--accent)"><?= $h($v['title']) ?></a></td>
        <td><?= $h($v['book']) ?></td><td><?= (int)$v['count'] ?></td>
        <td><form method="post" onsubmit="return confirm('Remove this video\'s cards from the game?')"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="videoId" value="<?= $h($v['videoId']) ?>"><button class="danger" name="do" value="remove">Remove</button></form></td>
      </tr>
    <?php endforeach; ?></table>
  <?php endif; ?>

  <h2>Recent games</h2>
  <?php $games = pmsim_game()->recentLogs(100); if (!$games): ?>
    <p class="muted">No games logged yet. Games are kept for 90 days.</p>
  <?php else: ?>
    <p class="muted">The last <?= count($games) ?> games, newest first. Logs hold no IP addresses and are deleted after 90 days.</p>
    <div class="scroll"><table><tr><th>Started (UTC)</th><th>Product</th><th>Weeks</th><th>Score</th><th>Outcome</th><th></th></tr>
    <?php foreach ($games as $gm): ?>
      <tr><td><?= $h(str_replace('T', ' ', substr((string)$gm['started'], 0, 16))) ?></td><td><?= $h($gm['product']) ?></td><td><?= (int)$gm['weeks'] ?></td>
        <td><?= $gm['score'] === null ? '–' : (int)$gm['score'] ?></td><td><?= $h($gm['outcome']) ?><?= $gm['found'] ? ' ★' : '' ?></td>
        <td><a class="btn" href="<?= $h($self . '?game=' . urlencode($gm['id'])) ?>">View</a></td></tr>
    <?php endforeach; ?></table></div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
