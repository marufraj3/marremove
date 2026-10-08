<?php

$trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));

if ($trustedProxies === '') {
    $proxyAddresses = null;
} elseif ($trustedProxies === '*') {
    $proxyAddresses = '*';
} else {
    $proxyAddresses = array_values(array_filter(
        array_map('trim', explode(',', $trustedProxies)),
        static fn (string $proxy): bool => $proxy !== '',
    ));
}

return [
    // Empty means no forwarded headers are trusted. Prefer explicit proxy IPs/CIDRs.
    // Use '*' only when the application origin is private and reachable exclusively
    // through a trusted load balancer/reverse proxy.
    'proxies' => $proxyAddresses,
];
