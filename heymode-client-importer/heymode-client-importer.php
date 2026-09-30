<?php
/**
 * Plugin Name: HeyMode Client Importer
 * Description: اسکلت اولیه پلاگین سایت مشتری برای اتصال به API هی‌مد Wholesale، تنظیم فرمول قیمت‌گذاری، و ردیابی Import محصولات.
 * Version: 0.5.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: HeyMode
 * Text Domain: heymode-client-importer
 */

defined('ABSPATH') || exit;

if (!defined('HCI_VERSION')) define('HCI_VERSION', '0.5.0');
if (!defined('HCI_FILE')) define('HCI_FILE', __FILE__);
if (!defined('HCI_DIR')) define('HCI_DIR', plugin_dir_path(__FILE__));
if (!defined('HCI_URL')) define('HCI_URL', plugin_dir_url(__FILE__));

require_once HCI_DIR . 'includes/class-hci-db.php';
require_once HCI_DIR . 'includes/class-hci-source-client.php';
require_once HCI_DIR . 'includes/class-hci-pricing.php';
require_once HCI_DIR . 'includes/class-hci-admin.php';
require_once HCI_DIR . 'includes/class-hci-products.php';
require_once HCI_DIR . 'includes/class-hci-import.php';
require_once HCI_DIR . 'includes/class-hci-sync.php';
require_once HCI_DIR . 'includes/class-hci-cli.php';

register_activation_hook(HCI_FILE, array('HCI_Admin', 'activate'));
register_deactivation_hook(HCI_FILE, array('HCI_Admin', 'deactivate'));

add_action('plugins_loaded', static function (): void {
    if (!HCI_Admin::requirements_met()) {
        add_action('admin_notices', array('HCI_Admin', 'requirements_notice'));
        return;
    }
    HCI_DB::ensure_schema();
    HCI_Admin::init();
    HCI_Products::init();
    HCI_Import::init();
    HCI_Sync::init();
});
