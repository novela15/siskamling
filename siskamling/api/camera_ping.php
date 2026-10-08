<?php
require __DIR__ . '/db.php';

db()->prepare('UPDATE device_state SET camera_seen=? WHERE id=1')->execute([now_ms()]);
json_out(['ok' => true]);