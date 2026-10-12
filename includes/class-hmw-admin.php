<?php

defined('ABSPATH') || exit;

final class HMW_Admin {
    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_hmw_test_connection', array(__CLASS__, 'handle_test_connection'));
        add_action('admin_post_hmw_start_full_sync', array(__CLASS__, 'handle_start_full_sync'));
        add_action('admin_post_hmw_cancel_full_sync', array(__CLASS__, 'handle_cancel_full_sync'));
        add_action('admin_post_hmw_generate_api_key', array(__CLASS__, 'handle_generate_api_key'));
        add_action('admin_post_hmw_revoke_api_key', array(__CLASS__, 'handle_revoke_api_key'));
        add_action('admin_post_hmw_save_api_ip_allowlist', array(__CLASS__, 'handle_save_api_ip_allowlist'));
        add_action('admin_post_hmw_self_test_rest', array(__CLASS__, 'handle_self_test_rest'));
        add_action('admin_post_hmw_site_add', array(__CLASS__, 'handle_site_add'));
        add_action('admin_post_hmw_site_toggle', array(__CLASS__, 'handle_site_toggle'));
        add_action('admin_post_hmw_site_reissue', array(__CLASS__, 'handle_site_reissue'));
        add_action('admin_post_hmw_site_delete', array(__CLASS__, 'handle_site_delete'));
        add_action('admin_post_hmw_legacy_toggle', array(__CLASS__, 'handle_legacy_toggle'));
        add_action('wp_ajax_hmw_full_tick', array(__CLASS__, 'ajax_full_tick'));
        add_action('hmw_daily_full_sync', array('HMW_Sync', 'cron_full'));
        add_action('hmw_full_continue', array('HMW_Sync', 'cron_full_continue'));
    }

    public static function admin_menu(): void {
        $hook = add_menu_page(
            'HeyMode Wholesale',
            'HeyMode Wholesale',
            'manage_options',
            'heymode-wholesale',
            array(__CLASS__, 'render_page'),
            'dashicons-store',
            58
        );

        add_submenu_page(
            'heymode-wholesale',
            'API مشتری',
            'API مشتری',
            'manage_options',
            'heymode-wholesale-api',
            array(__CLASS__, 'render_api_page')
        );

        add_submenu_page(
            'heymode-wholesale',
            'سایت‌های مشتری',
            'سایت‌های مشتری',
            'manage_options',
            'heymode-wholesale-sites',
            array(__CLASS__, 'render_sites_page')
        );
    }

    public static function render_sites_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'heymode-wholesale'));
        }
        HMW_DB::ensure_schema();
        $sites = HMW_Sites::all();
        $notice = isset($_GET['hmw_sites_notice']) ? sanitize_key(wp_unslash($_GET['hmw_sites_notice'])) : '';
        $notice_texts = array(
            'added' => array('success', 'دامنه ثبت شد و آماده فعال‌سازی است.'),
            'invalid' => array('error', 'نام دامنه معتبر نیست.'),
            'duplicate' => array('error', 'این دامنه قبلاً ثبت شده است.'),
            'toggled' => array('success', 'وضعیت سایت تغییر کرد و همان لحظه اعمال شد.'),
            'reissued' => array('warning', 'کلید قبلی باطل شد. سایت مشتری در بررسی بعدی خودکار دوباره فعال می‌شود.'),
            'deleted' => array('success', 'سایت حذف شد.'),
            'legacy_on' => array('success', 'کلید مشترک قدیمی دوباره روشن شد.'),
            'legacy_off' => array('warning', 'کلید مشترک قدیمی خاموش شد.'),
            'legacy_need_confirm' => array('error', 'برای خاموش‌کردن کلید قدیمی باید تأیید را تیک بزنید.'),
        );
        $status_labels = array(
            HMW_Sites::STATUS_PENDING => array('در انتظار فعال‌سازی', '#b26b00'),
            HMW_Sites::STATUS_ACTIVE => array('فعال', '#008a20'),
            HMW_Sites::STATUS_DISABLED => array('غیرفعال', '#b32d2e'),
        );
        $legacy_on = HMW_REST_API::legacy_enabled();
        $pending_count = count(array_filter($sites, static fn (array $s): bool => $s['status'] !== HMW_Sites::STATUS_ACTIVE));
        $fmt = static function ($utc): string {
            $ts = !empty($utc) ? strtotime((string) $utc . ' UTC') : false;
            return $ts ? wp_date('Y-m-d H:i', $ts, new DateTimeZone(HMW_TIMEZONE)) : '-';
        };
        ?>
        <div class="wrap">
            <h1>HeyMode Wholesale — سایت‌های مشتری</h1>
            <?php if (isset($notice_texts[$notice])) : ?>
                <div class="notice notice-<?php echo esc_attr($notice_texts[$notice][0]); ?> is-dismissible"><p><?php echo esc_html($notice_texts[$notice][1]); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:16px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="action" value="hmw_site_add">
                <?php wp_nonce_field('hmw_site_add'); ?>
                <input type="text" name="domain" placeholder="example.com" required dir="ltr" style="min-width:260px">
                <input type="text" name="note" placeholder="یادداشت (اختیاری)" style="min-width:220px">
                <button class="button button-primary" type="submit">افزودن دامنه</button>
            </form>
            <p class="description">دامنه‌ای که اینجا ثبت شود «آماده فعال‌سازی» است؛ افزونه‌ی همان سایت در اولین اجرا خودکار کلید اختصاصی می‌گیرد. کلید هرگز نمایش داده نمی‌شود.</p>

            <table class="widefat striped" style="max-width:1300px">
                <thead><tr><th>دامنه</th><th>وضعیت</th><th>پیشوند کلید</th><th>ایجاد</th><th>فعال‌سازی</th><th>آخرین ارتباط</th><th>آخرین سینک</th><th>یادداشت</th><th>عملیات</th></tr></thead>
                <tbody>
                <?php if (!$sites) : ?><tr><td colspan="9">هنوز سایتی ثبت نشده است.</td></tr><?php endif; ?>
                <?php foreach ($sites as $site) : ?>
                    <?php [$label, $color] = $status_labels[$site['status']] ?? array($site['status'], '#50575e'); ?>
                    <tr>
                        <td dir="ltr"><?php echo esc_html((string) $site['domain']); ?></td>
                        <td style="color:<?php echo esc_attr($color); ?>;font-weight:bold"><?php echo esc_html($label); ?></td>
                        <td><code><?php echo $site['key_prefix'] ? esc_html((string) $site['key_prefix']) . '…' : '-'; ?></code></td>
                        <td><?php echo esc_html($fmt($site['created_at'])); ?></td>
                        <td><?php echo esc_html($fmt($site['activated_at'])); ?></td>
                        <td><?php echo esc_html($fmt($site['last_seen_at'])); ?></td>
                        <td><?php echo esc_html($fmt($site['last_sync_at'])); ?></td>
                        <td><?php echo esc_html((string) ($site['note'] ?? '')); ?></td>
                        <td style="white-space:nowrap">
                            <?php foreach (array(
                                array('hmw_site_toggle', $site['status'] === HMW_Sites::STATUS_DISABLED ? 'فعال کردن' : 'غیرفعال کردن', ''),
                                array('hmw_site_reissue', 'صدور دوباره', 'کلید فعلی این سایت همین لحظه باطل می‌شود. ادامه می‌دهید؟'),
                                array('hmw_site_delete', 'حذف', 'این سایت حذف شود؟'),
                            ) as [$action, $text, $confirm]) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"<?php echo $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ');"' : ''; ?>>
                                    <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                                    <input type="hidden" name="site_id" value="<?php echo esc_attr((string) $site['id']); ?>">
                                    <?php wp_nonce_field($action); ?>
                                    <button class="button button-small" type="submit"><?php echo esc_html($text); ?></button>
                                </form>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px">کلید مشترک قدیمی</h2>
            <p>وضعیت: <strong style="color:<?php echo $legacy_on ? '#008a20' : '#b32d2e'; ?>"><?php echo $legacy_on ? 'روشن' : 'خاموش'; ?></strong>
                — تا وقتی روشن باشد، سایت‌هایی که هنوز کلید اختصاصی نگرفته‌اند با کلید قدیمی کار می‌کنند.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hmw_legacy_toggle">
                <?php wp_nonce_field('hmw_legacy_toggle'); ?>
                <?php if ($legacy_on) : ?>
                    <input type="hidden" name="enable" value="0">
                    <p><label><input type="checkbox" name="confirm" value="1"> می‌دانم که <?php echo esc_html((string) $pending_count); ?> سایت هنوز فعال اختصاصی نشده است و با خاموش‌کردن کلید قدیمی ممکن است قطع شوند.</label></p>
                    <button class="button" type="submit">خاموش کردن کلید قدیمی</button>
                <?php else : ?>
                    <input type="hidden" name="enable" value="1">
                    <button class="button button-primary" type="submit">روشن کردن دوباره کلید قدیمی (راه برگشت)</button>
                <?php endif; ?>
            </form>
            <p class="description">راه اضطراری: افزودن <code>define('HMW_FORCE_LEGACY_KEY', true);</code> به wp-config.php کلید قدیمی را همیشه روشن می‌کند.</p>

            <h2 style="margin-top:28px">درخواست‌های ردشده‌ی اخیر</h2>
            <?php $counts = HMW_Sites::reject_counts(); $log = HMW_Sites::reject_log(); ?>
            <p><?php foreach ($counts as $reason => $info) : ?><span style="margin-left:14px"><code><?php echo esc_html((string) $reason); ?></code>: <?php echo esc_html((string) (int) ($info['count'] ?? 0)); ?></span><?php endforeach; ?><?php if (!$counts) : ?>موردی ثبت نشده است.<?php endif; ?></p>
            <?php if ($log) : ?>
                <table class="widefat striped" style="max-width:900px"><thead><tr><th>زمان (UTC)</th><th>علت</th><th>دامنه</th><th>IP</th></tr></thead><tbody>
                <?php foreach (array_slice($log, 0, 20) as $entry) : ?>
                    <tr><td><?php echo esc_html((string) ($entry['at'] ?? '')); ?></td><td><?php echo esc_html((string) ($entry['reason'] ?? '')); ?></td><td dir="ltr"><?php echo esc_html((string) ($entry['domain'] ?? '-')); ?></td><td dir="ltr"><?php echo esc_html((string) ($entry['ip'] ?? '')); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function sites_redirect(string $notice): void {
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale-sites', 'hmw_sites_notice' => $notice), admin_url('admin.php')));
        exit;
    }

    public static function handle_site_add(): void {
        self::guard('hmw_site_add');
        $result = HMW_Sites::add(
            isset($_POST['domain']) ? (string) wp_unslash($_POST['domain']) : '',
            isset($_POST['note']) ? (string) wp_unslash($_POST['note']) : ''
        );
        self::sites_redirect($result['success'] ? 'added' : (strpos((string) $result['message'], 'قبلاً') !== false ? 'duplicate' : 'invalid'));
    }

    public static function handle_site_toggle(): void {
        self::guard('hmw_site_toggle');
        $id = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
        $row = HMW_Sites::find($id);
        if ($row) {
            HMW_Sites::set_status($id, $row['status'] === HMW_Sites::STATUS_DISABLED ? HMW_Sites::STATUS_ACTIVE : HMW_Sites::STATUS_DISABLED);
        }
        self::sites_redirect('toggled');
    }

    public static function handle_site_reissue(): void {
        self::guard('hmw_site_reissue');
        HMW_Sites::reissue(isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0);
        self::sites_redirect('reissued');
    }

    public static function handle_site_delete(): void {
        self::guard('hmw_site_delete');
        HMW_Sites::delete(isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0);
        self::sites_redirect('deleted');
    }

    public static function handle_legacy_toggle(): void {
        self::guard('hmw_legacy_toggle');
        $enable = !empty($_POST['enable']);
        $ok = HMW_REST_API::set_legacy_enabled($enable, !empty($_POST['confirm']));
        self::sites_redirect($ok ? ($enable ? 'legacy_on' : 'legacy_off') : 'legacy_need_confirm');
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'heymode-wholesale'));
        }
        HMW_DB::ensure_schema();
        global $wpdb;

        $products_table = HMW_DB::products_table();
        $logs_table = HMW_DB::sync_logs_table();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products_table}");
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products_table} WHERE is_active = 1");
        $parents = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products_table} WHERE parent_product_id IS NULL");
        $variations = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$products_table} WHERE product_type = 'variation'");
        $last_log = $wpdb->get_row("SELECT * FROM {$logs_table} WHERE sync_type = 'full' ORDER BY id DESC LIMIT 1");
        $full = HMW_Sync::get_state();
        $next_cron = wp_next_scheduled('hmw_daily_full_sync');
        $notice = isset($_GET['hmw_notice']) ? sanitize_text_field(wp_unslash($_GET['hmw_notice'])) : '';
        $is_full_running = (($full['status'] ?? 'idle') === 'running');
        $last_full_display = 'هنوز اجرا نشده';
        if ($last_log && !empty($last_log->started_at)) {
            $ts = strtotime((string) $last_log->started_at . ' UTC');
            if ($ts !== false) {
                $last_full_display = $last_log->status . ' / ' . wp_date('Y-m-d H:i:s', $ts, new DateTimeZone(HMW_TIMEZONE));
            }
        }
        ?>
        <div class="wrap">
            <h1>HeyMode Wholesale — v<?php echo esc_html(HMW_VERSION); ?></h1>

            <?php if ($notice === 'full_started') : ?><div class="notice notice-success is-dismissible"><p>Full Sync شروع شد.</p></div><?php endif; ?>
            <?php if ($notice === 'full_cancelled') : ?><div class="notice notice-warning is-dismissible"><p>Full Sync لغو شد.</p></div><?php endif; ?>
            <?php if ($notice === 'error') : ?><div class="notice notice-error is-dismissible"><p>اجرای Full Sync شروع نشد. جزئیات در لاگ Sync ثبت می‌شود.</p></div><?php endif; ?>

            <table class="widefat striped" style="max-width:1100px;margin:0 0 22px">
                <tbody>
                    <tr><td style="width:260px"><strong>جدول محصولات</strong></td><td><code><?php echo esc_html($products_table); ?></code></td></tr>
                    <tr><td><strong>کل رکوردها</strong></td><td><?php echo esc_html(number_format_i18n($total)); ?></td></tr>
                    <tr><td><strong>رکوردهای فعال</strong></td><td><?php echo esc_html(number_format_i18n($active)); ?></td></tr>
                    <tr><td><strong>محصولات اصلی</strong></td><td><?php echo esc_html(number_format_i18n($parents)); ?></td></tr>
                    <tr><td><strong>Variationها</strong></td><td><?php echo esc_html(number_format_i18n($variations)); ?></td></tr>
                    <tr><td><strong>آخرین Full Sync</strong></td><td><?php echo $last_log ? esc_html($last_full_display) : 'هنوز اجرا نشده'; ?></td></tr>
                </tbody>
            </table>

            <h2>اتصال به WooCommerce هی‌مد</h2>
            <table class="widefat striped" style="max-width:1100px;margin:0 0 16px">
                <tbody>
                    <tr><td style="width:260px"><strong>منبع</strong></td><td><?php echo defined('HMW_SOURCE_URL') ? esc_html(HMW_SOURCE_URL) : '<span style="color:#b32d2e">تعریف نشده</span>'; ?></td></tr>
                    <tr><td><strong>Credential</strong></td><td><?php echo HMW_Source_API::is_configured() ? '<span style="color:#008a20">تنظیم شده</span>' : '<span style="color:#b32d2e">تنظیم نشده</span>'; ?></td></tr>
                    <tr><td><strong>روش همگام‌سازی</strong></td><td><strong>Full Sync کل محصولات و Variationها</strong></td></tr>
                    <tr><td><strong>Cron</strong></td><td>هر روز ساعت 04:00 و اجرای Full Sync با ادامه خودکار تا پایان</td></tr>
                </tbody>
            </table>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:22px">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="hmw_test_connection">
                    <?php wp_nonce_field('hmw_test_connection'); ?>
                    <button type="submit" class="button">تست اتصال</button>
                </form>

                <?php if ($is_full_running) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="hmw_cancel_full_sync">
                        <?php wp_nonce_field('hmw_cancel_full_sync'); ?>
                        <button type="submit" class="button">لغو Full Sync</button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Full Sync کل محصولات منتشرشده و تمام Variationهای آن‌ها را دوباره از منبع می‌گیرد. ادامه می‌دهید؟');">
                        <input type="hidden" name="action" value="hmw_start_full_sync">
                        <?php wp_nonce_field('hmw_start_full_sync'); ?>
                        <button type="submit" class="button button-primary">شروع Full Sync</button>
                    </form>
                <?php endif; ?>
            </div>

            <h2>وضعیت Cron و Full Sync</h2>
            <table class="widefat striped" style="max-width:1100px;margin:0 0 20px">
                <tbody>
                    <tr><td style="width:260px"><strong>وضعیت فعلی</strong></td><td><?php echo esc_html($full['status'] ?? 'idle'); ?></td></tr>
                    <tr><td><strong>Cron بعدی</strong></td><td><?php echo $next_cron ? esc_html(wp_date('Y-m-d H:i:s', $next_cron, new DateTimeZone(HMW_TIMEZONE))) . ' (تهران)' : 'تنظیم نشده'; ?></td></tr>
                    <tr><td><strong>الگوی اجرا</strong></td><td>Products → Variationها → ثبت و به‌روزرسانی جدول → پایان Full Sync؛ در Cron ادامه به‌صورت خودکار انجام می‌شود.</td></tr>
                </tbody>
            </table>

            <h2>چند Full Sync آخر</h2>
            <table class="widefat striped" style="max-width:1100px;margin:0 0 20px">
                <thead><tr><th>شروع (تهران)</th><th>پایان (تهران)</th><th>وضعیت</th><th>دریافت‌شده</th><th>به‌روزشده</th><th>غیرفعال‌شده</th><th>خطا</th></tr></thead>
                <tbody>
                    <?php $recent_full = HMW_DB::get_recent_full_syncs(10); ?>
                    <?php if (!$recent_full) : ?><tr><td colspan="7">هنوز Full Syncی اجرا نشده.</td></tr><?php endif; ?>
                    <?php foreach ($recent_full as $log_row) : ?>
                        <?php
                        $fmt_log = static function ($utc): string {
                            $t = !empty($utc) ? strtotime((string) $utc . ' UTC') : false;
                            return $t ? wp_date('Y-m-d H:i:s', $t, new DateTimeZone(HMW_TIMEZONE)) : '-';
                        };
                        ?>
                        <tr>
                            <td><?php echo esc_html($fmt_log($log_row['started_at'] ?? '')); ?></td>
                            <td><?php echo esc_html($fmt_log($log_row['finished_at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($log_row['status'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) (int) ($log_row['products_fetched'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) (int) ($log_row['products_updated'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) (int) ($log_row['products_deactivated'] ?? 0)); ?></td>
                            <td><?php echo esc_html((string) (int) ($log_row['errors_count'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="hmw-live-box" style="max-width:1100px;<?php echo $is_full_running ? '' : 'display:none;'; ?>">
                <table class="widefat striped">
                    <tbody>
                        <tr><td style="width:260px"><strong>Phase</strong></td><td id="hmw-phase">-</td></tr>
                        <tr><td><strong>Page</strong></td><td id="hmw-page">0 / 0</td></tr>
                        <tr><td><strong>Variation Parent</strong></td><td id="hmw-parent">0 / 0</td></tr>
                        <tr><td><strong>رکوردهای دریافت‌شده</strong></td><td id="hmw-fetched">0</td></tr>
                        <tr><td><strong>New</strong></td><td id="hmw-inserted">0</td></tr>
                        <tr><td><strong>Updated</strong></td><td id="hmw-updated">0</td></tr>
                        <tr><td><strong>Unchanged</strong></td><td id="hmw-unchanged">0</td></tr>
                        <tr><td><strong>Deactivated</strong></td><td id="hmw-deactivated">0</td></tr>
                        <tr><td><strong>Errors</strong></td><td id="hmw-errors">0</td></tr>
                        <tr><td><strong>API Requests</strong></td><td id="hmw-api">0</td></tr>
                    </tbody>
                </table>
                <p id="hmw-message" style="font-weight:600"></p>
            </div>
        </div>

        <?php if ($is_full_running) : ?>
        <script>
        (function(){
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const nonce = <?php echo wp_json_encode(wp_create_nonce('hmw_full_tick')); ?>;
            let stopped = false;
            function render(s){
                document.getElementById('hmw-phase').textContent = s.phase || '-';
                document.getElementById('hmw-page').textContent = (s.page || 0) + ' / ' + (s.total_pages || 0);
                document.getElementById('hmw-parent').textContent = (s.phase === 'variations' ? ((s.variation_parent_index || 0) + 1) : 0) + ' / ' + (s.variation_parent_total || 0);
                document.getElementById('hmw-fetched').textContent = s.products_fetched || 0;
                document.getElementById('hmw-inserted').textContent = s.products_inserted || 0;
                document.getElementById('hmw-updated').textContent = s.products_updated || 0;
                document.getElementById('hmw-unchanged').textContent = s.products_unchanged || 0;
                document.getElementById('hmw-deactivated').textContent = s.products_deactivated || 0;
                document.getElementById('hmw-errors').textContent = s.errors_count || 0;
                document.getElementById('hmw-api').textContent = s.api_requests || 0;
            }
            function tick(){
                if(stopped)return;
                const body = new URLSearchParams();
                body.set('action', 'hmw_full_tick');
                body.set('_ajax_nonce', nonce);
                fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()})
                .then(async r => { const text = await r.text(); let d; try { d = JSON.parse(text); } catch(e) { throw new Error('HTTP '+r.status+' - '+text.slice(0,500)); } return d; })
                .then(d => {
                    if(!d || !d.success) throw new Error(d && d.data && d.data.message ? d.data.message : 'خطای نامشخص');
                    render(d.data.state);
                    if(d.data.finished){
                        stopped = true;
                        document.getElementById('hmw-message').textContent = 'Full Sync پایان یافت. صفحه را Refresh کنید.';
                        return;
                    }
                    setTimeout(tick, d.data.busy ? 1000 : 250);
                })
                .catch(e => { stopped = true; document.getElementById('hmw-message').textContent = 'خطا: '+e.message; });
            }
            render(<?php echo wp_json_encode($full); ?>);
            tick();
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    public static function render_api_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'heymode-wholesale'));
        }

        $plain_key = get_transient('hmw_api_plaintext_key_' . get_current_user_id());
        if ($plain_key !== false) {
            delete_transient('hmw_api_plaintext_key_' . get_current_user_id());
        }

        $configured = HMW_REST_API::is_configured();
        $prefix = HMW_REST_API::get_key_prefix();
        $created_at = HMW_REST_API::get_key_created_at();
        $allowlist = HMW_REST_API::get_ip_allowlist();
        $notice = isset($_GET['hmw_api_notice']) ? sanitize_key(wp_unslash($_GET['hmw_api_notice'])) : '';
        $self_test = get_transient('hmw_self_test_' . get_current_user_id());
        if ($self_test !== false) {
            delete_transient('hmw_self_test_' . get_current_user_id());
        }
        $base = rest_url('hmw/v1');
        ?>
        <div class="wrap">
            <h1>HeyMode Wholesale — API مشتری</h1>

            <?php if ($notice === 'generated') : ?>
                <div class="notice notice-success"><p>API Key جدید ساخته شد. کلید کامل فقط همین یک بار نمایش داده می‌شود.</p></div>
            <?php elseif ($notice === 'revoked') : ?>
                <div class="notice notice-warning is-dismissible"><p>API Key قبلی باطل شد.</p></div>
            <?php elseif ($notice === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p>محدودیت IP ذخیره شد.</p></div>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:1100px;margin-bottom:22px">
                <tbody>
                    <tr><td style="width:260px"><strong>وضعیت API</strong></td><td><?php echo $configured ? '<span style="color:#008a20">فعال</span>' : '<span style="color:#b32d2e">فعال نشده</span>'; ?></td></tr>
                    <tr><td><strong>Endpoint پایه</strong></td><td><code><?php echo esc_html($base); ?></code></td></tr>
                    <tr><td><strong>Endpoint محصولات</strong></td><td><code><?php echo esc_html($base . '/products'); ?></code></td></tr>
                    <tr><td><strong>Endpoint محصول تکی</strong></td><td><code><?php echo esc_html($base . '/products/{source_product_id}'); ?></code></td></tr>
                    <tr><td><strong>Endpoint Health</strong></td><td><code><?php echo esc_html($base . '/health'); ?></code></td></tr>
                    <tr><td><strong>Authentication</strong></td><td><code>Authorization: Bearer YOUR_API_KEY</code></td></tr>
                    <tr><td><strong>Rate Limit</strong></td><td><?php echo esc_html((string) 120); ?> درخواست در هر 60 ثانیه برای هر IP</td></tr>
                    <?php if ($configured) : ?>
                        <tr><td><strong>Prefix کلید</strong></td><td><code><?php echo esc_html($prefix); ?>…</code></td></tr>
                        <tr><td><strong>تاریخ ساخت</strong></td><td><?php echo esc_html($created_at); ?> UTC</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (is_array($self_test)) : ?>
                <div class="notice <?php echo !empty($self_test['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="padding:12px">
                    <p><strong>نتیجه بررسی سلامت REST API:</strong> <?php echo esc_html((string) ($self_test['message'] ?? '')); ?></p>
                    <?php if (!empty($self_test['url'])) : ?><p><code><?php echo esc_html((string) $self_test['url']); ?></code><?php if (isset($self_test['status'])) : ?> — HTTP <?php echo esc_html((string) $self_test['status']); ?><?php endif; ?></p><?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:22px">
                <input type="hidden" name="action" value="hmw_self_test_rest">
                <?php wp_nonce_field('hmw_self_test_rest'); ?>
                <button type="submit" class="button">بررسی سلامت REST API (خودِ این سایت)</button>
                <p class="description">یک درخواست Loopback به Endpoint سلامت خودِ همین سایت می‌زند تا مشخص شود آیا مسیر REST این پلاگین اصلاً توسط وردپرس شناخته می‌شود — قبل از این‌که سایت مشتری امتحان کند.</p>
            </form>

            <?php if ($plain_key !== false && is_string($plain_key) && $plain_key !== '') : ?>
                <div class="notice notice-warning" style="padding:16px">
                    <p><strong>API Key جدید — فقط همین یک بار:</strong></p>
                    <p><input type="text" readonly value="<?php echo esc_attr($plain_key); ?>" style="width:100%;max-width:900px;font-family:monospace" onclick="this.select();"></p>
                    <p>این کلید را در سایت مشتری ذخیره کنید. داخل URL یا Query String ارسال نشود.</p>
                </div>
            <?php endif; ?>

            <h2>مدیریت API Key</h2>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo $configured ? 'ساخت کلید جدید، کلید فعلی را فوراً باطل می‌کند. ادامه می‌دهید؟' : 'API Key ساخته شود؟'; ?>');">
                    <input type="hidden" name="action" value="hmw_generate_api_key">
                    <?php wp_nonce_field('hmw_generate_api_key'); ?>
                    <button class="button button-primary" type="submit"><?php echo $configured ? 'تعویض API Key' : 'ساخت API Key'; ?></button>
                </form>
                <?php if ($configured) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('API Key فوراً باطل شود؟');">
                        <input type="hidden" name="action" value="hmw_revoke_api_key">
                        <?php wp_nonce_field('hmw_revoke_api_key'); ?>
                        <button class="button" type="submit">ابطال API Key</button>
                    </form>
                <?php endif; ?>
            </div>

            <h2>محدودیت IP سایت مشتری</h2>
            <p>اختیاری است. در صورت خالی بودن، هر IP که API Key معتبر داشته باشد می‌تواند درخواست بدهد.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
                <input type="hidden" name="action" value="hmw_save_api_ip_allowlist">
                <?php wp_nonce_field('hmw_save_api_ip_allowlist'); ?>
                <textarea name="ip_allowlist" rows="6" style="width:100%;font-family:monospace" placeholder="مثال:\n1.2.3.4\n5.6.7.8"><?php echo esc_textarea(implode("\n", $allowlist)); ?></textarea>
                <p><button class="button" type="submit">ذخیره IPهای مجاز</button></p>
            </form>

            <h2>نمونه درخواست</h2>
            <pre style="background:#1e1e1e;color:#fff;padding:16px;overflow:auto;max-width:1100px">curl -H "Authorization: Bearer YOUR_API_KEY" "<?php echo esc_html($base . '/products?page=1&per_page=100'); ?>"</pre>

            <h2>فیلترها</h2>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>پارامتر</th><th>کاربرد</th><th>نمونه</th></tr></thead>
                <tbody>
                    <tr><td><code>page</code></td><td>شماره صفحه</td><td><code>page=2</code></td></tr>
                    <tr><td><code>per_page</code></td><td>تعداد در هر صفحه، حداکثر 100</td><td><code>per_page=100</code></td></tr>
                    <tr><td><code>type</code></td><td>simple / variable / variation</td><td><code>type=variation</code></td></tr>
                    <tr><td><code>sku</code></td><td>SKU دقیق</td><td><code>sku=ABC-123</code></td></tr>
                    <tr><td><code>source_product_id</code></td><td>شناسه محصول در HeyMode</td><td><code>source_product_id=1234</code></td></tr>
                    <tr><td><code>parent_product_id</code></td><td>شناسه والد Variation</td><td><code>parent_product_id=1000</code></td></tr>
                    <tr><td><code>stock_status</code></td><td>instock / outofstock / onbackorder</td><td><code>stock_status=instock</code></td></tr>
                    <tr><td><code>updated_after</code></td><td>فقط رکوردهایی که در DB محلی بعد از این زمان تغییر کرده‌اند</td><td><code>updated_after=2026-09-24T01:00:00Z</code></td></tr>
                    <tr><td><code>modified_after</code></td><td>فقط رکوردهایی که زمان تغییر منبعشان بعد از این زمان است</td><td><code>modified_after=2026-09-24T01:00:00Z</code></td></tr>
                    <tr><td><code>include_inactive</code></td><td>نمایش رکوردهای غیرفعال</td><td><code>include_inactive=true</code></td></tr>
                </tbody>
            </table>

            <h2>ساختار هر رکورد</h2>
            <pre style="background:#f6f7f7;padding:16px;overflow:auto;max-width:1100px">{
  "source_product_id": 1234,
  "parent_product_id": null,
  "product_type": "variable",
  "source_status": "publish",
  "sku": "ABC-123",
  "name": "نام محصول",
  "price": "350000",
  "stock_quantity": 12,
  "stock_status": "instock",
  "manage_stock": true,
  "image_url": "https://...",
  "product_url": "https://...",
  "category_path": [
    "دسته اصلی > دسته فرعی",
    "دسته اصلی"
  ],
  "source_modified_gmt": "2026-09-24T11:30:00+00:00",
  "is_active": true,
  "last_synced_at_utc": "2026-09-24 11:31:02",
  "updated_at_utc": "2026-09-24 11:31:02"
}</pre>
        </div>
        <?php
    }

    public static function handle_generate_api_key(): void {
        self::guard('hmw_generate_api_key');
        $key = HMW_REST_API::generate_key();
        set_transient('hmw_api_plaintext_key_' . get_current_user_id(), $key, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale-api', 'hmw_api_notice' => 'generated'), admin_url('admin.php')));
        exit;
    }

    public static function handle_revoke_api_key(): void {
        self::guard('hmw_revoke_api_key');
        HMW_REST_API::revoke_key();
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale-api', 'hmw_api_notice' => 'revoked'), admin_url('admin.php')));
        exit;
    }

    public static function handle_save_api_ip_allowlist(): void {
        self::guard('hmw_save_api_ip_allowlist');
        $text = isset($_POST['ip_allowlist']) ? (string) wp_unslash($_POST['ip_allowlist']) : '';
        HMW_REST_API::set_ip_allowlist($text);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale-api', 'hmw_api_notice' => 'saved'), admin_url('admin.php')));
        exit;
    }

    public static function handle_test_connection(): void {
        self::guard('hmw_test_connection');
        $result = HMW_Source_API::test_connection();
        $url = add_query_arg(array('page' => 'heymode-wholesale'), admin_url('admin.php'));
        if (!$result['success']) {
            $url = add_query_arg('hmw_notice', 'error', $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_self_test_rest(): void {
        self::guard('hmw_self_test_rest');
        $result = HMW_REST_API::self_test();
        set_transient('hmw_self_test_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale-api'), admin_url('admin.php')));
        exit;
    }

    public static function handle_start_full_sync(): void {
        self::guard('hmw_start_full_sync');
        $result = HMW_Sync::start_full_sync('manual');
        $url = add_query_arg(array('page' => 'heymode-wholesale', 'hmw_notice' => $result['success'] ? 'full_started' : 'error'), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_cancel_full_sync(): void {
        self::guard('hmw_cancel_full_sync');
        HMW_Sync::cancel_full_sync();
        wp_safe_redirect(add_query_arg(array('page' => 'heymode-wholesale', 'hmw_notice' => 'full_cancelled'), admin_url('admin.php')));
        exit;
    }

    public static function ajax_full_tick(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
        check_ajax_referer('hmw_full_tick');
        $result = HMW_Sync::tick_full();
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result, 500);
    }

    private static function guard(string $nonce_action): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'heymode-wholesale'));
        }
        check_admin_referer($nonce_action);
    }
}
