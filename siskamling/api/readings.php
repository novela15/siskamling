<?php
require __DIR__ . '/db.php';

$in = body_json();
if (!isset($in['distance']) || !is_numeric($in['distance']) || $in['distance'] < 0) {
    json_out(['error' => 'distance tidak valid'], 400);
}
$distance = (float) $in['distance'];

$pdo   = db();
$now   = now_ms();
$s     = get_settings();
$st    = $pdo->query('SELECT * FROM device_state WHERE id = 1')->fetch();
$level = level_of($distance, $s);

$pdo->prepare('UPDATE device_state SET distance=?, level=?, esp32_seen=?, sensor_ok=1 WHERE id=1')
    ->execute([$distance, $level, $now]);

$messages = ['bahaya' => 'Objek sangat dekat', 'waspada' => 'Objek mendekat', 'aman' => 'Jarak aman'];
$capture = false;

if ($s['armed']) {
    $changed      = $level !== $st['last_level'];
    $dangerRepeat = $level === 'bahaya' && ($now - (int) $st['last_danger']) > DANGER_COOLDOWN_MS;

    if ($changed || $dangerRepeat) {
        $pdo->prepare('INSERT INTO events (level, distance, message, created_at) VALUES (?,?,?,?)')
            ->execute([$level, $distance, $messages[$level], $now]);
        $pdo->prepare('UPDATE device_state SET last_level=? WHERE id=1')->execute([$level]);

        if ($level === 'bahaya') {
            $capture = true;
            $pdo->prepare('UPDATE device_state SET last_danger=? WHERE id=1')->execute([$now]);
            if ($s['siren_auto']) $pdo->exec('UPDATE device_state SET siren_on=1 WHERE id=1');
        }
    }
}

$siren = (bool) $pdo->query('SELECT siren_on FROM device_state WHERE id=1')->fetchColumn();
json_out(['level' => $level, 'armed' => $s['armed'], 'siren' => $siren, 'capture' => $capture]);