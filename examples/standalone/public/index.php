<?php

declare(strict_types=1);

/**
 * Front controller for `php -S 127.0.0.1:8099 examples/standalone/public/index.php`.
 *
 *   PRINTDOCK_DB         SQLite file (default var/standalone.sqlite)
 *   PRINTDOCK_BASE_URL   public origin agents use, for content_url (default: derived from the Host header)
 *   PRINTDOCK_ADMIN_TOKEN  when set, /admin requires header X-Admin-Token
 *
 * PAP lives under /pap/v1, the admin API under /admin (see AdminApi).
 */

use PrintDock\Server\Examples\AdminApi;
use PrintDock\Server\Examples\Runtime;
use PrintDock\Server\Http\HttpResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

ini_set('default_mimetype', '');

$env = static fn (string $name, string $default): string => is_string($value = getenv($name)) && '' !== $value ? $value : $default;

$databaseFile = $env('PRINTDOCK_DB', dirname(__DIR__, 3) . '/var/standalone.sqlite');
if (!is_dir(dirname($databaseFile))) {
    mkdir(dirname($databaseFile), 0777, true);
}
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '127.0.0.1';
$baseUrl = $env('PRINTDOCK_BASE_URL', 'http://' . $host);
// PRINTDOCK_CLAIM_COOLDOWN=0 only for test tools that claim back to back (the conformance suite does)
$runtime = new Runtime($databaseFile, $baseUrl, '0' !== $env('PRINTDOCK_CLAIM_COOLDOWN', '1'));

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$target = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($target)['path'] ?? '/';
/** @var array<string, string> $headers */
$headers = [];
foreach ((array) getallheaders() as $name => $value) {
    if (is_string($value)) {
        $headers[strtolower((string) $name)] = $value;
    }
}
$body = (string) file_get_contents('php://input');

if (str_starts_with($path, Runtime::PAP_PREFIX)) {
    $response = $runtime->router->handle($method, $target, $headers, $body);
} elseif (str_starts_with($path, '/admin')) {
    $clock = new class () implements Psr\Clock\ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    };
    $admin = new AdminApi($runtime->admin, $clock, $env('PRINTDOCK_ADMIN_TOKEN', '') ?: null, rtrim($baseUrl, '/') . Runtime::PAP_PREFIX);
    $response = $admin->handle($method, substr($path, strlen('/admin')), $headers, $body);
} else {
    $response = HttpResponse::json(200, ['service' => 'printdock-standalone-example', 'pap' => Runtime::PAP_PREFIX, 'admin' => '/admin']);
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
