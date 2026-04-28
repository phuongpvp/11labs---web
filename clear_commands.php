<?php
require_once __DIR__ . '/api/config.php';
$db = getDB();
$db->exec("UPDATE colab_commands SET status = 'cancelled' WHERE status = 'pending'");
echo "All pending commands have been cancelled.";
