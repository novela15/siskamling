<?php
require __DIR__ . '/db.php';

db()->exec('UPDATE device_state SET siren_on=0 WHERE id=1');
json_out(['siren_on' => false]);