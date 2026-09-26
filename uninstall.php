<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

$hooks = array(
    'hmw_daily_full_sync',
    'hmw_full_continue',
    'hmw_daily_incremental_sync',
    'hmw_incremental_continue',
);
foreach ($hooks as $hook) {
    while ($timestamp = wp_next_scheduled($hook)) {
        wp_unschedule_event($timestamp, $hook);
    }
}

delete_option('hmw_full_sync_state');
delete_option('hmw_incremental_sync_state');
delete_option('hmw_sync_cursor_gmt');
delete_option('hmw_last_incremental_completed_at');
delete_option('hmw_source_category_map');
delete_option('hmw_api_key_hash');
delete_option('hmw_api_key_prefix');
delete_option('hmw_api_key_created_at');
delete_option('hmw_api_ip_allowlist');
delete_option('hmw_api_revoked_at');
