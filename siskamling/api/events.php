<?php
require __DIR__ . '/db.php';

$pdo   = db();
$level = $_GET['level'] ?? '';

if (in_array($level, ['bahaya', 'waspada', 'aman'], true)) {
    $q = $pdo->prepare('SELECT * FROM events WHERE level=? ORDER BY created_at DESC LIMIT 200');
    $q->execute([$level]);
} else {
    $q = $pdo->query('SELECT * FROM events ORDER BY created_at DESC LIMIT 200');
}

json_out(array_map('event_dto', $q->fetchAll()));