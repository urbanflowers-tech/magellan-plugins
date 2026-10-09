<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Protocol {
    public static function json($value): string {
        $body = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        if ($body === false) { throw new \RuntimeException('json_encoding_failed'); }
        return $body;
    }

    // Integer string arithmetic: never multiply binary floating point by 100.
    public static function money($decimal, string $currency, int $exponent): array {
        $s = (string) $decimal;
        // WooCommerce's computed cart values may be floats or scientific notation.
        // Expand the decimal representation before rounding; never multiply a float.
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/D', $s, $scientific)) {
            $shift = (int) $scientific[4];
            if (abs($shift) > 100) { throw new \InvalidArgumentException('invalid_money'); }
            $digits = $scientific[2] . ($scientific[3] ?? ''); $point = strlen($scientific[2]) + $shift;
            $s = $scientific[1] . ($point <= 0 ? '0.' . str_repeat('0', -$point) . $digits : ($point >= strlen($digits) ? $digits . str_repeat('0', $point - strlen($digits)) : substr($digits, 0, $point) . '.' . substr($digits, $point)));
        }
        if ($exponent < 0 || $exponent > 6 || strlen($s) > 150 || !preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $s, $m)) {
            throw new \InvalidArgumentException('invalid_money');
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . str_pad(substr($fraction, 0, $exponent), $exponent, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        // Half up at the channel currency exponent, including negative refunds.
        if (isset($fraction[$exponent]) && $fraction[$exponent] >= '5') {
            for ($i = strlen($digits) - 1; $i >= 0 && $digits[$i] === '9'; $i--) { $digits[$i] = '0'; }
            if ($i < 0) { $digits = '1' . $digits; } else { $digits[$i] = (string) ((int) $digits[$i] + 1); }
        }
        return ['currency' => $currency, 'exponent' => $exponent, 'amount_minor' => ($m[1] === '-' && $digits !== '0' ? '-' : '') . $digits];
    }

    public static function target(string $url): string {
        $p = wp_parse_url($url);
        $path = $p['path'] ?? '/';
        $pairs = [];
        foreach (explode('&', $p['query'] ?? '') as $pair) {
            if ($pair === '') { continue; }
            $parts = explode('=', $pair, 2);
            $pairs[] = rawurlencode(rawurldecode($parts[0])) . '=' . rawurlencode(rawurldecode($parts[1] ?? ''));
        }
        sort($pairs, SORT_STRING);
        return $path . ($pairs ? '?' . implode('&', $pairs) : '');
    }

    public static function headers(array $config, string $method, string $url, string $body, ?int $time = null, ?string $nonce = null): array {
        $key = base64_decode($config['signing_key'], true);
        if ($key === false || strlen($key) !== 32) { throw new \InvalidArgumentException('invalid_signing_key'); }
        $time = $time ?? time(); $nonce = $nonce ?? wp_generate_uuid4();
        $input = implode("\n", ['v3', strtoupper($method), self::target($url), $config['installation_id'], (string) $time, $nonce, hash('sha256', $body)]);
        return ['Content-Type' => 'application/json', 'X-Magellan-Protocol' => '3', 'X-Magellan-Installation' => $config['installation_id'], 'X-Magellan-Key-Id' => $config['key_id'], 'X-Magellan-Timestamp' => (string) $time, 'X-Magellan-Nonce' => $nonce, 'X-Magellan-Signature' => hash_hmac('sha256', $input, $key)];
    }

    public static function consent(): array {
        return ['analytics' => 'unknown', 'advertising' => 'unknown', 'email_marketing' => 'unknown', 'sms_marketing' => 'unknown', 'source' => 'unavailable', 'effective_at' => gmdate('c'), 'policy_version' => 'unknown', 'epoch' => 0, 'gpc' => false];
    }

    public static function id($value): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) ? strtolower($value) : null;
    }
}
