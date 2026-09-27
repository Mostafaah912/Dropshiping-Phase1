<?php

defined('ABSPATH') || exit;

final class HCI_Source_Client {
    private const PRODUCTS_CACHE_TRANSIENT = 'hci_products_cache';
    private const PRODUCTS_CACHE_TTL = 10 * MINUTE_IN_SECONDS;
    private const MAX_PAGES = 30; // سقف ایمنی (≈۳۰۰۰ محصول) برای جلوگیری از حلقه بی‌پایان روی یک منبع خراب
    private const PARTIAL_STATE_TRANSIENT = 'hci_products_partial_state';
    private const PARTIAL_STATE_TTL = 30 * MINUTE_IN_SECONDS;

    // Rate Limit سمت منبع (class-hmw-rest-api.php: RATE_LIMIT=120 درخواست در
    // RATE_WINDOW=60 ثانیه در هر IP) یعنی نرخ پایدار مجاز حداکثر ۲ درخواست در
    // ثانیه است. ۶۰۰ میلی‌ثانیه تاخیر بین صفحات ≈ ۱٫۶۷ درخواست در ثانیه —
    // زیر سقف با حاشیه امن، بدون این‌که واکشی ۲۲۰۷ محصول (۲۳ صفحه) را
    // غیرقابل‌تحمل کند (~۱۴ ثانیه تاخیر جمعی).
    private static int $page_delay_us = 600000;
    // اگر با وجود تاخیر باز هم ۴۲۹ گرفتیم، طبق مستندات بین ۲ تا ۵ ثانیه صبر و
    // یک‌بار Retry می‌کنیم؛ اگر بازهم ۴۲۹ شد، همان‌جا متوقف می‌شویم (نه Retry بی‌پایان).
    private static int $rate_limit_retry_delay_s = 3;

    /**
     * فقط برای تست خودکار: تاخیرهای واقعی را کوتاه/صفر می‌کند تا اجرای تست
     * چند ثانیه معطل sleep() واقعی نشود. در کد Production هرگز صدا زده نمی‌شود.
     */
    public static function configure_delays_for_tests(int $page_delay_us, int $rate_limit_retry_delay_s): void {
        self::$page_delay_us = $page_delay_us;
        self::$rate_limit_retry_delay_s = $rate_limit_retry_delay_s;
    }

    /**
     * کل محصولات فعال منبع را برمی‌گرداند. برخلاف /products که فیلتر/مرتب‌سازی
     * سمت سرور کافی برایGrid فاز ۲ ندارد، اینجا یک‌بار همه صفحات را می‌گیریم و
     * در یک Transient کش می‌کنیم تا تغییر فیلتر/صفحه در UI ادمین دوباره به
     * منبع درخواست نزند؛ فقط با $force_refresh یا انقضای کش دوباره Fetch می‌شود.
     */
    public static function get_all_products(bool $force_refresh = false): array {
        if (!$force_refresh) {
            $cached = get_transient(self::PRODUCTS_CACHE_TRANSIENT);
            if (is_array($cached)) {
                return array('success' => true, 'items' => $cached, 'from_cache' => true);
            }
        }

        $api_url = trim((string) get_option('hci_api_url', ''));
        $api_key = trim((string) get_option('hci_api_key', ''));
        if ($api_url === '' || $api_key === '') {
            return array('success' => false, 'items' => array(), 'message' => 'API URL و API Key باید هر دو تنظیم شوند.');
        }

        // اگر تلاش قبلی به‌خاطر Rate Limit ناقص مانده، از همان صفحه ادامه
        // می‌دهیم (نه از صفر) تا محصولاتی که قبلاً موفق واکشی شده بودند دور
        // ریخته نشوند.
        $partial = get_transient(self::PARTIAL_STATE_TRANSIENT);
        if (is_array($partial) && isset($partial['items'], $partial['next_page'])) {
            $items = $partial['items'];
            $page = (int) $partial['next_page'];
            $total_pages = (int) ($partial['total_pages'] ?? $page);
            $total_count = $partial['total_count'] ?? null;
        } else {
            $items = array();
            $page = 1;
            $total_pages = 1;
            $total_count = null;
        }

        while ($page <= $total_pages && $page <= self::MAX_PAGES) {
            $result = self::fetch_products_page_with_retry($api_url, $api_key, $page);

            if (!$result['success']) {
                if (!empty($result['rate_limited'])) {
                    set_transient(self::PARTIAL_STATE_TRANSIENT, array(
                        'items' => $items,
                        'next_page' => $page,
                        'total_pages' => $total_pages,
                        'total_count' => $total_count,
                    ), self::PARTIAL_STATE_TTL);

                    return array(
                        'success' => false,
                        'items' => $items,
                        'partial' => true,
                        'total_pages' => $total_pages,
                        'total_count' => $total_count,
                        'message' => sprintf(
                            '%s محصول%s دریافت شد، اما به‌خاطر محدودیت نرخ درخواست (Rate Limit) سرور منبع متوقف شد. دوباره روی «بروزرسانی از منبع» بزنید تا از همین‌جا ادامه یابد.',
                            number_format(count($items)),
                            $total_count !== null ? ' از مجموع ' . number_format((int) $total_count) : ''
                        ),
                    );
                }

                // شکست غیر از Rate Limit (مثلاً خطای شبکه/Auth): پیشرفت جزئی
                // را هم نگه می‌داریم تا کاربر همه‌چیز را از دست ندهد، اما
                // success=false را صادق گزارش می‌کنیم.
                set_transient(self::PARTIAL_STATE_TRANSIENT, array(
                    'items' => $items,
                    'next_page' => $page,
                    'total_pages' => $total_pages,
                    'total_count' => $total_count,
                ), self::PARTIAL_STATE_TTL);

                return array('success' => false, 'items' => $items, 'partial' => !empty($items), 'message' => $result['message']);
            }

            $page_items = is_array($result['data']) ? $result['data'] : array();
            $items = array_merge($items, $page_items);
            $total_pages = (int) ($result['pagination']['total_pages'] ?? $page);
            $total_count = $result['pagination']['total'] ?? $total_count;
            $page++;

            if (empty($page_items)) {
                break;
            }

            if ($page <= $total_pages && $page <= self::MAX_PAGES) {
                usleep(self::$page_delay_us);
            }
        }

        delete_transient(self::PARTIAL_STATE_TRANSIENT);
        set_transient(self::PRODUCTS_CACHE_TRANSIENT, $items, self::PRODUCTS_CACHE_TTL);
        return array('success' => true, 'items' => $items, 'total_count' => $total_count, 'from_cache' => false);
    }

