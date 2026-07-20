<?php
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = array();
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $normalized = str_replace('\\', '/', $path);
    if ('php' !== strtolower($file->getExtension()) || preg_match('~/(?:vendor|dist|build|\.git)/~', $normalized)) {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if (0 !== $status) {
        $failed[$path] = implode("\n", $output);
    }
    $output = array();
}
if ($failed) {
    foreach ($failed as $path => $message) {
        fwrite(STDERR, $path . "\n" . $message . "\n");
    }
    exit(1);
}
echo "PHP syntax check passed.\n";
