<?php
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $in  = body_json();
    $pdo = db();
    $upd = $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?');

    foreach (['armed', 'notif_phone', 'siren_auto', 'save_photo'] as $k) {
        if (isset($in[$k]) && is_bool($in[$k])) {
            $upd->execute([$in[$k] ? '1' : '0', $k]);
        }
    }
    if (isset($in['armed']) && $in['armed'] === false) {
        $pdo->exec('UPDATE device_state SET siren_on=0 WHERE id=1');
    }
}

json_out(get_settings());