<?php

declare(strict_types=1);

namespace Tomvondracek\Sitemap\Url;

/**
 * Makes a URL safe for a sitemap: a non-ASCII host in Punycode, and
 * every byte of the path, query and fragment outside RFC 3986 percent-encoded
 * (`/služby` → `/slu%C5%BEby`, a space → `%20`). Existing escapes are kept, so
 * normalizing twice changes nothing.
 */
final class UrlNormalizer
{
    /**
     * Characters RFC 3986 allows unescaped in a URL, plus `%` that starts an escape.
     */
    private const UNSAFE = '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]|%(?![0-9A-Fa-f]{2})/';

    public static function normalize(string $url): string
    {
        if (preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $url, $parts) !== 1) {
            return self::encode($url);
        }

        return mb_strtolower($parts[1]) . self::authority($parts[2]) . self::encode($parts[3]);
    }

    private static function authority(string $authority): string
    {
        $userInfo = '';
        if (str_contains($authority, '@')) {
            [$userInfo, $authority] = explode('@', $authority, 2);
            $userInfo = self::encode($userInfo) . '@';
        }

        $port = '';
        if (preg_match('/^(.*)(:\d*)$/', $authority, $hostAndPort) === 1 && ! str_ends_with($hostAndPort[1], ':')) {
            [, $authority, $port] = $hostAndPort;
        }

        return $userInfo . self::host($authority) . $port;
    }

    private static function host(string $host): string
    {
        if (preg_match('/[^\x20-\x7e]/', $host) !== 1) {
            return mb_strtolower($host);
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? mb_strtolower($host) : $ascii;
    }

    private static function encode(string $value): string
    {
        return preg_replace_callback(self::UNSAFE, static fn (array $match): string => rawurlencode($match[0]), $value) ?? $value;
    }
}