    public static function clear_products_cache(): void {
        delete_transient(self::PRODUCTS_CACHE_TRANSIENT);
    }

    private static function fetch_products_page_with_retry(string $api_url, string $api_key, int $page): array {
        $result = self::fetch_products_page($api_url, $api_key, $page);
        if (($result['status'] ?? null) !== 429) {
            return $result;
        }

        sleep(self::$rate_limit_retry_delay_s);
        $retry = self::fetch_products_page($api_url, $api_key, $page);
        if (($retry['status'] ?? null) === 429) {
            $retry['rate_limited'] = true;
        }
        return $retry;
    }

    private static function fetch_products_page(string $api_url, string $api_key, int $page): array {
        // add_query_arg() به‌جای ساخت دستی '?'+http_build_query لازم است: وقتی
        // Permalinks سایت منبع روی Plain باشد، rest_url() آدرسی مثل
        // '.../index.php?rest_route=/hmw/v1' برمی‌گرداند که خودش از قبل یک '?'
        // دارد؛ اضافه‌کردن یک '?' دیگر بعد از '/products' باعث می‌شد کوئری‌استرینگ
        // به‌اشتباه پارس شود (rest_route با '?page=1' آلوده می‌شد و هیچ Route‌ای
        // مچ نمی‌کرد). add_query_arg() هر دو فرمت (Pretty Permalinks و
        // ?rest_route=) را درست merge می‌کند.
        $url = add_query_arg(array(
            'page' => $page,
            'per_page' => 100,
            'include_inactive' => 'false',
        ), rtrim($api_url, '/') . '/products');
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'خطا در ارتباط با سرور منبع: ' . $response->get_error_message(), 'status' => 0);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['success'])) {
            return array(
                'success' => false,
                'message' => sprintf('پاسخ سرور منبع: HTTP %d%s', $status, self::extract_error_message($json, $response)),
                'status' => $status,
            );
        }

        return array(
            'success' => true,
            'data' => $json['data'] ?? array(),
            'pagination' => $json['pagination'] ?? array(),
            'status' => $status,
        );
    }

    private static function extract_error_message($json, $response): string {
        $message = '';
        if (is_array($json)) {
            if (!empty($json['error']['message'])) {
                $message = (string) $json['error']['message'];
            } elseif (!empty($json['message'])) {
                $message = (string) $json['message'];
            }
        }
        if ($message === '') {
            $message = wp_remote_retrieve_response_message($response);
        }
        return $message !== '' ? ' — ' . $message : '';
    }

    public static function test_connection(string $api_url, string $api_key): array {
        $api_url = trim($api_url);
        $api_key = trim($api_key);

        if ($api_url === '' || $api_key === '') {
            return array('success' => false, 'message' => 'API URL و API Key باید هر دو تنظیم شوند.');
        }

        $url = rtrim($api_url, '/') . '/health';
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'خطا در ارتباط با سرور منبع: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            // خطاهای Auth (permission_callback) به شکل استاندارد WP_Error سریالایز
            // می‌شوند: {"code","message","data":{"status"}} — نه شکل سفارشی
            // {"error":{"message"}} که فقط برای خطاهای سطح Handler استفاده شده.
            // هر دو شکل باید هندل شوند وگرنه دلیل واقعی خطا (مثل کلید نامعتبر)
            // بی‌صدا با پیام عمومی HTTP جایگزین می‌شود.
            return array(
                'success' => false,
                'message' => sprintf('پاسخ سرور منبع: HTTP %d%s', $status, self::extract_error_message($json, $response)),
                'status' => $status,
            );
        }

        if (!is_array($json) || empty($json['success'])) {
            return array('success' => false, 'message' => 'پاسخ سرور منبع معتبر (JSON) نبود.', 'status' => $status);
        }

        return array(
            'success' => true,
            'message' => 'اتصال موفق بود.',
            'status' => $status,
            'data' => $json['data'] ?? null,
        );
    }
}
