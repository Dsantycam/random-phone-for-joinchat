<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package JoinChatRandomPhone
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'joinchat_random_phone_lists' );
delete_option( 'joinchat_random_phone_bags' );
