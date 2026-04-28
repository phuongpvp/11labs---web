<?php
require __DIR__ . '/api/config.php';
$w = getDB()->query('SELECT * FROM workers')->fetchAll();
print_r($w);
