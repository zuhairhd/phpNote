<?php
echo "PHP version: " . PHP_VERSION . PHP_EOL;
echo "Extensions: " . implode(", ", get_loaded_extensions()) . PHP_EOL;

$path = __DIR__ . '/data/test.sqlite';
try {
    $pdo = new PDO('sqlite:' . $path);
    echo "SQLite connected at $path\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
