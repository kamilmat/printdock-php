<?php

declare(strict_types=1);

namespace PrintDock\Server\Domain;

/**
 * The address of an `ipp` printer (section 4.3): `ipp://host[:port]/path` or `ipps://host[:port]/path`, default port
 * 631, no user info, query or fragment. A host given as an IP address must be on the local network (loopback,
 * link-local, RFC 1918, IPv6 ULA) - the same rule the agent applies to `socket` and `ipp` targets before it connects,
 * so a server UI refuses what the station would refuse anyway. A host name passes: only the station's resolver knows
 * what it points to, and the agent checks that.
 */
final class IppUri
{
    private const PRIVATE_V4 = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8], ['169.254.0.0', 16]];

    /**
     * @return array{scheme: string, host: string, port: int, path: string}
     */
    public static function check(string $uri): array
    {
        if (!preg_match('~^(ipps?)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::(\d{1,5}))?(/[^\s?#\\\\]*)?$~', $uri, $m)) {
            throw new \InvalidArgumentException('an ipp printer address must be ipp://host[:port]/path or ipps://host[:port]/path');
        }
        $port = isset($m[3]) && '' !== $m[3] ? (int) $m[3] : 631;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('ipp printer port out of range');
        }
        $host = trim($m[2], '[]');
        if (str_starts_with($m[2], '[') && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException('ipp printer address has a malformed IPv6 literal');
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP) && !self::isLocal($host)) {
            throw new \InvalidArgumentException('ipp printer address '.$host.' is outside the local network (loopback, link-local, RFC 1918, IPv6 ULA)');
        }

        return ['scheme' => $m[1], 'host' => $host, 'port' => $port, 'path' => $m[4] ?? '/ipp/print'];
    }

    public static function isLocal(string $ip): bool
    {
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            foreach (self::PRIVATE_V4 as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return true;
                }
            }

            return false;
        }
        $bin = inet_pton($ip);
        if (false === $bin || 16 !== strlen($bin)) {
            return false;
        }
        if ("\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr($bin, 0, 12)) {   // IPv4-mapped
            return self::isLocal((string) inet_ntop(substr($bin, 12)));
        }
        $first = ord($bin[0]);
        $second = ord($bin[1]);

        return str_repeat("\0", 15)."\1" === $bin                          // ::1
            || 0xFC === ($first & 0xFE)                                     // fc00::/7 (ULA)
            || (0xFE === $first && 0x80 === ($second & 0xC0));              // fe80::/10 (link-local)
    }
}
