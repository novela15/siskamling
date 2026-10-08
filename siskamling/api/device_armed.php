<?php
require __DIR__ . '/db.php';

$in    = body_json();
$armed = !empty($in['armed']) ? '1' : '0';

$pdo = db();
$pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='armed'")->execute([$armed]);
if ($armed === '0') $pdo->exec('UPDATE device_state SET siren_on=0 WHERE id=1');

json_out(['armed' => $armed === '1']);