<?php
class OjiseMysqlHelper {
	public function OjiseMysqlHelper() {
		//dbConn = DAO.getLiveConnection();
	}
	
	public function findUploadItems($batchId) {
		$res = mysql_query("SELECT id, size, priority, local_id, completed, merged from upload_items WHERE batch_id = '{$batchId}' ORDER BY id");
		return $this->ResultSetToArray($res);
	}
	
	public function findThread($ojiseKey, $batchId, $threadId) {		
		$res = mysql_query("SELECT item_threads.*, upload_items.completed AS item_completed, " .
			"upload_items.merged AS item_merged, upload_batches.completed AS batch_completed, " .
			"upload_batches.merged AS batch_merged " .
			"FROM item_threads " .
			"INNER JOIN upload_items ON (item_threads.item_id = upload_items.id) " .
			"INNER JOIN upload_batches ON (upload_items.batch_id = upload_batches.id) " .
			"INNER JOIN device_register ON (upload_batches.register_id = device_register.id) " .
			"WHERE (device_register.ojise_key = '{$ojiseKey}' AND upload_batches.id = '{$batchId}' AND item_threads.id = '{$threadId}') ");
		return mysql_fetch_assoc($res);
	}
	
	public function updateThreadStatus($threadId, $chunkSize) {		
		return mysql_query("UPDATE item_threads SET " .
				"date_spawn = IF(current_size = 0, NOW(), date_spawn),  " .
				"current_size = current_size + '{$chunkSize}', " .
				"chunk_serial = chunk_serial + 1,  " .
				"completed = IF(current_size = size, 1, 0),  " .
				"date_completed = IF(current_size = size, NOW(), date_completed) " .
				"WHERE id = '{$threadId}' ");
	}
	
	public function updateUploadItemStatus($itemId) {
		return mysql_query("UPDATE upload_items, (SELECT item_id, MIN(completed) AS completed1, MIN(date_spawn) AS date_spawn1, " .
				"MAX(date_spawn) AS date_spawn2 FROM item_threads WHERE item_id = '{$itemId}' GROUP BY item_id) AS TH " .
				"SET completed = TH.completed1,  " .
				"date_started = TH.date_spawn1,  " .
				"date_completed = IF(completed = 1, TH.date_spawn2, NULL)	 " .
				"WHERE id = TH.item_id ");
	}
	
	public function updateUploadBatchStatus($batchId) {		
		return mysql_query("UPDATE upload_batches, (SELECT batch_id, MIN(completed) AS completed1, MIN(merged) AS merged1, MAX(date_completed) AS date_completed2  " .
				"FROM upload_items WHERE batch_id = '{$batchId}' GROUP BY batch_id) AS TH " .
				"SET completed = TH.completed1, merged = merged1, date_completed = IF(completed = 1, TH.date_completed2, NULL)	 " .
				"WHERE id = TH.batch_id ");
	}
	
	public function getThreadItemsForMerge($itemId) {
		$res = mysql_query("SELECT id, completed, start_pos, size, chunk_serial FROM item_threads WHERE item_id = '{$itemId}' ORDER BY id;");
		return $this->ResultSetToArray($res);
	}
	
	public function findUploadItemByLocalId($batchId, $localId) {
		$res = mysql_query("SELECT * FROM upload_items WHERE batch_id = '{$batchId}' AND local_id = '{$localId}'");
		return mysql_fetch_assoc($res);
	}
	
	public function updateUploadItemMerged($itemId) {
		return mysql_query("UPDATE upload_items SET merged = 1 WHERE id = '{$itemId}' ");
	}
	
	public function updateUploadItemSavedResult($itemId, $result) {
		if (!is_array($result)) $result = array();
		$result = addslashes(json_encode($result, JSON_FORCE_OBJECT));
		
		return mysql_query("UPDATE upload_items SET save_result = '{$result}' WHERE id = '{$itemId}' ");
	}
	
	public function updateBatchMerged($batchId) {
		return mysql_query("UPDATE upload_batches SET merged = 1 WHERE id = '{$batchId}' ");
	}
	
	public function updateBatchSavedResult($batchId, $result) {
		if (!is_array($result)) $result = array();
		$result = addslashes(json_encode($result, JSON_FORCE_OBJECT));
		
		return mysql_query("UPDATE upload_batches SET save_result = '{$result}' WHERE id = '{$batchId}' ");
	}	
	
	private function ResultSetToArray($res) {
		$ret = array();
		while ($row = mysql_fetch_assoc($res)) $ret[] = $row;
		return $ret;
	}
}
?>