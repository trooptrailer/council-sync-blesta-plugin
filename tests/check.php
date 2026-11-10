<?php

$root = dirname(__DIR__);
$failed = false;
$checked = 0;
foreach ([$root . '/plugins/council_sync', __DIR__] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'pdt'], true)) {
            continue;
        }
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PHP syntax check.');
        }
        $failed = proc_close($process) !== 0 || $failed;
        $checked++;
    }
}
if ($failed) {
    exit(1);
}
echo $checked . " PHP/PDT files passed syntax checks.\n";
require __DIR__ . '/run.php';
