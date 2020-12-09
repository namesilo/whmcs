<?php
if (isset($_SERVER['REMOTE_ADDR'])) {
	exit ('ERROR: Script called from web environment');
}

/*****************************************/
/* Grab Required Includes				 */
/*****************************************/

$module_dir = dirname(__FILE__);
chdir($module_dir);

require '../../../init.php';
require '../../../includes/functions.php';
require '../../../includes/registrarfunctions.php';

use WHMCS\Database\Capsule;

/*****************************************/
/* Transaction Processor Function		 */
/*****************************************/
function transactionProcessor($call)
{
	# Set CURL Options
    $options = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => false,    // don't return headers
        CURLOPT_USERAGENT      => "NameSilo Domain Sync 1.3", // For use with WHMCS 6x
    );
	
	# Initialize CURL
    $ch      = curl_init($call);
	# Import CURL options array
    curl_setopt_array($ch, $options);
	# Execute and store response
    $content = curl_exec( $ch );
	# Process any CURL errors
	$curl_err = false;
	if (curl_error ( $ch )) {
		$curl_err = 'CURL Error: ' . curl_errno ( $ch ) . ' - ' . curl_error ( $ch );
		exit ( 'CURL Error: ' . curl_errno ( $ch ) . ' - ' . curl_error ( $ch ) );
	}
	# Log error(s)
	if ($curl_err) { $cronreport .= "Error connecting to API: $curl_err"; }	
    curl_close( $ch );
	# Process XML result
	$xml = new SimpleXMLElement($content);
	$result ["expiry"] = (string) $xml->reply->expires;
	$result ["status"] = (string) $xml->reply->status;
	# Return result
	return $result;
}

/*****************************************/
/* Get Registrar Config Options			 */
/*****************************************/

$params = getregistrarconfigoptions('namesilo');

/*****************************************/
/* Define Test Mode						 */
/*****************************************/

$ns_test_mode = @$params['Test_Mode'];

/*****************************************/
/* Define Sync Due Date					 */
/*****************************************/

$ns_sync_next_due_date = @$params ['Sync_Next_Due_Date'];

/*****************************************/
/* Define API Servers					 */
/*****************************************/

$ns_live_api_server = 'https://www.namesilo.com';
$ns_test_api_server = 'https://sandbox.namesilo.com';

/*****************************************/
/* Define API Keys						 */
/*****************************************/

$ns_test_api_key = @$params['Sandbox_API_Key'];
$ns_live_api_key = @$params['Live_API_Key'];

/*****************************************/
/* Check Test Mode and Assign Variables	 */
/*****************************************/

# Set appropriate API server
$apiServerUrl = (@$ns_test_mode == 'on') ? $ns_test_api_server : $ns_live_api_server;

# Set appropriate API key
$apiKey = (@$ns_test_mode == 'on') ? $ns_test_api_key : $ns_live_api_key;

/*****************************************/
/* Implement Updates					 */
/*****************************************/

# Start cron report
$cronreport = 'NameSilo Domain Sync Report<br>
---------------------------------------------------<br>
';

# Query WHMCS for a list of domains using NameSilo
$queryresult = Capsule::table("tbldomains")->select("domain")->where("registrar", '=', "namesilo")->where(function ($query) {$query->where("status", '=', "Pending")->orWhere("status", '=', "Active");})->get()->toArray();

# Loop through domain useing NameSilo
foreach ($queryresult as $data) {
	$data = (array) $data;
	
	$domainname = trim(strtolower($data['domain']));
	
	# Build API call 
	$result = transactionProcessor($apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$domainname");

	# Process results
	if (!empty($result["expiry"])) {
		$expirydate = $result ["expiry"];
		$status = $result ["status"];
		if ($status == 'Active') {
			Capsule::table("tbldomains")->where("domain", '=', $domainname)->update(["status" => "Active"]);
		}
		if ($expirydate) {
			Capsule::table("tbldomains")->where("domain", '=', $domainname)->update(["expirydate" => $expirydate]);
			if (@$ns_sync_next_due_date == 'on') {
				Capsule::table("tbldomains")->where("domain", '=', $domainname)->update(["nextduedate" => $expirydate]);
			}
			$cronreport .= '' . 'Updated ' . $domainname . ' expiry to ' . frommysqldate ( $expirydate ) . '<br>';
		}
	} else {
		$cronreport .= '' . 'ERROR: ' . $domainname . ' -  Domain does not appear in the account at NameSilo<br>';
	}
}

/*****************************************/
/* Echo to the screen 					 */
/*****************************************/
echo $cronreport;

/*****************************************/
/* Log System Activity					 */
/*****************************************/
logactivity ( 'NameSilo Domain Sync Run' );

/*****************************************/
/* Send Cron Report						 */
/*****************************************/
sendadminnotification ( 'system', 'NameSilo Domain Syncronization Report', $cronreport );

?>
