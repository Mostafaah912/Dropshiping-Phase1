<?php

defined('ABSPATH') || exit;

/**
 * اتصال به هی‌مد: آدرس مبدا و کلید هیچ‌وقت به کاربر نشان داده یا از او گرفته
 * نمی‌شود. سایت با فعال‌سازی خودکار (مبتنی بر دامنه) کلید اختصاصی می‌گیرد.
 *
 * ترتیب آدرس مبدا: ثابت HCI_SOURCE_URL (wp-config، برای تست/استیجینگ) ←
 * ثابت پیش‌فرض داخل پلاگین (HCI_DEFAULT_SOURCE_URL) ← گزینه‌ی خصوصی (مقدار
 * قبلی سایت‌های موجود) ← گزینه‌ی قدیمی hci_api_url.
 */
final class HCI_Connection {
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_NOT_ACTIVATED = 'not_activated';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_UNREACHABLE = 'unreachable';

    private const OPT_KEY = 'hci_conn_key';
    private const OPT_URL = 'hci_conn_source_url';
    private const OPT_SECRET = 'hci_install_secret';
    private const OPT_STATE = 'hci_conn_state';
    private const OPT_LAST_ATTEMPT = 'hci_conn_last_attempt';
    private const OPT_LAST_REACTIVATE = 'hci_conn_last_reactivate';
    private const LEGACY_URL = 'hci_api_url';
    private const LEGACY_KEY = 'hci_api_key';
    private const PENDING_TRANSIENT = 'hci_activation_pending';
    private const PAGE_ATTEMPT_INTERVAL = 600;
    private const REACTIVATE_INTERVAL = 6 * HOUR_IN_SECONDS;

    public static function init(): void {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    // ------------------------------------------------------------------ آدرس و کلید (هرگز نمایش داده نمی‌شوند)

    public static function url(): string {
        if (defined('HCI_SOURCE_URL') && is_string(HCI_SOURCE_URL) && trim(HCI_SOURCE_URL) !== '') {
            return trim(HCI_SOURCE_URL);
        }
        $built_in = self::built_in_url();
        if ($built_in !== '') {
            return $built_in;
        }
        $stored = trim((string) get_option(self::OPT_URL, ''));
        return $stored !== '' ? $stored : trim((string) get_option(self::LEGACY_URL, ''));
    }

    private static function built_in_url(): string {
        return defined('HCI_DEFAULT_SOURCE_URL') && is_string(HCI_DEFAULT_SOURCE_URL) ? trim(HCI_DEFAULT_SOURCE_URL) : '';
    }

    public static function key(): string {
        if (defined('HCI_API_KEY') && is_string(HCI_API_KEY) && trim(HCI_API_KEY) !== '') {
            return trim(HCI_API_KEY); // راه اضطراری wp-config
        }
        $own = trim((string) get_option(self::OPT_KEY, ''));
        return $own !== '' ? $own : trim((string) get_option(self::LEGACY_KEY, ''));
    }

    private static function has_own_key(): bool {
        return trim((string) get_option(self::OPT_KEY, '')) !== '' || (defined('HCI_API_KEY') && is_string(HCI_API_KEY) && trim(HCI_API_KEY) !== '');
    }

    public static function is_configured(): bool {
        return self::url() !== '' && self::key() !== '';
    }

    public static function install_secret(): string {
        $secret = (string) get_option(self::OPT_SECRET, '');
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
            $secret = bin2hex(random_bytes(32));
            update_option(self::OPT_SECRET, $secret, false);
        }
        return $secret;
    }

    /**
     * هر متنی که ممکن است آدرس/دامنه‌ی مبدا یا کلید را داشته باشد قبل از
     * نمایش/ذخیره/لاگ پاک می‌شود.
     */
    public static function scrub(string $text): string {
        $secrets = array_filter(array(self::url(), self::key(), (string) get_option(self::OPT_URL, ''), (string) get_option(self::LEGACY_URL, ''), (string) get_option(self::LEGACY_KEY, ''), (string) get_option(self::OPT_KEY, '')));
        foreach ($secrets as $secret) {
            $host = parse_url($secret, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $text = str_ireplace($host, '[هی‌مد]', $text);
            }
            $text = str_replace($secret, '[هی‌مد]', $text);
        }
        $text = preg_replace('#https?://\S+#i', '[آدرس]', $text) ?? '';
        return trim($text);
    }

    // ------------------------------------------------------------------ وضعیت

    public static function state(): array {
        $state = get_option(self::OPT_STATE, array());
        return is_array($state) ? $state : array();
    }

    public static function status(): string {
        $status = (string) (self::state()['status'] ?? '');
        if ($status !== '') {
            return $status;
        }
        return self::is_configured() ? self::STATUS_CONNECTED : self::STATUS_NOT_ACTIVATED;
    }

