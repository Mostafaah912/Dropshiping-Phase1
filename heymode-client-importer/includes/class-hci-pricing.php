<?php

defined('ABSPATH') || exit;

final class HCI_Pricing {
    private const FIXED_AMOUNT_OPTION = 'hci_price_fixed_amount';
    private const PERCENT_OPTION = 'hci_price_percent';
    private const DEFAULT_STATUS_OPTION = 'hci_default_post_status';
    private const ALLOWED_STATUSES = array('draft', 'publish');

    public static function get_fixed_amount(): float {
        return (float) get_option(self::FIXED_AMOUNT_OPTION, 0);
    }

    public static function get_percent(): float {
        return (float) get_option(self::PERCENT_OPTION, 0);
    }

    public static function get_default_post_status(): string {
        $status = (string) get_option(self::DEFAULT_STATUS_OPTION, 'draft');
        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'draft';
    }

    public static function save(float $fixed_amount, float $percent, string $default_status): void {
        update_option(self::FIXED_AMOUNT_OPTION, $fixed_amount, false);
        update_option(self::PERCENT_OPTION, $percent, false);
        update_option(
            self::DEFAULT_STATUS_OPTION,
            in_array($default_status, self::ALLOWED_STATUSES, true) ? $default_status : 'draft',
            false
        );
    }

    /**
     * لایه‌ی resolve فرمول قیمت. فعلاً فقط فرمول سراسری را برمی‌گرداند؛ پارامتر
     * $category_id برای Override بعدی per-category در فاز ۲/۳ نگه داشته شده و
     * در این فاز نادیده گرفته می‌شود.
     */
    public static function resolve_price(float $hmp_price, ?int $category_id = null): float {
        return self::apply_global_formula($hmp_price);
    }

    private static function apply_global_formula(float $hmp_price): float {
        return ($hmp_price + self::get_fixed_amount()) * (1 + (self::get_percent() / 100));
    }
}
