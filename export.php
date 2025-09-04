<?php
// export.php — Export a single note as PDF/HTML/MD/TXT
// Usage: export.php?id=123&format=pdf|html|md|txt

declare(strict_types=1);
session_start();

require_once __DIR__ . '/db.php';

// ---------- helpers ----------
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Minimal safe Markdown → HTML (same as index.php) */
function md_to_html(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Fenced code blocks
    $blocks = [];
    $text = preg_replace_callback('/```([a-z0-9+\-_]*)\n(.*?)\n```/is', function($m) use (&$blocks) {
        $lang = strtolower(trim($m[1] ?? ''));
        $code = $m[2] ?? '';
        $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $idx = count($blocks);
        $blocks[$idx] = '<pre><code'.($lang ? ' class="lang-'.htmlspecialchars($lang, ENT_QUOTES).'"' : '').'>'.$safe.'</code></pre>';
        return "§BLOCK{$idx}§";
    }, $text);

    // Escape rest
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Headings
    for ($i=6; $i>=1; $i--) {
        $text = preg_replace('/^' . str_repeat('#', $i) . '\s+(.*)$/m', '<h'.$i.'>$1</h'.$i.'>', $text);
    }

    // Lists (- or *)
    $lines = explode("\n", $text);
    $out = []; $inList = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $mm)) {
            if (!$inList) { $out[] = '<ul>'; $inList = true; }
            $out[] = '<li>'.$mm[1].'</li>';
        } else {
            if ($inList) { $out[] = '</ul>'; $inList = false; }
            $out[] = $line;
        }
    }
    if ($inList) { $out[] = '</ul>'; }
    $text = implode("\n", $out);

    // Inline
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

    // Paragraphs & <br>
    $parts = preg_split("/\n{2,}/", trim($text));
    $final = [];
    foreach ($parts as $p) {
        if (preg_match('/^\s*<(h[1-6]|ul|pre|blockquote)/i', $p)) $final[] = $p;
        else $final[] = '<p>'.preg_replace("/\n/", "<br>", $p).'</p>';
    }
    $text = implode("\n", $final);

    // Restore code blocks
    $text = preg_replace_callback('/§BLOCK(\d+)§/', function($m) use ($blocks) {
        return $blocks[(int)$m[1]] ?? '';
    }, $text);

    return $text;
}

// ---------- input ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$format = strtolower($_GET['format'] ?? 'pdf');

if ($id <= 0) {
    http_response_code(400);
    echo "Missing or invalid ?id";
    exit;
}

// ---------- fetch note ----------
$stmt = $pdo->prepare("SELECT id, content, created_at FROM notes WHERE id = :id");
$stmt->execute([':id' => $id]);
$note = $stmt->fetch();
if (!$note) {
    http_response_code(404);
    echo "Note not found";
    exit;
}

$filenameBase = "note-" . $note['id'];

// ---------- export by format ----------
switch ($format) {
    case 'md':
    case 'markdown':
        header('Content-Type: text/markdown; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filenameBase.'.md"');
        echo $note['content'];
        break;

    case 'txt':
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filenameBase.'.txt"');
        echo $note['content'];
        break;

    case 'html':
        $html = md_to_html($note['content']);
        $page = '<!doctype html><html><head><meta charset="utf-8"><title>'.e($filenameBase).'</title>'
              . '<style>'.file_get_contents(__DIR__ . '/styles.print.css') .'</style>'
              . '</head><body><article class="print-note"><div class="meta">'.e($note['created_at']).'</div>'.$html.'</article></body></html>';
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filenameBase.'.html"');
        echo $page;
        break;

    case 'pdf':
        // Try Dompdf (composer). If missing, show instruction.
        $dompdfAutoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($dompdfAutoload)) {
            http_response_code(501);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "PDF export requires Dompdf.\nRun:\n  composer require dompdf/dompdf\nThen try again.";
            exit;
        }
        require_once $dompdfAutoload;

        $html = md_to_html($note['content']);
        $page = '<!doctype html><html><head><meta charset="utf-8"><title>'.e($filenameBase).'</title>'
              . '<style>'.file_get_contents(__DIR__ . '/styles.print.css') .'</style>'
              . '</head><body><article class="print-note"><div class="meta">'.e($note['created_at']).'</div>'.$html.'</article></body></html>';

        $dompdf = new Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $dompdf->loadHtml($page, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream($filenameBase . ".pdf", ["Attachment" => true]);
        break;

    default:
        http_response_code(400);
        echo "Unsupported format. Use one of: pdf, html, md, txt";
}
