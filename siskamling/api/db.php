<?php
date_default_timezone_set('Asia/Jakarta');

const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'siskamling';

const ONLINE_TIMEOUT_MS  = 15000;  // ESP32 dianggap terhubung jika kirim data <15 dtk lalu
const DANGER_COOLDOWN_MS = 10000;  // jeda pencatatan ulang saat tetap Bahaya
const PHOTO_WINDOW_MS    = 30000;  // foto menempel ke kejadian bahaya <30 dtk lalu

/* ---------- Helper umum ---------- */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

// Kalau ada error PHP/MySQL, tampil sebagai JSON supaya mudah dibaca
set_exception_handler(function ($e) {
    json_out(['error' => $e->getMessage()], 500);
});

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function body_json(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

/* ---------- Koneksi + buat database & tabel otomatis ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
    $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4');

    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(50) NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        level      ENUM('bahaya','waspada','aman') NOT NULL,
        distance   DOUBLE NOT NULL,
        message    VARCHAR(100) NOT NULL,
        photo      VARCHAR(255) NULL,
        created_at BIGINT NOT NULL,
        INDEX idx_created (created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS device_state (
        id          TINYINT PRIMARY KEY,
        distance    DOUBLE NULL,
        level       VARCHAR(10) NULL,
        last_level  VARCHAR(10) NULL,
        last_danger BIGINT NOT NULL DEFAULT 0,
        esp32_seen  BIGINT NOT NULL DEFAULT 0,
        sensor_ok   TINYINT NOT NULL DEFAULT 1,
        camera_seen BIGINT NOT NULL DEFAULT 0,
        siren_on    TINYINT NOT NULL DEFAULT 0
    )");

    // Nilai awal (sesuai layar Pengaturan di desain)
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
        ('danger_max','10'), ('warn_max','50'), ('safe_max','100'),
        ('armed','1'), ('notif_phone','1'), ('siren_auto','1'), ('save_photo','0')");
    $pdo->exec('INSERT IGNORE INTO device_state (id) VALUES (1)');

    return $pdo;
}

/* ---------- Helper logika ---------- */
function get_settings(): array {
    $r = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'danger_max'  => (float) $r['danger_max'],
        'warn_max'    => (float) $r['warn_max'],
        'safe_max'    => (float) $r['safe_max'],
        'armed'       => $r['armed'] === '1',
        'notif_phone' => $r['notif_phone'] === '1',
        'siren_auto'  => $r['siren_auto'] === '1',
        'save_photo'  => $r['save_photo'] === '1',
    ];
}

function level_of(float $d, array $s): string {
    if ($d <= $s['danger_max']) return 'bahaya';
    if ($d <= $s['warn_max'])   return 'waspada';
    return 'aman';
}

function event_dto($e): ?array {
    if (!$e) return null;
    return [
        'id'         => (int) $e['id'],
        'level'      => $e['level'],
        'distance'   => (float) $e['distance'],
        'message'    => $e['message'],
        'photo'      => $e['photo'] ? 'uploads/' . $e['photo'] : null,
        'created_at' => (int) $e['created_at'],
    ];
}