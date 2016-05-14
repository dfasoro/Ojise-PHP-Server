<?php
abstract class OjiseServer {
	private $db = null;
	
	final public function execute($method, $ojiseData) {
		//check methodname among some names array
		//TODO
		
		$data = $this->$method($ojiseData);
		if ($data) return json_encode($data);
		else return null; //return error actually. TODO
	}
	
	final public function _execute() {
		$ojise_key = $this->getOjiseKey(null);
		echo $ojise_key, "\r\n<br />";
		
		//mysql_query("TRUNCATE TABLE upload_batches");
		//mysql_query("TRUNCATE TABLE upload_items");
		//mysql_query("TRUNCATE TABLE item_threads");
		
		$params = array("age" >= 76, "gender" => "Male");
		$file_data = array();
		
		for ($i = 1; $i <= 10; $i++) {
			$file_data[] = array('local_id' => $i, 'type' => 'file', 'params' => array("i" => $i), 'priority' => rand(1, 3), 
				'filename' => "file{$i}.zip", 'size' => rand(100 * 1024, 5 * 1024 * 1024));
		}
		
		//echo "<pre>", print_r($file_data), "</pre>";
		
		$batch_id = $this->initializeBatch($ojise_key, 24 * 60 * 60, $params, $file_data);
		
		//echo "<pre>\r\n\r\n\r\n", print_r($batch_info, true), "</pre>";
		
		if($this->createUploadPlan($ojise_key, $batch_id, $this->getDefaultChunkCount(), $this->getDefaultChunkSize())) {
			echo "<pre>\r\n\r\n\r\n", print_r($this->getUploadPlan($ojise_key, $batch_id), true), "</pre>";
		}		
	}
	
	public function OjiseServer($db) {		
		$this->db = $db;
	}
	
