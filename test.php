<?php
@set_magic_quotes_runtime(0);
error_reporting(E_ALL);
ini_set("display_errors", "On");

//execution time should be set high enough

ini_set("mysql.trace_mode", "0");

require_once "lib/OjiseServer.php";
require_once "lib/OjiseMysqlHelper.php";

class TestOjiseServer extends OjiseServer {
	public function TestOjiseServer() {
		parent::__construct(new OjiseMysqlHelper());
	}
	protected function getSecret() { return "bleh-bleh"; }
	protected function getStoragePath() { return "/tmp/ojise"; }
	protected function checkAccess($accessDetails) { return 55; /* $accessDetails["agent_id"]; */ }
	protected function onBatchComplete($batch) { 
		//@file_get_contents("http://oilspillwitness.org/mobile/publish.php?id={$batch['id']}"); 
		return null; 
	}
	
}

mysql_connect("localhost", "root", "root");
mysql_select_db("new_ojise");

$test = new TestOjiseServer();
echo $test->execute($_POST["method"], @json_decode(@$_POST["ojiseData"], true));

?>