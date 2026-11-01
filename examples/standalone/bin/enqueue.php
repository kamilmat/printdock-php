<?php

declare(strict_types=1);

/**
 * Enqueues a ZPL file for a printer and prints the new job id: the `--enqueue-cmd` the black-box conformance
 * suite expects when it runs against a server it cannot administer through an API.
 *
 *   php examples/standalone/bin/enqueue.php <printer_id> <file>
 *
 * PRINTDOCK_DB is as for the server; PRINTDOCK_AGENT_ID picks the agent, otherwise the first one with that printer.
 */

use PrintDock\Server\Domain\JobFormat;
use PrintDock\Server\Domain\NewJob;
use PrintDock\Server\Examples\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$args = $_SERVER['argv'] ?? [];
$printerId = is_array($args) ? ($args[1] ?? null) : null;
$file = is_array($args) ? ($args[2] ?? null) : null;
if (!is_string($printerId) || !is_string($file) || false === ($content = file_get_contents($file))) {
    fwrite(STDERR, "usage: enqueue.php <printer_id> <file>\n");
    exit(2);
}
$database = getenv('PRINTDOCK_DB');
$runtime = new Runtime(is_string($database) && '' !== $database ? $database : dirname(__DIR__, 3) . '/var/standalone.sqlite', 'http://127.0.0.1');
$wanted = getenv('PRINTDOCK_AGENT_ID');
foreach ($runtime->admin->agents() as $agent) {
    if ($agent->hasPrinter($printerId) && (!is_string($wanted) || '' === $wanted || $agent->id()->value === $wanted)) {
        $job = $runtime->admin->enqueue(new NewJob($agent->id(), $printerId, null, JobFormat::Zpl, $content, title: 'enqueue.php'));
        echo $job->id()->value, "\n";
        exit(0);
    }
}
fwrite(STDERR, "no agent has printer $printerId\n");
exit(1);
