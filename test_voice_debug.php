<?php
require __DIR__ . '/api/config.php';
$worker = getActiveWorker();
echo "Active worker: "; print_r($worker); echo "<br>";
$test = testElevenLabsVoice('eithancervantesopeo@outlook.com:Phuong@123'); // or whatever the user's blocked key is
echo "Test Result: " . $test;
