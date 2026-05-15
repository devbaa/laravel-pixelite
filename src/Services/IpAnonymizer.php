<?php

declare(strict_types=1);

namespace Boralp\Pixelite\Services;

class IpAnonymizer
{
    /**
     * Anonymize a human-readable IP address string.
     *
     * @param  string|null  $ip     e.g. "203.0.113.42" or "2001:db8::1"
     * @param  string       $level  none | partial | full
     * @return string|null  Anonymized IP string, or null when level=full
     */
    public function anonymize(?string $ip, string $level): ?string
    {
        if ($level === 'none') {
            return $ip;
        }

        if (! $ip) {
            return null;
        }

        if ($level === 'full') {
            return null;
        }

        // partial — mask last octet for IPv4, keep /64 prefix for IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->anonymizeIPv6($ip);
        }

        return null;
    }

    private function anonymizeIPv6(string $ip): ?string
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }

        // Keep the first 8 bytes (/64 prefix), zero out the interface identifier
        $anonymized = substr($binary, 0, 8).str_repeat("\x00", 8);
        $result = inet_ntop($anonymized);

        return $result !== false ? $result : null;
    }
}
