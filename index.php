<?php
// index.php — Notes (SQLite + Markdown) with CRUD, panels, theme, CSRF, exports
declare(strict_types=1);
session_start();

require_once __DIR__ . '/db.php';

/* ------------------------- Helpers ------------------------- */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

function check_csrf(): void {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(403);
        die('Invalid CSRF token');
    }
}
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function baseurl(): string { return e(strtok($_SERVER['REQUEST_URI'], '?')); }
function redirect_self(): void { header('Location: ' . baseurl()); exit; }

/**
 * Minimal safe Markdown → HTML:
 * - Escapes HTML, supports headings, bold, italic, inline code, fenced code, lists, links, paragraphs & <br>.
 * - Good for notes; swap with Parsedown/CommonMark later if you need full spec.
 */
function md_to_html(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Fenced code blocks first (```lang ... ```)
    $blocks = [];
    $text = preg_replace_callback('/```([a-z0-9+\-_]*)\n(.*?)\n```/is', function($m) use (&$blocks) {
        $lang = strtolower(trim($m[1] ?? ''));
        $code = $m[2] ?? '';
        $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $idx = count($blocks);
        $blocks[$idx] = '<pre><code'.($lang ? ' class="lang-'.htmlspecialchars($lang, ENT_QUOTES).'"' : '').'>'.$safe.'</code></pre>';
        return "§BLOCK{$idx}§";
    }, $text);

    // Escape everything else
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Headings
    for ($i=6; $i>=1; $i--) {
        $text = preg_replace('/^' . str_repeat('#', $i) . '\s+(.*)$/m', '<h'.$i.'>$1</h'.$i.'>', $text);
    }

    // Lists
    $lines = explode("\n", $text);
    $out = [];
    $inList = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $mm)) {
            if (!$inList) { $out[] = '<ul>'; $inList = true; }
            $out[] = '<li>' . $mm[1] . '</li>';
        } else {
            if ($inList) { $out[] = '</ul>'; $inList = false; }
            $out[] = $line;
        }
    }
    if ($inList) { $out[] = '</ul>'; }
    $text = implode("\n", $out);

    // Inline styles
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);                    // **bold**
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);      // *italic*
    $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);                           // `code`
    $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text); // [txt](url)

    // Paragraphs & <br>
    $parts = preg_split("/\n{2,}/", trim($text));
    $final = [];
    foreach ($parts as $p) {
        if (preg_match('/^\s*<(h[1-6]|ul|pre|blockquote)/i', $p)) {
            $final[] = $p;
        } else {
            $final[] = '<p>' . preg_replace("/\n/", "<br>", $p) . '</p>';
        }
    }
    $text = implode("\n", $final);

    // Restore code blocks
    $text = preg_replace_callback('/§BLOCK(\d+)§/', function($m) use ($blocks) {
        return $blocks[(int)$m[1]] ?? '';
    }, $text);

    return $text;
}

/* ------------------------- Actions ------------------------- */
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $content = trim($_POST['content'] ?? '');
            if ($content === '') throw new RuntimeException('Note content is required.');
            $stmt = $pdo->prepare("INSERT INTO notes (content) VALUES (:c)");
            $stmt->execute([':c' => $content]);
            $_SESSION['toast'] = ['type'=>'ok','msg'=>'Note created'];
            redirect_self();
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            if ($id <= 0 || $content === '') throw new RuntimeException('Invalid update data.');
            $stmt = $pdo->prepare("UPDATE notes SET content = :c WHERE id = :id");
            $stmt->execute([':c' => $content, ':id' => $id]);
            $_SESSION['toast'] = ['type'=>'ok','msg'=>"Note #$id updated"];
            redirect_self();
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid note id.');
            $stmt = $pdo->prepare("DELETE FROM notes WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $_SESSION['toast'] = ['type'=>'ok','msg'=>"Note #$id deleted"];
            redirect_self();
        }
    }
} catch (Throwable $e) {
    $_SESSION['toast'] = ['type'=>'err','msg'=>$e->getMessage()];
    redirect_self();
}

/* ------------------------- Read (search + pagination) ------------------------- */
$q     = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 9;
$offset = ($page - 1) * $limit;

