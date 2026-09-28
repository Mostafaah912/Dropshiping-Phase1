<?php
/**
 * Plugin Name: HeyMode Wholesale
 * Description: Snapshot محصولات و Variationهای هی‌مد با Full Sync و Cron روزانه ساعت ۴.
 * Version: 1.9.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: HeyMode
 * Text Domain: heymode-wholesale
 */

defined('ABSPATH') || exit;

if (!defined('HMW_VERSION')) define('HMW_VERSION', '1.9.2');
if (!defined('HMW_FILE')) define('HMW_FILE', __FILE__);
if (!defined('HMW_DIR')) define('HMW_DIR', plugin_dir_path(__FILE__));
if (!defined('HMW_URL')) define('HMW_URL', plugin_dir_url(__FILE__));
if (!defined('HMW_TIMEZONE')) define('HMW_TIMEZONE', 'Asia/Tehran');

require_once HMW_DIR . 'includes/class-hmw-db.php';
require_once HMW_DIR . 'includes/class-hmw-source-api.php';
require_once HMW_DIR . 'includes/class-hmw-sync.php';
require_once HMW_DIR . 'includes/class-hmw-admin.php';
require_once HMW_DIR . 'includes/class-hmw-rest-api.php';

register_activation_hook(HMW_FILE, array('HMW_Sync', 'activate'));
register_deactivation_hook(HMW_FILE, array('HMW_Sync', 'deactivate'));

add_action('plugins_loaded', static function (): void {
    HMW_DB::ensure_schema();
    HMW_Sync::ensure_options();
    HMW_Sync::ensure_cron_schedule();
    HMW_Admin::init();
    HMW_REST_API::init();
});
