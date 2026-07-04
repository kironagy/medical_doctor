<?php
$cwd = __DIR__;
$command = 'npm run build';

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$process = proc_open($command, $descriptorspec, $pipes, $cwd);

if (is_resource($process)) {
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_var = proc_close($process);

    echo "STDOUT:\n$stdout\n";
    echo "STDERR:\n$stderr\n";
    echo "Return code: $return_var\n";
}
