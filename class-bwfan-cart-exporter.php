<?php

namespace BWFAN\Exporter;
use BWFAN\Exporter\Base;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/***
 * Class Automation_Exporter
 *
 * @package Autonami
 */
class Cart extends Base
{
	private $db_export_row = [];
	private $export_id = 0;
	private $export_meta = [];
	private $user_id = 0;
	private $current_pos = 0;
	public $carts = [];
	private $count = 0;
	private $halt = 0;
	private $start_time = 0;

	/**
	 * Class constructor
	 */
	public function __construct()
	{
		$this->type = 'cart';
		error_log("Cart Exporter initialized.");
	}

	/**
	 * Handle cart export
	 *
	 * @param $user_id
	 *
	 * @return void
	 */
	public function handle_export($user_id, $export_id = 0)
	{
		error_log("Starting export process for user ID: $user_id");

		// Insert data and get export ID
		$export_id = $this->maybe_insert_data_in_table();
		error_log("Export ID obtained: $export_id");

		$this->export_id = $export_id;
		$this->user_id = $user_id;
		$this->maybe_get_export($this->export_id);

		$this->current_pos = absint($this->db_export_row['offset']);
		$this->populate_carts();

		$this->start_time = time();
		while (((time() - $this->start_time) < 30) && !\BWFCRM_Common::memory_exceeded() && (0 === $this->halt)) {
			if (empty($this->carts) || $this->count >= count($this->carts)) {
				$this->populate_carts(); 
				if (empty($this->carts)) {
					error_log("No more carts found. Ending export.");
					$this->halt = 1;
					break;
				}
			}

			$this->export_cart();
			$this->update_offset();

			if ($this->get_percent_completed() >= 100) {
				error_log("Export completed 100%.");
				break;
			}
		}

		if ($this->get_percent_completed() >= 100) {
			$this->end_export(3, 'Export completed successfully');
		} else {
			error_log("Export halted before completion.");
		}
	}


	public function maybe_get_export($export_id)
	{
		error_log("Fetching export data for Export ID: $export_id");
		if (is_array($this->db_export_row) && !empty($this->db_export_row) && absint($this->db_export_row['id']) === absint($export_id)) {
			error_log("Export data already loaded.");
			return;
		}
		$this->export_id = absint($export_id);
		$this->db_export_row = \BWFAN_Model_Import_Export::get($this->export_id);
		error_log("Export row data: " . json_encode($this->db_export_row));
		$this->export_meta = !empty($this->db_export_row['meta']) ? json_decode($this->db_export_row['meta'], true) : array();
		error_log("Export meta data: " . json_encode($this->export_meta));
	}


	public function maybe_insert_data_in_table()
	{
		if (!file_exists(BWFCRM_EXPORT_DIR . '/')) {
			wp_mkdir_p(BWFCRM_EXPORT_DIR);
		}
		$file_name = 'cart-export-' . time() . '.csv';
		$file = fopen(BWFCRM_EXPORT_DIR . '/' . $file_name, "wb");
		if (empty($file)) {
			error_log("Failed to create export file.");
			return 0;
		}
		$labels = ['email', 'user_id', 'created_time', 'items', 'total', 'currency', 'checkout_data', 'checkout_page_id'];
		fputcsv($file, $labels);
		fclose($file);

		$count = \BWFAN_Recoverable_Carts::get_abandoned_carts('', '', '', '', '', true);

		\BWFAN_Model_Import_Export::insert(array(
			'offset' => 0,
			'type' => 2,
			'status' => 1,
			'count' => $count['total_count'],
			'meta' => wp_json_encode(array(
				'title' => 'cart',
				'file' => $file_name
			)),
			'created_date' => current_time('mysql', 1),
			'last_modified' => current_time('mysql', 1)
		));

		$export_id = \BWFAN_Model_Import_Export::insert_id();

		if (empty($export_id)) {
			error_log("Failed to insert export record into database.");
			wp_delete_file(BWFCRM_EXPORT_DIR . '/' . $file_name);
			return 0;
		}

		error_log("Data inserted successfully with Export ID: $export_id");
		return $export_id;
	}

