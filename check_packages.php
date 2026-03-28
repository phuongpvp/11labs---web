<?php
require_once 'api/config.php';
$db = getDB();
$stmt = $db->query("SELECT * FROM packages");
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($packages);
