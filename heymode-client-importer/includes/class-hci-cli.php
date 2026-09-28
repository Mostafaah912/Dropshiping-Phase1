<?php

defined('ABSPATH') || exit;

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * دستورهای WP-CLI پلاگین HeyMode Client Importer.
 */
final class HCI_CLI {
    /**
     * سینک روزانه قیمت/موجودی را همین حالا اجرا می‌کند — دقیقاً همان منطق
     * دکمه «Sync Now» در پنل ادمین. برای سایت‌های کم‌بازدید که به Action
     * Scheduler (که خودش با بازدید واقعی کاربر Pseudo-Cron وردپرس را اجرا
     * می‌کند) نمی‌شود به‌تنهایی متکی بود، این دستور را با یک Cron واقعی سرور
     * صدا بزنید:
     *
     *     0 6 * * * cd /path/to/wordpress && wp hci sync --quiet
     *
     * ## EXAMPLES
     *
     *     wp hci sync
     *
     * @when after_wp_load
     */
    public function sync(array $args, array $assoc_args): void {
        if (!class_exists('HCI_Sync')) {
            WP_CLI::error('پلاگین HeyMode Client Importer فعال نیست یا بارگذاری نشده.');
            return;
        }

        $summary = HCI_Sync::run_daily_sync(true);

        if (empty($summary['success'])) {
            WP_CLI::error('سینک ناموفق بود: ' . (string) ($summary['message'] ?? ''));
            return;
        }

        WP_CLI::success(sprintf(
            'سینک با موفقیت انجام شد — بررسی‌شده: %d، قیمت به‌روزشده: %d، موجودی به‌روزشده: %d، Out of Stock شده: %d، خطاها: %d',
            (int) ($summary['checked'] ?? 0),
            (int) ($summary['price_updated'] ?? 0),
            (int) ($summary['stock_updated'] ?? 0),
            (int) ($summary['deactivated'] ?? 0),
            (int) ($summary['errors'] ?? 0)
        ));
    }
}

WP_CLI::add_command('hci', 'HCI_CLI');
