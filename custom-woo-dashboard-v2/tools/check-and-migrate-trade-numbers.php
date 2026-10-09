<?php

/**
 * Standalone Script: Check & Migrate EWS Trade Account Numbers.
 *
 * Can be run via:
 * 1. WP-CLI:
 *    wp eval-file tools/check-and-migrate-trade-numbers.php check
 *    wp eval-file tools/check-and-migrate-trade-numbers.php migrate
 *
 * 2. PHP CLI (if wp-load.php is found in root):
 *    php tools/check-and-migrate-trade-numbers.php check
 *    php tools/check-and-migrate-trade-numbers.php migrate
 */

// If WordPress is not loaded yet, attempt to find wp-load.php
if (! defined('ABSPATH')) {
	$wp_load_candidates = array(
		__DIR__ . '/../../../../wp-load.php',
		__DIR__ . '/../../../wp-load.php',
		dirname(__DIR__, 4) . '/wp-load.php',
	);

	foreach ($wp_load_candidates as $candidate) {
		if (file_exists($candidate)) {
			require_once $candidate;
			break;
		}
	}
}

if (! defined('ABSPATH')) {
	echo "Error: WordPress environment not loaded. Please run via WP-CLI or inside WordPress.\n";
	exit(1);
}

// Ensure user has admin privileges if accessed via web
if (php_sapi_name() !== 'cli' && ! current_user_can('manage_options')) {
	wp_die('Unauthorized access.');
}

require_once dirname(__DIR__) . '/includes/class-cwd-v2-trade-number-migrator.php';

$action = 'check';
if (isset($argv[1])) {
	$action = strtolower(trim($argv[1]));
} elseif (isset($_GET['action'])) {
	$action = strtolower(trim($_GET['action']));
}

$is_cli = php_sapi_name() === 'cli';
$nl     = $is_cli ? "\n" : "<br>\n";

echo "====================================================={$nl}";
echo " EWS Trade Account Number Format & Conflict Checker {$nl}";
echo " Target format: EWS-###### (6-digit zero-padded, no 'T'){$nl}";
echo "====================================================={$nl}{$nl}";

$analysis = CWD_V2_Trade_Number_Migrator::analyze();

echo sprintf("Total trade accounts analyzed: %d%s", $analysis['total_accounts'], $nl);
echo sprintf("Legacy accounts with 'T': %d%s", count($analysis['t_accounts']), $nl);
echo sprintf("Clean accounts (EWS-######): %d%s", count($analysis['clean_accounts']), $nl);
echo sprintf("Highest sequence number seen: %d%s%s", $analysis['max_num'], $nl, $nl);

if ($analysis['has_conflicts']) {
	echo "⚠️ CONFLICTS DETECTED:{$nl}";
	foreach ($analysis['conflicts'] as $c) {
		echo " - " . $c['message'] . $nl;
	}
	echo "{$nl}During migration, these conflicting accounts will automatically be allocated new unique sequential numbers above {$analysis['max_num']}.{$nl}{$nl}";
} else {
	echo "✅ ZERO CONFLICTS DETECTED. All accounts can be cleanly converted.{$nl}{$nl}";
}

if ($action === 'migrate') {
	echo "Executing migration...{$nl}";
	$result = CWD_V2_Trade_Number_Migrator::migrate(true);
	echo sprintf("Updated %d account(s). Highest sequence is now %d.%s%s", count($result['updated']), $result['new_max'], $nl, $nl);
	foreach ($result['updated'] as $u) {
		echo sprintf(" - User #%d (%s): %s -> %s [%s]%s", $u['user_id'], $u['email'], $u['old'], $u['new'], $u['status'], $nl);
	}
	echo "{$nl}Migration complete!{$nl}";
} else {
	echo "Run with 'migrate' argument to execute the updates:{$nl}";
	echo "  wp eval-file tools/check-and-migrate-trade-numbers.php migrate{$nl}";
}
