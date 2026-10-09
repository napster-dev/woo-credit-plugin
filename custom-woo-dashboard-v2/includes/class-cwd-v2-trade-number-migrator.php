<?php

/**
 * Migration & Conflict Check utility for EWS Trade Account Numbers.
 * Ensures all account numbers adhere to EWS-###### (6-digit zero-padded, no 'T').
 */

if (! defined('ABSPATH')) {
	exit;
}

class CWD_V2_Trade_Number_Migrator
{

	/**
	 * Analyze all trade account numbers currently in user meta.
	 *
	 * Detects:
	 * 1. Numbers containing 'T' (e.g. EWS-T0042, EWS-T-0042, etc.).
	 * 2. Already clean numbers (e.g. EWS-000042, EWS-123456).
	 * 3. Collisions if 'T' is removed and formatted to 6-digit EWS-######:
	 *    - Collision with another user already holding that clean EWS-###### number.
	 *    - Collision where two different T-accounts map to the same 6-digit number.
	 *
	 * @return array
	 */
	public static function analyze()
	{
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value 
			 FROM {$wpdb->usermeta} 
			 WHERE meta_key = 'ews_account_number' 
			 ORDER BY user_id ASC"
		);

		$total_accounts = is_array($rows) ? count($rows) : 0;
		$t_accounts     = array();
		$clean_accounts = array(); // number => account info
		$max_num        = (int) get_option('cwd_v2_last_trade_number', 0);

		if (! empty($rows)) {
			foreach ($rows as $row) {
				$user_id = (int) $row->user_id;
				$raw_val = trim((string) $row->meta_value);
				if ($raw_val === '') {
					continue;
				}

				$user  = get_userdata($user_id);
				$email = $user ? $user->user_email : ('user_' . $user_id . '@unknown');

				// Check if number has 'T': EWS-T####, EWS-T-####, T####, etc.
				if (preg_match('/^EWS-?T-?(\d+)$/i', $raw_val, $matches)) {
					$numeric_val = (int) $matches[1];
					if ($numeric_val > $max_num) {
						$max_num = $numeric_val;
					}
					$proposed = 'EWS-' . str_pad($numeric_val, 6, '0', STR_PAD_LEFT);
					$t_accounts[] = array(
						'user_id'  => $user_id,
						'email'    => $email,
						'current'  => $raw_val,
						'numeric'  => $numeric_val,
						'proposed' => $proposed,
					);
				} elseif (preg_match('/^EWS-(\d+)$/i', $raw_val, $matches)) {
					$numeric_val = (int) $matches[1];
					if ($numeric_val > $max_num) {
						$max_num = $numeric_val;
					}
					$normalized = 'EWS-' . str_pad($numeric_val, 6, '0', STR_PAD_LEFT);
					$clean_accounts[$normalized] = array(
						'user_id'  => $user_id,
						'email'    => $email,
						'current'  => $raw_val,
						'numeric'  => $numeric_val,
					);
				} else {
					// Fallback for non-standard formats containing T
					if (stripos($raw_val, 'T') !== false) {
						// Extract any numeric sequence
						preg_match('/(\d+)/', $raw_val, $fallback_m);
						$numeric_val = ! empty($fallback_m[1]) ? (int) $fallback_m[1] : $user_id;
						if ($numeric_val > $max_num) {
							$max_num = $numeric_val;
						}
						$proposed = 'EWS-' . str_pad($numeric_val, 6, '0', STR_PAD_LEFT);
						$t_accounts[] = array(
							'user_id'  => $user_id,
							'email'    => $email,
							'current'  => $raw_val,
							'numeric'  => $numeric_val,
							'proposed' => $proposed,
						);
					}
				}
			}
		}

		// Conflict Detection
		$conflicts    = array();
		$proposed_map = array(); // proposed_number => acc_info