    private static function set_status(string $status): void {
        update_option(self::OPT_STATE, array('status' => $status, 'checked_at' => time()), false);
    }

    public static function is_blocked(): bool {
        return self::status() === self::STATUS_DISABLED;
    }

    public static function mark_disabled(): void {
        self::set_status(self::STATUS_DISABLED);
    }

    public static function blocked_message(): string {
        return 'اتصال شما توسط هی‌مد قطع شده است. تا وقتی هی‌مد دوباره فعالش نکند، قیمت، موجودی و محصولات فروشگاه شما تغییری نمی‌کند.';
    }

    public static function unavailable_message(): string {
        return 'اتصال شما با هی‌مد برقرار نیست. از صفحه «اتصال» دوباره بررسی کنید.';
    }

    /** متن ساده‌ی صفحه‌ی اتصال. */
    public static function describe(): array {
        switch (self::status()) {
            case self::STATUS_CONNECTED:
                return array('ok' => true, 'headline' => 'اتصال شما با هی‌مد برقرار است', 'reason' => '');
            case self::STATUS_DISABLED:
                return array('ok' => false, 'headline' => 'اتصال برقرار نیست', 'reason' => 'اتصال شما توسط هی‌مد قطع شده است. تا وقتی هی‌مد دوباره فعال نکند، چیزی در فروشگاه شما تغییر نمی‌کند. با هی‌مد تماس بگیرید.');
            case self::STATUS_UNREACHABLE:
                return array('ok' => false, 'headline' => 'اتصال برقرار نیست', 'reason' => 'ارتباط با سرور ممکن نشد. چند دقیقه بعد دوباره بررسی کنید.');
            default:
                return array('ok' => false, 'headline' => 'اتصال برقرار نیست', 'reason' => 'هنوز فعال نشده است. اگر تازه افزونه را نصب کرده‌اید، از هی‌مد بخواهید سایت شما را فعال کند و بعد «بررسی دوباره» را بزنید.');
        }
    }

    // ------------------------------------------------------------------ فعال‌سازی

    public static function on_plugin_activation(): void {
        self::refresh(true);
    }