	final private function getOjiseKey($accessDetails) {
		if (!($access_code = $this->checkAccess($accessDetails))) {
			return null;
		}
		
		$ojise_key = sha1(join($this->getSecret(), array($access_code, "|", time())));
		
		mysql_query("INSERT IGNORE INTO device_register (access_code, ojise_key, date_added)
			VALUES ('{$access_code}', '{$ojise_key}', NOW())");
			
		$res = mysql_query("select * from device_register where access_code = '{$access_code}'");
		
		if ($row = mysql_fetch_assoc($res)) {
			mysql_free_result($res);
			return $row['ojise_key'];
		}
		else return null;
	}
	
	final private function getAccess($ojise_key) {
		$res = mysql_query("select id, access_code from device_register where ojise_key = '{$ojise_key}'");
		if ($row = mysql_fetch_assoc($res)) {
			mysql_free_result($res);
			return $row;
		}
		else return null;
	}
	
	final private function loadBatch($register_id, $batch_id) {
		$res = mysql_query("select * from upload_batches where register_id = '{$register_id}' AND id = '{$batch_id}'");
		if ($row = mysql_fetch_assoc($res)) {
			mysql_free_result($res);
			return $row;
		}
		else return null;
	}
	
	final private function initializeBatch($ojise_key, $expiry, $params, $file_data) {
		if ($access = $this->getAccess($ojise_key)) {
			$register_id = $access['id'];			
		}
		else return 0; //Could not get Access
		
		if (empty($params)) $params = array();		
		$params = addslashes(json_encode($params, JSON_FORCE_OBJECT));
		
		
		mysql_query("INSERT INTO upload_batches (register_id, params, completed, merged, save_result, date_added, date_completed, expiry)
			VALUES ('{$register_id}', '{$params}', 0, 0, NULL, NOW(), NULL, '{$expiry}')");
	
		$batch_id = mysql_insert_id();
		
		if (!$batch_id) {
			return 0; //Could not get register batch id
		}
		
		/* The following block of code will correct any wrong ordering of priority and upload items that might introduce bugs later */
		usort($file_data, function ($a, $b) {
			if ($a['priority'] == $b['priority']) {
				return ($a['local_id'] < $b['local_id']) ? -1 : 1;
			}
			return ($a['priority'] < $b['priority']) ? -1 : 1;
		});

		
		$priority = NULL;
		foreach ($file_data as $file) {
			if ($priority !== $file['priority']) {
				$priority = $file['priority'];
			}
			
			$params = addslashes(json_encode($file['params'], JSON_FORCE_OBJECT));
			
			if (!mysql_query("INSERT INTO upload_items (batch_id, local_id, `type`, params, priority, filename, size, completed, merged, save_result)
				VALUES ('{$batch_id}', '{$file['local_id']}', '{$file['type']}', '{$params}', '{$file['priority']}', '{$file['filename']}', 
				'{$file['size']}', 0, 0, NULL)")) {
				return 0; //Could not get register file for batch id
			}
		}
		
		if ($this->onBatchInitialize($ojise_key, $params, $file_data)) {
			return $batch_id;
		}
		else return 0; //Not Approved by Logic Code.
	}
	
	final private function createUploadPlan($ojise_key, $batch_id, $threads, $size) {
		if ($access = $this->getAccess($ojise_key)) {
			$register_id = $access['id'];			
		}
		else return false; //Could not get Access

		if (!($batch_info = $this->loadBatch($register_id, $batch_id))) {
			return false; //Either not owned or something.
		}
		
		if ($batch_info["batch_threads"]) {
			return true; //Batch Threads has already been created.
		}
		
		$items = array();
		$items_detail = mysql_query("SELECT id, size, priority from upload_items WHERE batch_id = {$batch_id} ORDER BY id");
		while ($item = mysql_fetch_assoc($items_detail)) {
			$items[$item['priority']][] = $item;
		}
		mysql_free_result($items_detail);
		
		$item_sum = mysql_query("SELECT priority, CEILING(SUM(size) / {$threads}) AS chunk_size from upload_items WHERE batch_id = {$batch_id} GROUP BY priority ORDER BY priority");
				
		$generated = array();
		while ($item = mysql_fetch_assoc($item_sum)) {
			$chunk_size = $item['chunk_size'];
			if ($chunk_size < $size) $chunk_size = $size;
			
			$thread = 1;
			$present_size = 0;
			$chunk_position = 0;
			
			for ($i = 0; $i < sizeof($items[$item['priority']]);) {
				$_item = &$items[$item['priority']][$i];
				
				$remaining_size = $chunk_size - $present_size;
				$alloc_size = null;
				
				if ($_item['size'] <= $remaining_size) $alloc_size = $_item['size'];
				else $alloc_size = $remaining_size;
				
				$generated[] = array('item_id' => $_item['id'], 'thread_number' => $thread, 'start_pos' => $chunk_position, 
						'current_size' => 0, 'size' => $alloc_size);
			
				$present_size += $alloc_size;
				$chunk_position += $alloc_size;
				$_item['size'] -= $alloc_size;
								
				if ($_item['size'] == 0) {
					$i++;
					$chunk_position = 0;
				}
				if ($chunk_size == $present_size) {
					$thread++;
					if ($thread > $threads) $thread = 1;					
					$present_size = 0;
				}				
			}
		}
		
		$sql_gen = array();
		foreach ($generated as $_thread) $sql_gen[] = "(" . join(', ', $_thread) . ")";
		
		mysql_query("INSERT INTO item_threads (" . join(", ", array_keys($generated[0])) . ") VALUES " . join(",\r\n", $sql_gen));
		
		mysql_query("UPDATE upload_batches SET batch_threads = " . sizeof($generated) . " WHERE id = '{$batch_id}'");
		
		mysql_free_result($item_sum);
		
		return true;
	}
	
	final private function getUploadPlan($ojise_key, $batch_id) {
		$plan_res = mysql_query("SELECT item_threads.id, item_threads.thread_number, item_threads.start_pos, 
			item_threads.current_size, item_threads.size, upload_items.local_id, upload_items.priority
			FROM upload_items
				INNER JOIN item_threads ON (upload_items.id = item_threads.item_id)
				INNER JOIN upload_batches ON (upload_batches.id = upload_items.batch_id)
				INNER JOIN device_register ON (device_register.id = upload_batches.register_id)
			WHERE device_register.ojise_key = '{$ojise_key}' AND upload_batches.id = {$batch_id}
			ORDER BY item_threads.id ASC");
		
		$plans = array();
		while ($plan = mysql_fetch_assoc($plan_res)) $plans[] = $plan;
		
		return $plans;
	}
	
	abstract protected function getSecret();
	abstract protected function getStoragePath();
	abstract protected function checkAccess($accessDetails); //accessDetails should be a JSON object/hashmap
	protected function getDefaultChunkSize() { return 1 * 1024 * 1024; }
	protected function getDefaultChunkCount() { return 6; }
	protected function getDefaultTimeOut() { return 60; }	
	protected function getDefaultUploadBitsSize() { return 1 * 1024 * 1024; }	
	protected function getDefaultUploadBitsMaxSize() { return $this->getDefaultUploadBitsSize() * 3; }
	protected function getDefaultUploadExpiry() { return 24 * 60 * 60; }

	protected function onBatchInitialize($ojise_key, $params, $file_data) { return true; }
	protected function onItemComplete($item) { return null; } //$item is an array/hashmap
	protected function onBatchComplete($batch) { return null; }
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	
	final private function uploadPart($ojise_key, $batch_id, $item_meta, $file_name) {
		$thread_id = $item_meta["thread_id"];
		$chunk_position = $item_meta["chunk_position"];
		$chunk_size = $item_meta["chunk_size"];
		
		if ($chunk_size != filesize($file_name)) return null;
		
		$thread_row = $this->db->findThread($ojise_key, $batch_id, $thread_id);
		
		if ($thread_row["current_size"] == $chunk_position && 
				($chunk_position + $chunk_size) <= $thread_row["size"]) {
			$chunk_serial = $thread_row["chunk_serial"] + 1;
			$item_id = $thread_row["item_id"];
			
			$directory = $this->getStoragePath() . "/" . $batch_id . "/" . $item_id . "/" . $thread_id . "/";
			@mkdir($directory, 0777, true);
			if (!file_exists($directory)) return null;
			
			$chunk = $directory . $chunk_serial . ".ise";
			@unlink($chunk);

			if (!move_uploaded_file($file_name, $chunk)) return null;
			
			if (!$this->db->updateThreadStatus($thread_id, $chunk_size)) return null;
			
			if (!$this->db->updateUploadItemStatus($item_id)) return null;
		}

		if (!$this->db->updateUploadBatchStatus($batch_id)) return null;
		
		return $this->db->findThread($ojise_key, $batch_id, $thread_id);		
	}
	
	//TODO include Ojise Key
	final private function mergeUploadItem($batch_id, $local_id) {
		$item = $this->db->findUploadItemByLocalId($batch_id, $local_id);
		$item_id = $item["id"];
		
		$this->db->updateUploadItemStatus($item_id);
		
		if ($item["completed"] == 1) {
			$ext = @pathinfo($item["filename"], PATHINFO_EXTENSION);
			if (empty($ext)) $ext = "ise";
			
			$lump = $this->getStoragePath() . "/" . $item["batch_id"] . "/" . $item_id . "." . $ext;
			$item_dir = $this->getStoragePath() . "/" . $item["batch_id"] . "/" . $item_id . "/";
			
			if ($item["merged"] == 0) {				
				$size = @filesize($lump);
				$fos = fopen($lump, "ab");
				
				$threads = $this->db->getThreadItemsForMerge($item_id);
				
				clearstatcache();
				if ($size != filesize($lump)) return null; //$probably modified before time ...
				
				for ($i = 0; $i < sizeof($threads); $i++) {
					$thread = $threads[$i];
					$thread_dir = $item_dir . $thread["id"] . "/";
					
					if ($size < ($thread["start_pos"] + $thread["size"])) {
						$file_len_check = $thread["start_pos"];
						for ($chunk_serial = 1; $chunk_serial <= $thread["chunk_serial"]; $chunk_serial++) {
							$chunk = $thread_dir . $chunk_serial . ".ise";
							
							$chunk_size = filesize($chunk);
							
							if ($size < ($file_len_check + $chunk_size)) {
								$chunk_read = fopen($chunk, "rb");
								$read_offset = ($size - $file_len_check); 
								$read_length = (($file_len_check + $chunk_size) - $size);

								fseek($chunk_read, $read_offset);
								
								fwrite($fos, fread($chunk_read, $read_length));
								
								fclose($chunk_read);
								
								$size += $read_length;
								$file_len_check += $chunk_size;
							}
						}
						fflush($fos);
					}
	
					//clean out all the files for that $thread item
					for ($chunk_serial = 1; $chunk_serial <= $thread["chunk_serial"]; $chunk_serial++) {
						$chunk = $thread_dir . $chunk_serial . ".ise";
						@unlink($chunk);
					}
					rmdir($thread_dir);
				}
				
				fclose($fos);
				chmod($lump, 0777);
				
				$item["merged"] = 1;
			}
			
			if ($item["merged"] == 1) {	
				if ($this->db->updateUploadItemMerged($item_id)) {
					
					$item["savepath"] = $lump;
					
					$save_result_json = null;
					
					if (empty($item["save_result"])) { 
						$save_result = null;
						$save_result = @$this->onItemComplete($item);
						
						$save_result_json = !$save_result ? array() : $save_result;
						$this->db->updateUploadItemSavedResult($item_id, $save_result_json);
					}
					else {
						$save_result_json = json_decode($item["save_result"], true);
					}
					
					$ret = array();
					$ret["local_id"] = $local_id;
					$ret["merged"] = 1;
					$ret["completed"] = 1;
					$ret["save_result"] = $save_result_json;
					
					@rmdir($item_dir);
					
					return $ret;
				}
			}
		}
		
		return null;		
	}
	
	final private function mergeBatch($ojise_key, $batch_id) {
		$access = $batch_info = null;
		$register_id = null;
		
		if (($access = $this->getAccess($ojise_key)) != null) {
			$register_id = $access["id"];			
		}
		else return null; //Could not get Access
		
		if (!$this->db->updateUploadBatchStatus($batch_id)) return null;
		
		if (($batch_info = $this->loadBatch($register_id, $batch_id)) == null) {
			return null; //Either not owned or something.
		}
		
		if ($batch_info["merged"] == 1) {	
			if ($this->db->updateBatchMerged($batch_id)) {				
				$save_result_json = null;
				
				if (empty($batch_info["save_result"])) { 
					$save_result = null;
					$save_result = $this->onBatchComplete($batch_info);
					
					$save_result_json = !$save_result ? array() : $save_result;
					$this->db->updateBatchSavedResult($batch_id, $save_result_json);
				}
				else {
					$save_result_json = json_decode($batch_info["save_result"], true);
				}
				
				$ret = array();
				$ret["merged"] = 1;
				$ret["completed"] = 1;
				$ret["save_result"] = $save_result_json;
				
				return $ret;
			}
		}
		
		return null;		
	}
	
	
	
	
	
	
	
	
	
	final public function _registerOjiseBatch($ojiseData) {
		$ret = array();
		
		$ojise_key = $this->getOjiseKey($ojiseData["AccessDetails"]);
		if ($ojise_key == null) return null;
		
		//$this->debug( $ojise_key +  "\r\n<br />" );
		
		$params = $ojiseData["Params"];
		$file_data = $ojiseData["file_data"];
		
		$Expiry = $ojiseData["Expiry"];
		$ChunksCount = $ojiseData["ChunksCount"];
		$ChunksMinSize = $ojiseData["ChunksMinSize"];
		
		if ($Expiry <= 0) $Expiry = $this->getDefaultUploadExpiry();
		if ($ChunksCount <= 0) $ChunksCount = $this->getDefaultChunkCount();
		if ($ChunksMinSize <= 0) $ChunksMinSize = $this->getDefaultChunkSize();
		
		$batch_id = $this->initializeBatch($ojise_key, $Expiry, $params, $file_data);
		if ($batch_id == 0) return null;
		
		//$this->debug( "<pre>\r\n\r\n\r\n", print_r($batch_info, true), "</pre>";
		
		if (!$this->createUploadPlan($ojise_key, $batch_id, $ChunksCount, $ChunksMinSize)) return null;
		
		$upload_threads = $this->getUploadPlan($ojise_key, $batch_id);
		if ($upload_threads == null || !$upload_threads) return null;
		
		$ret["batch_id"] = $batch_id;
		$ret["ojise_key"] = $ojise_key;
		
		$ret["ChunkSize"] = $this->getDefaultChunkSize();
		$ret["ChunkCount"] = $this->getDefaultChunkCount();
		$ret["TimeOut"] = $this->getDefaultTimeOut();
		$ret["UploadBitsSize"] = $this->getDefaultUploadBitsSize();
		$ret["UploadBitsMaxSize"] = $this->getDefaultUploadBitsMaxSize();
		$ret["Expiry"] = $this->getDefaultUploadExpiry();

		$ret["upload_threads"] = $upload_threads;
		
		
		return $ret;
		//$this->debug( "<pre>\r\n\r\n\r\n" . $this->_toJSON(upload_threads).toString(4) . "</pre>" );
					
	}
	
	final public function _getUploadPlanAndStatus($ojiseData) {		 
		$ojise_key = $ojiseData["ojise_key"];
		$batch_id =  $ojiseData["batch_id"];
		
		$ret = array();
		$upload_threads = $this->getUploadPlan($ojise_key, $batch_id);
		//if ($upload_threads == null || sizeof($upload_threads) == 0) return null;
		
		$upload_items_status = $this->db->findUploadItems($batch_id);
		//if ($upload_items_status == null || sizeof($upload_items_status) == 0) return null;
		
		$ret["upload_threads"] = $upload_threads;
		$ret["upload_items_status"] = $upload_items_status;
		
		return $ret;
	}
	
	final public function _uploadPart($ojiseData/*, $file_name*/) {
		$ojise_key = $ojiseData["ojise_key"];
		$batch_id = $ojiseData["batch_id"];
		$item_meta = $ojiseData["item_meta"];
		
		$file_name = $_FILES["chunk"]["tmp_name"];
		
		return $this->uploadPart($ojise_key, $batch_id, $item_meta, $file_name);		
	}
	
	final public function _mergeUploadItem($ojiseData) {
		$batch_id = $ojiseData["batch_id"];
		$local_id = $ojiseData["local_id"];
		return $this->mergeUploadItem($batch_id, $local_id);
	}
	
	final public function _mergeBatch($ojiseData) {
		$ojise_key = $ojiseData["ojise_key"];
		$batch_id = $ojiseData["batch_id"];
		return $this->mergeBatch($ojise_key, $batch_id);
	}
	
}

?>