		foreach ($t_accounts as $t_acc) {
			$proposed = $t_acc['proposed'];
			$uid      = $t_acc['user_id'];

			// 1. Conflict with already clean accounts
			if (isset($clean_accounts[$proposed])) {
				$clean_acc = $clean_accounts[$proposed];
				if ($clean_acc['user_id'] !== $uid) {
					$conflicts[] = array(
						'type'                   => 'collision_with_existing_clean',
						'user_id'                => $uid,
						'email'                  => $t_acc['email'],
						'current'                => $t_acc['current'],
						'proposed'               => $proposed,
						'conflicts_with_user_id' => $clean_acc['user_id'],
						'conflicts_with_email'   => $clean_acc['email'],
						'conflicts_with_current' => $clean_acc['current'],
						'message'                => sprintf(
							__('User #%1$d (%2$s) currently has "%3$s". Proposed "%4$s" collides with existing User #%5$d (%6$s) who already has "%7$s".', 'custom-woo-dashboard'),
							$uid,
							$t_acc['email'],
							$t_acc['current'],
							$proposed,
							$clean_acc['user_id'],
							$clean_acc['email'],
							$clean_acc['current']
						),
					);
				}
			}

			// 2. Conflict between multiple T-accounts that map to the same proposed number
			if (isset($proposed_map[$proposed])) {
				$prev_acc = $proposed_map[$proposed];
				$conflicts[] = array(
					'type'                   => 'collision_between_t_accounts',
					'user_id'                => $uid,
					'email'                  => $t_acc['email'],
					'current'                => $t_acc['current'],
					'proposed'               => $proposed,
					'conflicts_with_user_id' => $prev_acc['user_id'],
					'conflicts_with_email'   => $prev_acc['email'],
					'conflicts_with_current' => $prev_acc['current'],
					'message'                => sprintf(
						__('Both User #%1$d (%2$s, current: "%3$s") and User #%4$d (%5$s, current: "%6$s") resolve to the exact same proposed number "%7$s".', 'custom-woo-dashboard'),
						$uid,
						$t_acc['email'],
						$t_acc['current'],
						$prev_acc['user_id'],
						$prev_acc['email'],
						$prev_acc['current'],
						$proposed
					),
				);
			} else {
				$proposed_map[$proposed] = $t_acc;
			}
		}

		return array(
			'total_accounts' => $total_accounts,
			't_accounts'     => $t_accounts,
			'clean_accounts' => $clean_accounts,
			'conflicts'      => $conflicts,
			'has_conflicts'  => ! empty($conflicts),
			'max_num'        => $max_num,
		);
	}

	/**
	 * Perform the migration.
	 *
	 * - If no conflicts: strips 'T' and updates to 6-digit EWS-######.
	 * - If conflicts exist and $regenerate_conflicts is true:
	 *   Keeps non-conflicting numbers as their proposed 6-digit value, and assigns fresh
	 *   sequential numbers (starting after the global maximum) to conflicting accounts,
	 *   guaranteeing 0 collisions and no lost references.
	 *
	 * @param bool $regenerate_conflicts Automatically assign new numbers to conflicting accounts.
	 * @return array
	 */
	public static function migrate($regenerate_conflicts = true)
	{
		$analysis = self::analyze();
		$updated  = array();
		$next_num = $analysis['max_num'] + 1;

		$conflicting_uids = array();
		foreach ($analysis['conflicts'] as $c) {
			$conflicting_uids[$c['user_id']] = true;
		}

		foreach ($analysis['t_accounts'] as $acc) {
			$uid = $acc['user_id'];

			if (isset($conflicting_uids[$uid])) {
				if ($regenerate_conflicts) {
					$new_val = 'EWS-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
					$next_num++;
					update_user_meta($uid, 'ews_account_number', $new_val);
					$updated[] = array(
						'user_id' => $uid,
						'email'   => $acc['email'],
						'old'     => $acc['current'],
						'new'     => $new_val,
						'status'  => 'regenerated_due_to_conflict',
					);
				} else {
					$updated[] = array(
						'user_id' => $uid,
						'email'   => $acc['email'],
						'old'     => $acc['current'],
						'new'     => $acc['proposed'],
						'status'  => 'skipped_due_to_unresolved_conflict',
					);
				}
			} else {
				$new_val = $acc['proposed'];
				update_user_meta($uid, 'ews_account_number', $new_val);
				$updated[] = array(
					'user_id' => $uid,
					'email'   => $acc['email'],
					'old'     => $acc['current'],
					'new'     => $new_val,
					'status'  => 'migrated_successfully',
				);
			}
		}

		// Update global sequence counter so future accounts never collide
		$new_max = max((int) get_option('cwd_v2_last_trade_number', 0), $next_num - 1);
		update_option('cwd_v2_last_trade_number', $new_max);

		return array(
			'analysis' => $analysis,
			'updated'  => $updated,
			'new_max'  => $new_max,
		);
	}
}