if ($q !== '') {
    $stmt = $pdo->prepare("SELECT id, content, created_at FROM notes
                           WHERE content LIKE :q
                           ORDER BY created_at DESC
                           LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':q', '%'.$q.'%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit+1, PDO::PARAM_INT); // +1 to detect next page
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT id, content, created_at FROM notes
                           ORDER BY created_at DESC
                           LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit+1, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$rows = $stmt->fetchAll();
$hasNext = count($rows) > $limit;
if ($hasNext) array_pop($rows);
$hasPrev = $page > 1;

// One-time toast
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Notes — SQLite + Markdown</title>
  <link rel="stylesheet" href="styles.css"/>
  <link rel="icon" href="data:,">
</head>
<body>

<header class="topbar">
  <a class="brand" href="<?= baseurl() ?>">
    <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="currentColor" opacity=".2"/><path d="M14 2v5h5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
    <span>Notes</span>
  </a>

  <form class="search" method="get">
    <input name="q" placeholder="Search Markdown content…" value="<?= e($q) ?>">
    <button type="submit" class="btn ghost" title="Search">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn tiny ghost" href="<?= baseurl() ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="actions">
    <button type="button" id="themeToggle" class="btn ghost" title="Toggle theme">🌗</button>
    <button type="button" class="btn primary" data-panel="create">+ New</button>
  </div>
</header>

<main class="container">
  <?php if (empty($rows)): ?>
    <section class="empty">
      <div class="empty-emoji">📝</div>
      <h2>No notes yet</h2>
      <p>Create your first note or adjust your search.</p>
      <button type="button" class="btn primary" data-panel="create">Create Note</button>
    </section>
  <?php else: ?>
    <?php if ($q !== ''): ?>
      <div class="search-chip">Search: <strong><?= e($q) ?></strong></div>
    <?php endif; ?>

    <section class="grid">
      <?php foreach ($rows as $note): ?>
        <?php $html = md_to_html($note['content']); ?>
        <article class="card">
          <div class="card-body">
            <div class="meta small"><?= e($note['created_at']) ?></div>
            <div class="note">
              <?= $html ?>
            </div>
          </div>
          <div class="card-actions">
            <!-- Export buttons -->
            <a class="btn ghost tiny" href="export.php?id=<?= (int)$note['id'] ?>&format=pdf" target="_blank" title="Export as PDF">📄 PDF</a>
            <a class="btn ghost tiny" href="export.php?id=<?= (int)$note['id'] ?>&format=html" title="Export as HTML">🌐 HTML</a>
            <a class="btn ghost tiny" href="export.php?id=<?= (int)$note['id'] ?>&format=md" title="Export as Markdown">📝 MD</a>
            <a class="btn ghost tiny" href="export.php?id=<?= (int)$note['id'] ?>&format=txt" title="Export as Plain Text">📃 TXT</a>

            <!-- Edit/Delete -->
            <button type="button" class="btn ghost" title="Edit"
              data-panel="edit"
              data-id="<?= (int)$note['id'] ?>"
              data-content="<?= e($note['content']) ?>">✏️ Edit</button>

            <form method="post" onsubmit="return confirm('Delete this note?');">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$note['id'] ?>">
              <button class="btn ghost danger" title="Delete">🗑️ Delete</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <nav class="pager">
      <a class="btn ghost <?= $hasPrev?'':'disabled' ?>" href="?<?= http_build_query(['q'=>$q,'page'=>$page-1]) ?>">‹ Prev</a>
      <span class="page-dot"><?= (int)$page ?></span>
      <a class="btn ghost <?= $hasNext?'':'disabled' ?>" href="?<?= http_build_query(['q'=>$q,'page'=>$page+1]) ?>">Next ›</a>
    </nav>
  <?php endif; ?>
</main>

<!-- Create Panel -->
<aside id="panel-create" class="panel">
  <div class="panel-head">
    <h3>New Note (Markdown)</h3>
    <button type="button" class="btn ghost panel-close">✖</button>
  </div>
  <form class="panel-body" method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <label class="field">
      <span>Content</span>
      <textarea name="content" rows="14" placeholder="# Title
Write **bold**, *italic*, `code`.
- Item 1
- Item 2" required></textarea>
    </label>
    <div class="panel-actions">
      <button class="btn primary">Save</button>
      <button type="button" class="btn" data-close>Cancel</button>
    </div>
  </form>
</aside>

<!-- Edit Panel -->
<aside id="panel-edit" class="panel">
  <div class="panel-head">
    <h3>Edit Note</h3>
    <button type="button" class="btn ghost panel-close">✖</button>
  </div>
  <form class="panel-body" method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="edit-id">
    <label class="field">
      <span>Content (Markdown)</span>
      <textarea name="content" id="edit-content" rows="16" required></textarea>
    </label>
    <div class="panel-actions">
      <button class="btn primary">Update</button>
      <button type="button" class="btn" data-close>Cancel</button>
    </div>
  </form>
</aside>

<!-- Toast -->
<?php if ($toast): ?>
<div id="toast" class="toast <?= e($toast['type']) ?>">
  <div class="toast-body"><?= e($toast['msg']) ?></div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Theme toggle (persist)
  (function(){
    var root = document.documentElement;
    var key = 'pref-theme';
    var btn = document.getElementById('themeToggle');
    function setTheme(t) {
      root.setAttribute('data-theme', t);
      try { localStorage.setItem(key, t); } catch(e) {}
    }
    try { var saved = localStorage.getItem(key); if (saved) setTheme(saved); } catch(e) {}
    if (btn) {
      btn.addEventListener('click', function(){
        var cur = root.getAttribute('data-theme') || 'light';
        setTheme(cur === 'light' ? 'dark' : 'light');
      });
    }
  })();

  // Panels open/close
  function openPanel(id){ var el = document.getElementById(id); if (el) el.classList.add('show'); }
  function closePanels(){ var list = document.querySelectorAll('.panel.show'); for (var i=0;i<list.length;i++){ list[i].classList.remove('show'); } }

  // Event delegation
  document.body.addEventListener('click', function(e){
    if (e.target.closest('.panel-close') || e.target.closest('[data-close]')) { e.preventDefault(); closePanels(); return; }
    var createBtn = e.target.closest('[data-panel="create"]');
    if (createBtn) { e.preventDefault(); openPanel('panel-create'); return; }
    var editBtn = e.target.closest('[data-panel="edit"]');
    if (editBtn) {
      e.preventDefault();
      var id = editBtn.getAttribute('data-id') || '';
      var content = editBtn.getAttribute('data-content') || '';
      var idEl = document.getElementById('edit-id');
      var contentEl = document.getElementById('edit-content');
      if (idEl) idEl.value = id;
      if (contentEl) contentEl.value = content;
      openPanel('panel-edit');
      return;
    }
  });

  // Keyboard: N to open new (when not typing)
  document.addEventListener('keydown', function(e){
    if (e.key && e.key.toLowerCase() === 'n' && !e.target.closest('input,textarea')) {
      e.preventDefault(); openPanel('panel-create');
    }
  });

  // Toast auto-hide
  var toast = document.getElementById('toast');
  if (toast) { setTimeout(function(){ toast.classList.add('hide'); }, 2600); }
});
</script>
</body>
</html>
