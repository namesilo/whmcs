<?php

/*****************************************/
/* Grab Required Includes				 */
/*****************************************/

$module_dir = dirname(__FILE__);
chdir($module_dir);

require '../../../init.php';
require '../../../includes/functions.php';
require '../../../includes/registrarfunctions.php';

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
$ns_test_api_server = 'http://sandbox.namesilo.com';

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
$queryresult = select_query ( "tbldomains", "domain", "registrar='namesilo' AND (status='Pending Transfer' OR status='Active')" );

# Loop through domain useing NameSilo
while ($data = mysql_fetch_array($queryresult)) {

	$domainname = trim(strtolower($data['domain']));

	# Build API call 
	$result = transactionProcessor($apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$domainname");

	# Process results
	if (!empty($result["expiry"])) {
		$expirydate = $result ["expiry"];
		$status = $result ["status"];
		if ($status == 'Active') {
			update_query ( "tbldomains", array ("status" => "Active" ), array ("domain" => $domainname ) );
		}
		if ($expirydate) {
			update_query ( "tbldomains", array ("expirydate" => $expirydate ), array ("domain" => $domainname ) );
			if (@$ns_sync_next_due_date == 'on') {
				update_query ( "tbldomains", array ("nextduedate" => $expirydate ), array ("domain" => $domainname ) );
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
