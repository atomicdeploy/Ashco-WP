<?php
$root = isset($argv[1]) ? realpath($argv[1]) : false;
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Usage: php scripts/check-public-tree.php <unpacked-plugin-root>\n");
    exit(2);
}
if ('ashko-wp' !== basename($root) || !is_file($root . DIRECTORY_SEPARATOR . 'ashko-wp.php')) {
    fwrite(STDERR, "Package root must be ashko-wp/ and contain ashko-wp.php.\n");
    exit(1);
}
$forbidden_segments = array('.git', '.github', 'tests', 'dist', 'build', 'reports', 'production-data');
$forbidden_extensions = array('db', 'sqlite', 'sql', 'csv', 'json', 'zip', 'tar', 'gz', 'log');
$errors = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $segments = explode('/', $relative);
    if (array_intersect($segments, $forbidden_segments)) {
        $errors[] = 'forbidden path: ' . $relative;
    }
    if (in_array(strtolower($file->getExtension()), $forbidden_extensions, true)) {
        $errors[] = 'forbidden data/archive extension: ' . $relative;
    }
    if (preg_match('/(?:^|[-_.])(?:kala|production[-_]?data|credentials?|secrets?)(?:[-_.]|$)/i', basename($relative))) {
        $errors[] = 'suspicious production filename: ' . $relative;
    }
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "Public plugin tree is production-data-free and root-correct.\n";
