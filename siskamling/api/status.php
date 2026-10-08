<?php
require __DIR__ . '/db.php';

$pdo = db();
$now = now_ms();
$s   = get_settings();
$st  = $pdo->query('SELECT * FROM device_state WHERE id = 1')->fetch();

$recent = array_map('event_dto',
    $pdo->query("SELECT * FROM events WHERE level != 'aman' ORDER BY created_at DESC LIMIT 2")->fetchAll()
);

// Peringatan aktif = sirine masih menyala
$alert = null;
if ((int) $st['siren_on'] === 1) {
    $alert = event_dto(
        $pdo->query("SELECT * FROM events WHERE level='bahaya' ORDER BY created_at DESC LIMIT 1")->fetch()
    );
}

$distance = $st['distance'] === null ? null : (float) $st['distance'];

json_out([
    'esp32_online'  => ($now - (int) $st['esp32_seen']) < ONLINE_TIMEOUT_MS,
    'sensor_ok'     => (int) $st['sensor_ok'] === 1,
    'camera_online' => ($now - (int) $st['camera_seen']) < ONLINE_TIMEOUT_MS,
    'distance'      => $distance,
    'level'         => $distance === null ? null : level_of($distance, $s),
    'armed'         => $s['armed'],
    'siren_on'      => (int) $st['siren_on'] === 1,
    'thresholds'    => [
        'danger_max' => $s['danger_max'],
        'warn_max'   => $s['warn_max'],
        'safe_max'   => $s['safe_max'],
    ],
    'recent' => $recent,
    'alert'  => $alert,
]);