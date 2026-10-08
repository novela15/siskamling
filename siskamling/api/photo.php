<?php
require __DIR__ . '/db.php';

$data = file_get_contents('php://input');
if (!$data || strlen($data) < 100 || substr($data, 0, 2) !== "\xFF\xD8") {
    json_out(['error' => 'foto kosong atau bukan JPEG'], 400);
}

$pdo = db();
$now = now_ms();
$pdo->prepare('UPDATE device_state SET camera_seen=? WHERE id=1')->execute([$now]);

$q = $pdo->prepare("SELECT id FROM events
    WHERE level='bahaya' AND photo IS NULL AND created_at > ?
    ORDER BY created_at DESC LIMIT 1");
$q->execute([$now - PHOTO_WINDOW_MS]);
$ev = $q->fetch();
if (!$ev) json_out(['error' => 'tidak ada kejadian bahaya yang menunggu foto'], 404);

$dir = __DIR__ . '/../uploads';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$filename = "event-{$ev['id']}-{$now}.jpg";
file_put_contents("$dir/$filename", $data);
$pdo->prepare('UPDATE events SET photo=? WHERE id=?')->execute([$filename, $ev['id']]);

json_out(['ok' => true, 'event_id' => (int) $ev['id']]);