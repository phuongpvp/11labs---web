<?php
$logDir = __DIR__ . '/logs';
if (is_dir($logDir)) {
    $files = scandir($logDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..')
            continue;
        $mtime = date('Y-m-d H:i:s', filemtime($logDir . '/' . $file));
        echo "$file - $mtime\n";
    }
} else {
    echo "Log directory not found";
}
?>