	public function populate_carts()
	{
		$this->carts = \BWFAN_Recoverable_Carts::get_abandoned_carts('', '', $this->current_pos, 10, '', false);
		error_log("Populated carts: " . print_r($this->carts, true));
	}

	public function export_cart()
{
    $this->count = 0;

    if (empty($this->carts) || !is_array($this->carts)) {
        error_log("No valid cart data found. Ending export.");
        $this->end_export(2, 'No valid cart data found for export');
        $this->halt = 1;
        return;
    }

    $file_path = BWFCRM_EXPORT_DIR . '/' . (is_array($this->export_meta) ? $this->export_meta['file'] : $this->export_meta->file);
    $file = fopen($file_path, "a");

    if (!$file) {
        error_log("Failed to open file for appending: $file_path");
        $this->halt = 1;
        return;
    }

    foreach ($this->carts as $cart) {
        if (!is_object($cart)) {
            error_log("Skipping invalid cart data: " . print_r($cart, true));
            continue;
        }

        $csvData = [
            $cart->email ?? 'N/A',
            $cart->user_id ?? 'N/A',
            $cart->created_time ?? 'N/A',
            $cart->items ?? 'N/A',
            $cart->total ?? 'N/A',
            $cart->currency ?? 'N/A',
            $cart->checkout_data ?? 'N/A',
            $cart->checkout_page_id ?? 'N/A',
        ];

        error_log("Exporting cart data row: " . json_encode($csvData));

        if (!fputcsv($file, $csvData)) {
            error_log("Failed to write cart data row: " . json_encode($csvData));
        } else {
            $this->count++;
        }
    }

    fclose($file);
    $this->current_pos += $this->count;
    error_log("Current export position updated to: " . $this->current_pos);
}


	/**
	 * Finish exporting to file
	 *
	 * @param int $status
	 * @param string $status_message
	 */
	public function end_export($status = 3, $status_message = '')
	{
		if (empty($this->export_id)) {
			error_log("No Export ID available to end export.");
			return;
		}

		BWFAN_Core()->exporter->unschedule_export_action([
			'type' => $this->type,
			'user_id' => $this->user_id,
			'export_id' => $this->export_id
		]);

		if (!empty($status_message)) {
			BWFAN_Core()->logger->log($status_message, 'export_contacts_crm');
		} else if (3 === $status) {
			$status_message = 'Cart exported. Export ID: ' . $this->export_id;
		}

		$this->db_export_row['status'] = $status;
		$this->export_meta['status_msg'] = $status_message;
		\BWFAN_Model_Import_Export::update(array(
			"status" => $status,
			"meta" => wp_json_encode($this->export_meta)
		), array(
			'id' => absint($this->export_id)
		));
		error_log("Export ended with status $status and message: $status_message");
	}

	/**
	 * Update DB offset
	 */
	public function update_offset()
	{
		$this->db_export_row['offset'] = $this->current_pos;
		\BWFAN_Model_Import_Export::update(array("offset" => $this->current_pos), array('id' => absint($this->export_id)));
		error_log("Database offset updated to: " . $this->current_pos);

		if ($this->get_percent_completed() >= 100) {
			$this->end_export();
		}
	}

	/**
	 * Return percent completed
	 *
	 * @return int
	 */
	public function get_percent_completed()
	{
		$start_pos = isset($this->db_export_row['offset']) && !empty(absint($this->db_export_row['offset'])) ? absint($this->db_export_row['offset']) : 1;
		$percent_completed = absint(min(round((($start_pos / $this->db_export_row['count']) * 100)), 100));
		error_log("Percent completed: $percent_completed%");
		return $percent_completed;
	}
}

/**
 * Register exporter
 */
BWFAN_Core()->exporter->register_exporter('cart', 'BWFAN\Exporter\Cart');