    /**
     * فعال‌سازی: دامنه‌ی خودِ سایت و یک راز تصادفی نصب به مبدا فرستاده می‌شود؛
     * مبدا با یک درخواست برگشتی (HMAC) مالکیت دامنه را می‌سنجد و کلید را یک‌بار می‌دهد.
     *
     * @return array{success:bool,reason:string}
     */
    public static function activate(): array {
        update_option(self::OPT_LAST_ATTEMPT, time(), false);
        $base = self::url();
        if ($base === '') {
            self::set_status(self::STATUS_NOT_ACTIVATED);
            return array('success' => false, 'reason' => 'no_url');
        }

        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $body = (string) wp_json_encode(array('domain' => $host, 'secret' => self::install_secret()));

        // مسیر عمومی HMAC فقط در همین بازه‌ی کوتاه پاسخ می‌دهد.
        set_transient(self::PENDING_TRANSIENT, 1, 120);
        try {
            $response = wp_remote_post(rtrim($base, '/') . '/activate', array(
                'timeout' => 20,
                'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                'body' => $body,
            ));
        } finally {
            delete_transient(self::PENDING_TRANSIENT);
        }

        if (is_wp_error($response)) {
            self::set_status_if_not_working(self::STATUS_UNREACHABLE);
            return array('success' => false, 'reason' => 'unreachable');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $api_key = is_array($json) ? (string) ($json['data']['api_key'] ?? '') : '';
        if ($status !== 200 || $api_key === '') {
            self::set_status_if_not_working($status === 429 || $status >= 500 ? self::STATUS_UNREACHABLE : self::STATUS_NOT_ACTIVATED);
            return array('success' => false, 'reason' => $status === 403 ? 'rejected' : 'unreachable');
        }

        // کلید اختصاصی ذخیره می‌شود؛ مقدار دستی قبلی پاک می‌شود. اگر آدرس ثابت
        // داخل پلاگین/wp-config نیست، همان آدرس قبلی در گزینه‌ی خصوصی می‌ماند.
        if (!(defined('HCI_SOURCE_URL') && trim((string) HCI_SOURCE_URL) !== '') && self::built_in_url() === '') {
            update_option(self::OPT_URL, $base, false);
        } else {
            delete_option(self::OPT_URL);
        }
        update_option(self::OPT_KEY, $api_key, false);
        delete_option(self::LEGACY_URL);
        delete_option(self::LEGACY_KEY);
        self::set_status(self::STATUS_CONNECTED);
        return array('success' => true, 'reason' => '');
    }

    /** اگر اتصال قدیمی (کلید دستی) هنوز کار می‌کند، شکست فعال‌سازی وضعیتش را خراب نکند. */
    private static function set_status_if_not_working(string $status): void {
        if (self::status() === self::STATUS_CONNECTED && self::key() !== '' && !self::has_own_key()) {
            return;
        }
        if (self::status() === self::STATUS_DISABLED) {
            return;
        }
        self::set_status($status);
    }

    public static function allow_reactivation(): bool {
        $last = (int) get_option(self::OPT_LAST_REACTIVATE, 0);
        if ($last > time() - self::REACTIVATE_INTERVAL) {
            return false;
        }
        update_option(self::OPT_LAST_REACTIVATE, time(), false);
        return true;
    }

    /**
     * بررسی اتصال. $force از دکمه «بررسی دوباره»/فعال‌سازی افزونه می‌آید؛ بدون آن
     * (باز شدن صفحه) تلاش فعال‌سازی حداکثر هر ۱۰ دقیقه است.
     */
    public static function refresh(bool $force = false): string {
        if (self::url() === '') {
            self::set_status(self::STATUS_NOT_ACTIVATED);
            return self::STATUS_NOT_ACTIVATED;
        }

        if (!self::has_own_key()) {
            $last = (int) get_option(self::OPT_LAST_ATTEMPT, 0);
            if ($force || $last <= time() - self::PAGE_ATTEMPT_INTERVAL) {
                if (self::activate()['success']) {
                    return self::STATUS_CONNECTED;
                }
            }
            if (self::key() === '') {
                if (self::status() !== self::STATUS_UNREACHABLE) {
                    self::set_status(self::STATUS_NOT_ACTIVATED);
                }
                return self::status();
            }
            // کلید دستی قدیمی هنوز هست: وضعیت واقعی را از health می‌گیریم.
        }

        $probe = HCI_Source_Client::probe();
        if ($probe['code'] === 'hmw_api_invalid_key' || $probe['code'] === 'hmw_api_missing_key') {
            if (($force || self::allow_reactivation()) && self::activate()['success']) {
                return self::STATUS_CONNECTED;
            }
            if (!self::has_own_key() || $probe['code'] !== '') {
                self::set_status(self::STATUS_NOT_ACTIVATED);
            }
            return self::status();
        }
        if ($probe['code'] === 'hmw_site_disabled') {
            self::mark_disabled();
            return self::STATUS_DISABLED;
        }
        self::set_status($probe['ok'] ? self::STATUS_CONNECTED : self::STATUS_UNREACHABLE);
        return self::status();
    }

    /** باز شدن صفحه‌ی اتصال: فقط وقتی وصل نیست و حداکثر هر ۱۰ دقیقه. */
    public static function refresh_on_page_open(): void {
        if (self::status() === self::STATUS_CONNECTED) {
            return;
        }
        $last = (int) get_option(self::OPT_LAST_ATTEMPT, 0);
        if ($last > time() - self::PAGE_ATTEMPT_INTERVAL) {
            return;
        }
        self::refresh(false);
        update_option(self::OPT_LAST_ATTEMPT, time(), false);
    }

    // ------------------------------------------------------------------ مسیر عمومی اثبات مالکیت

    public static function register_routes(): void {
        register_rest_route('hci/v1', '/ownership', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'ownership_proof'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * HMAC(راز نصب، challenge). خودِ راز هرگز برنمی‌گردد. فقط در بازه‌ی کوتاهِ
     * فعال‌سازی و با محدودیت تعداد در دقیقه پاسخ می‌دهد.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function ownership_proof(WP_REST_Request $request) {
        if (get_transient(self::PENDING_TRANSIENT) === false) {
            return new WP_Error('rest_no_route', 'No route was found.', array('status' => 404));
        }
        $challenge = (string) $request->get_param('challenge');
        if (!preg_match('/^[a-f0-9]{32,128}$/', $challenge)) {
            return new WP_Error('hci_bad_challenge', 'Bad request.', array('status' => 400));
        }
        if (!self::allow_proof_request()) {
            return new WP_Error('hci_rate_limited', 'Too many requests.', array('status' => 429));
        }
        return new WP_REST_Response(array('proof' => hash_hmac('sha256', $challenge, self::install_secret())), 200);
    }

    private static function allow_proof_request(): bool {
        $key = 'hci_proof_rate';
        $state = get_transient($key);
        $now = time();
        if (!is_array($state) || $now - (int) $state['start'] >= 60) {
            $state = array('start' => $now, 'count' => 0);
        }
        if ((int) $state['count'] >= 10) {
            return false;
        }
        $state['count']++;
        set_transient($key, $state, 60);
        return true;
    }
}
