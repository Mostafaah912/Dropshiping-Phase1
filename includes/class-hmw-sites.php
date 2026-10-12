<?php

defined('ABSPATH') || exit;

/**
 * سایت‌های مشتری: هر دامنه یک کلید اختصاصی دارد (فقط hash ذخیره می‌شود).
 */
final class HMW_Sites {
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    private const TOUCH_INTERVAL = 300;
    private const REJECT_LOG_OPTION = 'hmw_reject_log';
    private const REJECT_COUNTS_OPTION = 'hmw_reject_counts';
    private const REJECT_LOG_LIMIT = 50;

    /** سایتی که درخواست جاری با کلید اختصاصی‌اش احراز شده (یا null). */
    public static ?array $current = null;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hmw_client_sites';
    }

    // ------------------------------------------------------------------ دامنه

    public static function normalize_domain(string $raw): ?string {
        $host = trim($raw);
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $host) ?? '';
        $host = preg_split('#[/?\#]#', $host, 2)[0] ?? '';
        $host = preg_replace('/^.*@/', '', $host) ?? '';
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');
        if (function_exists('mb_strtolower')) {
            $host = mb_strtolower($host, 'UTF-8');
        } else {
            $host = strtolower($host);
        }
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host === '' || preg_match('/[\s\[\]]/u', $host)) {
            return null;
        }

        $labels = explode('.', $host);
        $ascii = array();
        foreach ($labels as $label) {
            if ($label === '') {
                return null;
            }
            if (!preg_match('/^[\x00-\x7F]+$/', $label)) {
                if (class_exists('Normalizer')) {
                    $label = (string) Normalizer::normalize($label, Normalizer::FORM_C);
                }
                $label = 'xn--' . self::punycode_encode($label);
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $label) || strlen($label) > 63) {
                return null;
            }
            $ascii[] = $label;
        }
        if (count($ascii) < 2 || preg_match('/^\d+$/', end($ascii))) {
            return null; // تک‌بخشی (localhost) یا IP
        }
        $domain = implode('.', $ascii);
        return strlen($domain) <= 253 ? $domain : null;
    }

    private static function punycode_encode(string $input): string {
        $base = 36; $tmin = 1; $tmax = 26; $skew = 38; $damp = 700;
        $n = 128; $delta = 0; $bias = 72;
        $cps = array_map('mb_ord', mb_str_split($input, 1, 'UTF-8'));
        $output = '';
        foreach ($cps as $c) {
            if ($c < 128) {
                $output .= chr($c);
            }
        }
        $b = $h = strlen($output);
        if ($b > 0) {
            $output .= '-';
        }
        $digit = static fn (int $d): string => chr($d < 26 ? $d + 97 : $d + 22);
        $adapt = static function (int $delta, int $points, bool $first) use ($base, $tmin, $tmax, $skew, $damp): int {
            $delta = $first ? intdiv($delta, $damp) : $delta >> 1;
            $delta += intdiv($delta, $points);
            $k = 0;
            while ($delta > ((($base - $tmin) * $tmax) >> 1)) {
                $delta = intdiv($delta, $base - $tmin);
                $k += $base;
            }
            return $k + intdiv(($base - $tmin + 1) * $delta, $delta + $skew);
        };
        $total = count($cps);
        while ($h < $total) {
            $m = PHP_INT_MAX;
            foreach ($cps as $c) {
                if ($c >= $n && $c < $m) {
                    $m = $c;
                }
            }
            $delta += ($m - $n) * ($h + 1);
            $n = $m;
            foreach ($cps as $c) {
                if ($c < $n) {
                    $delta++;
                }
                if ($c === $n) {
                    $q = $delta;
                    for ($k = $base;; $k += $base) {
                        $t = $k <= $bias ? $tmin : ($k >= $bias + $tmax ? $tmax : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= $digit($t + (($q - $t) % ($base - $t)));
                        $q = intdiv($q - $t, $base - $t);
                    }
                    $output .= $digit($q);
                    $bias = $adapt($delta, $h + 1, $h === $b);
                    $delta = 0;
                    $h++;
                }
            }
            $delta++;
            $n++;
        }
        return $output;
    }

    // ------------------------------------------------------------------ CRUD

    public static function add(string $raw_domain, string $note = ''): array {
        global $wpdb;
        $domain = self::normalize_domain($raw_domain);
        if ($domain === null) {
            return array('success' => false, 'message' => 'نام دامنه معتبر نیست.');
        }
        if (self::find_by_domain($domain) !== null) {
            return array('success' => false, 'message' => 'این دامنه قبلاً ثبت شده است.');
        }
        $ok = $wpdb->insert(self::table(), array(
            'domain' => $domain,
            'status' => self::STATUS_PENDING,
            'note' => sanitize_text_field($note),
            'created_at' => current_time('mysql', true),
        ));
        if ($ok === false) {
            return array('success' => false, 'message' => 'این دامنه قبلاً ثبت شده است.');
        }
        return array('success' => true, 'id' => (int) $wpdb->insert_id, 'domain' => $domain);
    }

    public static function find(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function find_by_domain(string $domain): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE domain = %s LIMIT 1', $domain), ARRAY_A);
        return $row ?: null;
    }

    /** بدون هیچ کشی: هر درخواست مستقیم از دیتابیس می‌خواند (غیرفعال‌سازی همان لحظه اثر کند). */
    public static function find_by_key(string $token): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE key_hash = %s LIMIT 1', hash('sha256', $token)), ARRAY_A);
        return $row ?: null;
    }

    public static function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY id ASC', ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    public static function set_status(int $id, string $status): bool {
        global $wpdb;
        $row = self::find($id);
        if (!$row || !in_array($status, array(self::STATUS_ACTIVE, self::STATUS_DISABLED), true)) {
            return false;
        }
        // بدون کلید نمی‌شود «فعال» بود؛ منتظر فعال‌سازی می‌ماند.
        if ($status === self::STATUS_ACTIVE && ($row['key_hash'] === null || $row['key_hash'] === '')) {
            $status = self::STATUS_PENDING;
        }
        return $wpdb->update(self::table(), array('status' => $status), array('id' => $id)) !== false;
    }

    /** صدور دوباره: کلید قبلی همان لحظه نامعتبر و سایت دوباره «آماده فعال‌سازی» می‌شود. */
    public static function reissue(int $id): bool {
        global $wpdb;
        if (!self::find($id)) {
            return false;
        }
        return $wpdb->update(self::table(), array('key_hash' => null, 'key_prefix' => null, 'status' => self::STATUS_PENDING, 'activated_at' => null), array('id' => $id)) !== false;
    }

    public static function delete(int $id): bool {
        global $wpdb;
        return $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE id = %d', $id)) !== false;
    }

    /** کلید را می‌سازد، فقط hash/پیشوند را ذخیره می‌کند و کلید خام را یک‌بار برمی‌گرداند. */
    public static function issue_key(int $id): string {
        global $wpdb;
        $raw = 'hmw_' . wp_generate_uuid4() . '_' . bin2hex(random_bytes(24));
        $key = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $wpdb->update(self::table(), array(
            'key_hash' => hash('sha256', $key),
            'key_prefix' => substr($key, 0, 12),
            'status' => self::STATUS_ACTIVE,
            'activated_at' => current_time('mysql', true),
        ), array('id' => $id));
        return $key;
    }

    // ------------------------------------------------------------------ ارتباط

    public static function touch_seen(array $row): void {
        self::touch($row, 'last_seen_at');
    }

    public static function touch_sync_current(): void {
        if (self::$current !== null) {
            self::touch(self::$current, 'last_sync_at');
        }
    }

    private static function touch(array $row, string $column): void {
        global $wpdb;
        $last = !empty($row[$column]) ? strtotime((string) $row[$column] . ' UTC') : false;
        if ($last !== false && $last > time() - self::TOUCH_INTERVAL) {
            return;
        }
        $now = current_time('mysql', true);
        $wpdb->update(self::table(), array($column => $now), array('id' => (int) $row['id']));
        if (self::$current !== null && (int) self::$current['id'] === (int) $row['id']) {
            self::$current[$column] = $now;
        }
    }

    // ------------------------------------------------------------------ درخواست‌های ردشده (بدون کلید)

    public static function log_reject(string $reason, ?string $domain = null): void {
        $counts = get_option(self::REJECT_COUNTS_OPTION, array());
        $counts = is_array($counts) ? $counts : array();
        $counts[$reason] = array('count' => (int) ($counts[$reason]['count'] ?? 0) + 1, 'last_at' => current_time('mysql', true));
        update_option(self::REJECT_COUNTS_OPTION, $counts, false);

        $log = get_option(self::REJECT_LOG_OPTION, array());
        $log = is_array($log) ? $log : array();
        array_unshift($log, array(
            'at' => current_time('mysql', true),
            'reason' => $reason,
            'domain' => $domain !== null ? substr(sanitize_text_field($domain), 0, 100) : null,
            'ip' => HMW_REST_API::client_ip(),
        ));
        update_option(self::REJECT_LOG_OPTION, array_slice($log, 0, self::REJECT_LOG_LIMIT), false);
    }

    public static function reject_log(): array {
        $log = get_option(self::REJECT_LOG_OPTION, array());
        return is_array($log) ? $log : array();
    }

    public static function reject_counts(): array {
        $counts = get_option(self::REJECT_COUNTS_OPTION, array());
        return is_array($counts) ? $counts : array();
    }
}
