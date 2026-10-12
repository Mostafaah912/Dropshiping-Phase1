<?php

defined('ABSPATH') || exit;

/**
 * Rate Limit با پنجره ثابت: شروع پنجره با اولین درخواست ثبت می‌شود و هیچ
 * درخواستی (چه مجاز چه ردشده) آن را تمدید نمی‌کند؛ بعد از $window ثانیه
 * شمارنده از صفر شروع می‌شود.
 */
final class HMW_Rate_Limiter {
    private const PREFIX = 'hmw_rl_';

    public static function allow(string $bucket, int $limit, int $window, ?int $now = null): bool {
        $now = $now ?? time();
        $key = self::PREFIX . md5($bucket);
        $state = get_transient($key);
        if (!is_array($state) || !isset($state['start'], $state['count']) || $now - (int) $state['start'] >= $window) {
            $state = array('start' => $now, 'count' => 0);
        }
        $remaining = max(1, $window - ($now - (int) $state['start']));

        if ((int) $state['count'] >= $limit) {
            set_transient($key, $state, $remaining);
            return false;
        }
        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $remaining);
        return true;
    }

    public static function peek(string $bucket): ?array {
        $state = get_transient(self::PREFIX . md5($bucket));
        return is_array($state) ? $state : null;
    }
}
