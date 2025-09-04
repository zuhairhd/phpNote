<?php
// db.php — SQLite connection (creates data/notes.sqlite automatically)
declare(strict_types=1);

// Writable data folder
define('DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data');
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0777, true) && !is_dir(DATA_DIR)) {
        die("Failed to create data directory: " . DATA_DIR);
    }
}
define('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'notes.sqlite');

try {
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Improve concurrency & integrity
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Schema
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content   TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage() . ' (DB_PATH=' . DB_PATH . ')');
}
