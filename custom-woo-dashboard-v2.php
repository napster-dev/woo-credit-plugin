<?php

/**
 * Plugin Name: Custom WooCommerce Dashboard
 * Description: Replaces the default WooCommerce My Account page with a custom dashboard and adds credit account features.
 * Version: 2.2.19
 * Author: Jules
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/custom-woo-dashboard-v2/custom-woo-dashboard-v2.php';

if (class_exists('CWD_V2_Activator')) {
	register_activation_hook(__FILE__, array('CWD_V2_Activator', 'activate'));
}
