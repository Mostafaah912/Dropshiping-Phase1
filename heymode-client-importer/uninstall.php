<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('hci_api_url');
delete_option('hci_api_key');
delete_option('hci_price_fixed_amount');
delete_option('hci_price_percent');
delete_option('hci_default_post_status');
delete_option('hci_db_version');
