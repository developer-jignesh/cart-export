<?php
namespace BWFAN\Exporter;

use BWFAN\Exporter\Base;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class Cart extends Base
{
	public $export_id = 0;

	public function __construct()
	{
		$this->type = 'cart';
	}

	/**
	 * Handle cart export
	 *
	 * @param $user_id
	 * @param $export_id
	 *
	 * @return void
	 */
	public function handle_export($user_id, $export_id = 0)
	{
		$this->export_id = $export_id;

		// Default status in case of failure
		$status_data = [
			'status' => 3,
			'msg' => [
				__('Unable to create cart export file.', 'wp-marketing-automations-pro')
			]
		];

		// Ensure necessary class and data are available
		if (!class_exists('\BWFAN_Recoverable_Carts')) {
			$status_data['msg'][] = __('Missing BWFAN_Recoverable_Carts class.', 'wp-marketing-automations-pro');
		} else {
			// Fetch abandoned carts data
			$carts = \BWFAN_Recoverable_Carts::get_abandoned_carts(
				'',
				'',
				0,
				100,
				'',
				false
			);

			// Prepare CSV filename and path
			$filename = 'cart-export-' . time() . '.csv';
			if (!file_exists(self::$export_folder)) {
				wp_mkdir_p(self::$export_folder);
			}
			$file_path = self::$export_folder . '/' . $filename;

			// Open file for writing
			$file = fopen($file_path, 'w');
			if ($file) {
				// Define headers
				$headers = ['email', 'user_id', 'created_time', 'items', 'total', 'currency', 'checkout_data', 'checkout_page_id'];
				fputcsv($file, $headers);

				// Write cart data to CSV

				foreach ($carts as $cart) {
					if (!is_object($cart)) {
						continue;
					}

					$row_data = [
						$this->sanitize_field($cart->email),
						$this->sanitize_field($cart->user_id),
						$this->sanitize_field($cart->created_time),
						$this->sanitize_field($cart->items),
						$this->sanitize_field($cart->total),
						$this->sanitize_field($cart->currency),
						$this->sanitize_field($cart->checkout_data),
						$this->sanitize_field($cart->checkout_page_id)
					];

					fputcsv($file, $row_data);
				}

				fclose($file);

				// Update status data on success
				$status_data = [
					'status' => 2,
					'url' => $file_path,
					'msg' => [
						__('Cart export file created successfully', 'wp-marketing-automations-pro')
					]
				];
			} else {
				$status_data['msg'][] = __('Failed to open export file for writing.', 'wp-marketing-automations-pro');
			}
		}

		// Update user meta with export status
		$user_data = get_user_meta($user_id, 'bwfan_single_export_status', true);
		$user_data[$this->type] = $status_data;
		update_user_meta($user_id, 'bwfan_single_export_status', $user_data);

		// Unschedule the export action
		BWFAN_Core()->exporter->unschedule_export_action([
			'type' => $this->type,
			'user_id' => $user_id,
			'export_id' => $this->export_id
		]);
	}

	/**
	 * Sanitize field for CSV
	 *
	 * @param $field
	 *
	 * @return string
	 */
	private function sanitize_field($field)
	{
		if (is_null($field))
			return 'N/A';
		if (is_array($field) || is_object($field)) {
			return wp_json_encode($field);
		}
		return sanitize_text_field($field);
	}
}

// Register the Cart exporter
BWFAN_Core()->exporter->register_exporter('cart', 'BWFAN\Exporter\Cart');