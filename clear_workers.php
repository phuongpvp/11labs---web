<?php
require __DIR__ . '/api/config.php';
getDB()->exec('DELETE FROM workers');
echo 'Workers cleared!';
