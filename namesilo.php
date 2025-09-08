<?php

/*****************************************/
/* Set WHMCS Configuration Variables     */
/*****************************************/

//Lookup dependencies
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

#Price sync dependencies
//use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Domain\TopLevel\ImportItem;

function namesilo_getConfigArray()
{

    $configarray = array(
        "Live_API_Key" => array("Type" => "text", "Size" => "30", "Description" => "Enter your Live API Key",),
        "Sandbox_API_Key" => array("Type" => "text", "Size" => "30", "Description" => "Enter your Sandbox API Key (Optional)",),
        "Payment_ID" => array("Type" => "text", "Size" => "20", "Description" => "Enter your Payment ID (Optional)",),
        "Sandbox_Payment_ID" => array("Type" => "text", "Size" => "20", "Description" => "Enter your Sandbox Payment ID (Optional)",),
        //        "Coupon" => array("Type" => "text", "Size" => "20", "Description" => "Enter your Reseller Discount Coupon (Optional)",),
        "Test_Mode" => array("Type" => "yesno", 'Description' => "Enable this option ONLY if you have a Sandbox account (Optional)"),
        "Auto_Renew" => array("Type" => "yesno", 'Description' => "Do you want new domain registrations to automatically renew at NameSilo?"),
        "Default_Privacy" => array("Type" => "yesno", 'Description' => "Do you want to enable WHOIS privacy by default on registrations and transfers?"),
        "Delete_Default_DNS" => array("Type" => "yesno", 'Description' => "Do you want to delete the default DNS records when the default name servers are used on registrations?"),
        "Sync_Next_Due_Date" => array("Type" => "yesno", 'Description' => "Tick this box if you want the expiry date sync script to update both expiry and next due dates. If left unchecked it will only update the domain expiration date. (cron must be configured)"),
        "Debug_Recipient" => array("Type" => "text", "Size" => "30", "Description" => "Enter the email address where debug emails should be sent",),
        "Debug_ON" => array("Type" => "yesno", 'Description' => "Enable this option ONLY if you want debug emails"),
    );

    return $configarray;
}

/*****************************************/
/* Define API Servers                    */
/*****************************************/
define('LIVE_API_SERVER', 'https://www.namesilo.com');
define('TEST_API_SERVER', 'https://sandbox.namesilo.com');

/*****************************************/
/* Transaction Processor                 */
/*****************************************/
function namesilo_transactionCall($callType, $call, $params)
{
    $options = array(
        CURLOPT_RETURNTRANSFER => true,           // return body
        CURLOPT_HEADER         => false,          // no headers
        CURLOPT_USERAGENT      => "NameSilo WHMCS Module/8.x (PHP " . PHP_VERSION . ")",
        CURLOPT_SSL_VERIFYPEER => true,           // keep TLS verify on
        // --- NEW: sensible timeouts ---
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    );

    $ch = curl_init($call);
    curl_setopt_array($ch, $options);
    $content  = curl_exec($ch);
    $err      = curl_errno($ch);
    $errmsg   = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // NEW: capture HTTP status
    curl_close($ch);

    $response = [];

    // --- Parse XML only if no cURL error and we got a body ---
    if (!$err && is_string($content) && $content !== '') {
        $xmlErrorDisplay = libxml_use_internal_errors(true);
        try {
            $xml = new SimpleXMLElement($content);
        } catch (Exception $excp) {
            $err    = -1;
            $errmsg = $excp->getMessage();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($xmlErrorDisplay);
        }
    }

    // If we still have no cURL error, try to read NameSilo reply code/detail safely
    $code = null;
    $detail = null;
    if (!$err && isset($xml->reply)) {
        if (isset($xml->reply->code)) {
            $code   = (int)$xml->reply->code;
        }
        if (isset($xml->reply->detail)) {
            $detail = (string)$xml->reply->detail;
        }
    }

    if (!$err && $code !== null) {

        switch ($callType) {

            case "Standard":
                if ($code == 300) {
                    $response = [];
                    break;
                }
                $response['error'] = $detail;
                if ($code == 301 || $code == 302) {
                    $response['error'] .= ' - ' . (string)$xml->reply->message;
                }
                break;

            case "registrantVerificationStatus":
                if ($code == 300) {
                    $response['email'] = $xml->reply->email;
                    break;
                }
                $response['error'] = $detail;
                break;

            case 'domainSync':
                // CHANGED: previously returned $xml->reply unconditionally.
                // Now: only return reply on success; otherwise return a clean error structure.
                if ($code == 300) {
                    return $xml->reply;
                }
                $response['error'] = $detail ?: 'Domain sync failed';
                $response['code']  = $code;
                break;

            case "getRegistrarLock":
                $status = (string)$xml->reply->locked;
                if ($status === 'Yes') {
                    $response = 'locked';
                } elseif ($status === 'No') {
                    $response = 'unlocked';
                } else {
                    $response['error'] = 'There was a problem';
                }
                break;

            case "getNameServers":
                if ($code == 300) {
                    $i = 0;
                    foreach (($xml->reply->nameservers->nameserver ?? []) as $ns) {
                        $response['ns' . ++$i] = (string)$ns;
                    }
                    break;
                }
                $response['error'] = $detail;
                break;

            case "getForwardingInfo":
                if ($code == 300) {
                    $response['traffic_type'] = $xml->reply->traffic_type;
                    $response['forward_url']  = $xml->reply->forward_url;
                    $response['forward_type'] = $xml->reply->forward_type;
                } else {
                    $response['error'] = $detail;
                }
                break;

            case "getContactID":
                if ($code == 300) {
                    $response['registrant'] = $xml->reply->contact_ids->registrant;
                    $response['admin']      = $xml->reply->contact_ids->administrative;
                    $response['tech']       = $xml->reply->contact_ids->technical;
                    break;
                }
                $response['error'] = $detail;
                break;

            case "contactAdd":
                if ($code == 300) {
                    $response['new_contact_id'] = $xml->reply->contact_id;
                    break;
                }
                $response['error'] = $detail;
                break;

            case "getContactDetails":
                if ($code == 300) {
                    $response['firstname']  = $xml->reply->contact->first_name;
                    $response['lastname']   = $xml->reply->contact->last_name;
                    $response['company']    = $xml->reply->contact->company;
                    $response['address']    = $xml->reply->contact->address;
                    $response['address2']   = $xml->reply->contact->address2;
                    $response['city']       = $xml->reply->contact->city;
                    $response['state']      = $xml->reply->contact->state;
                    $response['postalcode'] = $xml->reply->contact->zip;
                    $response['country']    = $xml->reply->contact->country;
                    $response['email']      = $xml->reply->contact->email;
                    $response['phone']      = $xml->reply->contact->phone;
                    $response['fax']        = $xml->reply->contact->fax;
                    break;
                }
                $response['error'] = $detail;
                break;

            case "listDNS":
                if ($code == 300) {
                    foreach (($xml->reply->resource_record ?? []) as $record) {
                        $hostname = (string)$record->host;
                        if ($hostname == $params['sld'] . "." . $params['tld']) {
                            $hostname = '';
                        }
                        $hostname = explode(".", $hostname);
                        $hostname = (string)$hostname[0];
                        $response[] = array(
                            "hostname"  => $hostname,
                            "type"      => $record->type,
                            "address"   => $record->value,
                            "record_id" => $record->record_id,
                            "priority"  => $record->distance
                        );
                    }
                    break;
                }
                $response['error'] = $detail;
                break;

            case "listEmailForwards":
                if ($code == 300) {
                    foreach (($xml->reply->addresses ?? []) as $record) {
                        $raw_email = (string)$record->email;
                        preg_match('/([^\@]+)@/', $raw_email, $m);
                        if (@$m[1]) {
                            $email   = $m[1];
                            $forward = (string)$record->forwards_to;
                            $response[] = array("email" => $email, "forward" => $forward);
                        }
                    }
                    break;
                }
                $response['error'] = $detail;
                break;

            case "domainAvailability":
                if ($code == 300) {
                    $response["domains"] = [];
                    foreach (($xml->reply->available->domain ?? []) as $aDomain) {
                        $response["domains"][] = array(
                            "domain"  => (string)$aDomain,
                            "price"   => (string)$aDomain["price"],
                            "premium" => (string)$aDomain["premium"],
                            "status"  => "available"
                        );
                    }

                    foreach (($xml->reply->unavailable->domain ?? []) as $uDomain) {
                        $response["domains"][] = array(
                            "domain"  => (string)$uDomain,
                            "price"   => "0.00",
                            "premium" => "0",
                            "status"  => "unavailable"
                        );
                    }

                    foreach (($xml->reply->invalid->domain ?? []) as $iDomain) {
                        $response["domains"][] = array(
                            "domain"  => (string)$iDomain,
                            "price"   => "0.00",
                            "premium" => "0",
                            "status"  => "invalid"
                        );
                    }

                    break;
                }
                $response['error'] = $detail;
                break;

            case "domainPricing":
                if ($code == 300) {
                    $response["prices"] = [];
                    $xmlReplyChildrenData = isset($xml->reply) ? $xml->reply->children() : [];
                    foreach ($xmlReplyChildrenData as $tld) {
                        if ($tld->count() === 3) {
                            $response["prices"][] = array(
                                "tld"          => (string)$tld->getName(),
                                "registration" => number_format((float)str_replace(',', '', $tld->registration), 2, '.', ''),
                                "renew"        => number_format((float)str_replace(',', '', $tld->renew),        2, '.', ''),
                                "transfer"     => number_format((float)str_replace(',', '', $tld->transfer),     2, '.', ''),
                            );
                        }
                    }
                    break;
                }
                $response['error'] = $detail;
                break;
        }
    } else {
        // Either cURL errored, or body was empty, or XML failed to parse.
        $response["error"] = $err ? "$err - $errmsg" : ("HTTP $httpCode with empty/invalid body");
    }

    // Optional: email debug as in your original
    if (isset($params['Debug_ON']) && $params['Debug_ON'] == 'on') {
        $to      = $params['Debug_Recipient'];
        $subject = 'Namesilo Registrar Module Debug Notification';
        $headers = "From: NameSilo Registrar Module<no-reply@" . $_SERVER['SERVER_NAME'] . ">\r\n" .
            'X-Mailer: PHP/' . phpversion();
        $message  = "Transaction Call: " . $call . "\n\n";
        $message .= "XML Response: " . $content . "\n\n";
        if (isset($response['error'])) {
            $message .= "Error Message: " . $response['error'] . "\n\n";
        }
        $message .= "Response Code: " . ($code ?? 'n/a') . "\n\n";
        $message .= "Response Detail: " . ($detail ?? 'n/a') . "\n\n";
        $message .= ($params["sld"] ?? '') . "." . ($params["tld"] ?? '');
        @mail($to, $subject, $message, $headers);
    }

    // Redact API key in log
    $action   = explode("?", str_replace(array(LIVE_API_SERVER, TEST_API_SERVER), "", $call));
    $callvars = isset($action[1]) ? explode("&", $action[1]) : [];
    $apikey   = '';
    foreach ($callvars as $callv) {
        $namevalue = explode("=", $callv);
        if (($namevalue[0] ?? '') === "key") {
            $apikey = $namevalue[1] ?? '';
            break;
        }
    }

    // Ensure response is an array before merging (handles string cases like getRegistrarLock)
    $logResponse = is_array($response) ? $response : ['result' => $response];

    // Log raw response body ($content) so Module Log shows the XML, not just Array()
    logModuleCall(
        "namesilo",                 // keep module id consistent
        $action[0] ?? 'call',       // action/path
        $call,                      // request
        $content,                   // raw response body (XML string)
        array_merge($logResponse, ['httpCode' => $httpCode]), // <-- changed line
        [$apikey]                   // redact API key
    );

    // Preserve your legacy behavior for 301/302 under "Standard"
    if ($callType == "Standard" && ($code == 301 || $code == 302)) {
        $response = [];
    }

    return $response;
}


function namesilo_GetDomainInformation(array $params)
{
    // Pick API env + key
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    $apiKey       = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];

    // Normalize TLD for IRTP check later
    $rawTld = $params['tld'];
    $tldDot = (strpos($rawTld, '.') === 0) ? strtolower($rawTld) : ('.' . strtolower($rawTld));

    // Encode
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);

    // --- Get domain info (now safe even when domain not active / not in account)
    $domain = namesilo_transactionCall(
        "domainSync",
        $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld",
        $params
    );

    // If our wrapper returned an error array, bail gracefully
    if (is_array($domain) && isset($domain['error'])) {
        $status = [
            'active'          => false,
            'cancelled'       => true,
            'transferredAway' => true,
            'expirydate'      => null,
        ];
        return (new \WHMCS\Domain\Registrar\Domain)
            ->setNameservers([])
            ->setRegistrationStatus($status)
            ->setTransferLock('unlocked')
            ->setTransferLockExpiryDate(null)
            ->setIsIrtpEnabled(false)
            ->setIrtpOptOutStatus(false)
            ->setRestorable(false)
            ->setDomainContactChangePending(false)
            ->setPendingSuspension(false);
    }

    // $domain is the <reply> node (SimpleXMLElement) on success
    $response = [];

    // Nameservers
    $response['nameservers'] = [];
    if (isset($domain->nameservers->nameserver)) {
        $i = 0;
        foreach ($domain->nameservers->nameserver as $ns) {
            $response['nameservers']['ns' . ++$i] = (string)$ns;
        }
    }

    // Status
    $statusStr = isset($domain->status) ? (string)$domain->status : '';
    $active = in_array($statusStr, ['Active', 'Grace', 'Redemption'], true);
    $response['status'] = [
        'active'          => $active,
        'cancelled'       => !$active,
        'transferredAway' => false,
        'expirydate'      => isset($domain->expires) ? (string)$domain->expires : null,
    ];

    // Transfer lock
    $transferLock = isset($domain->locked) ? (string)$domain->locked : '';
    if (strcasecmp($transferLock, 'Yes') === 0) {
        $response['transferlock'] = 'locked';
    } elseif (strcasecmp($transferLock, 'No') === 0) {
        $response['transferlock'] = 'unlocked';
    } else {
        $response['transferlock'] = 'unlocked'; // safe default
    }

    // Contact → registrant details
    $firstName = $lastName = $organisation = $email = '';
    if (isset($domain->contact_ids->registrant)) {
        $registrantId = (string)$domain->contact_ids->registrant;

        $details = namesilo_transactionCall(
            "getContactDetails",
            $apiServerUrl . "/api/contactList?version=1&type=xml&key=$apiKey&contact_id=" . urlencode($registrantId),
            $params
        );

        // Our transactionCall("getContactDetails") returns an array of fields on success
        if (is_array($details) && !isset($details['error'])) {
            $firstName    = (string)($details['firstname']  ?? '');
            $lastName     = (string)($details['lastname']   ?? '');
            $organisation = (string)($details['company']    ?? '');
            $email        = (string)($details['email']      ?? '');
        }
    }

    // Email verification (IRTP)
    $is_verified = false;
    if ($email !== '') {
        $verificationDetails = namesilo_transactionCall(
            'registrantVerificationStatus',
            $apiServerUrl . "/api/registrantVerificationStatus?version=1&type=xml&key=$apiKey&email=" . urlencode($email),
            $params
        );

        if (is_array($verificationDetails) && !isset($verificationDetails['error'])) {
            // For this call, wrapper returns ['email' => SimpleXMLElement]
            if (isset($verificationDetails['email']->verified)) {
                $is_verified = (strtolower((string)$verificationDetails['email']->verified) === 'yes');
            }
        }
    }

    if (!$is_verified) {
        return (new \WHMCS\Domain\Registrar\Domain)
            ->setIsIrtpEnabled(true)
            ->setIrtpOptOutStatus(false)
            ->setRestorable(false)
            ->setIrtpTransferLock(true)
            ->setDomainContactChangePending(true)
            ->setPendingSuspension(true)
            ->setIrtpVerificationTriggerFields([
                'Registrant' => [$firstName, $lastName, $organisation, $email],
            ]);
    }

    // Enable IRTP for common gTLDs (extend if you like)
    $irtpTlds = ['.com', '.net', '.org', '.biz', '.info', '.xyz', '.online', '.top', '.site', '.shop', '.app', '.dev'];
    $irtpEnabled = in_array($tldDot, $irtpTlds, true);

    return (new \WHMCS\Domain\Registrar\Domain)
        ->setNameservers($response['nameservers'])
        ->setRegistrationStatus($response['status'])
        ->setTransferLock($response['transferlock'])
        ->setTransferLockExpiryDate(null)
        ->setIsIrtpEnabled($irtpEnabled)
        ->setIrtpVerificationTriggerFields([
            'Registrant' => [$firstName, $lastName, $organisation, $email],
        ]);
}

function namesilo_ResendIRTPVerificationEmail($params)
{
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    $apiKey       = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];

    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);

    // Resolve registrant contact via getContactID case (your wrapper maps it out)
    $contact_ids = namesilo_transactionCall(
        "getContactID",
        $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld",
        $params
    );

    if (!is_array($contact_ids) || isset($contact_ids['error']) || empty($contact_ids['registrant'])) {
        return ['error' => 'Could not resolve registrant contact for this domain'];
    }

    $registrantId = (string)$contact_ids['registrant'];

    // Pull registrant contact → email
    $details = namesilo_transactionCall(
        "getContactDetails",
        $apiServerUrl . "/api/contactList?version=1&type=xml&key=$apiKey&contact_id=" . urlencode($registrantId),
        $params
    );

    if (!is_array($details) || isset($details['error']) || empty($details['email'])) {
        return ['error' => 'Registrant email not found'];
    }

    $email = (string)$details['email'];

    // IMPORTANT: callType "Standard" so your switch handles success (code=300) → empty array
    $result = namesilo_transactionCall(
        "Standard",
        $apiServerUrl . "/api/emailVerification?version=1&type=xml&key=$apiKey&email=" . urlencode($email),
        $params
    );

    if (is_array($result) && isset($result['error'])) {
        return ['error' => $result["error"]];
    }

    return ['success' => true];
}

/*****************************************/
/* Process .us/.ca params                */
/*****************************************/
function namesilo__convertUsParams($whmcsNexusCategory, $whmcsApplicationPurpose)
{
    $nsUsData = [];

    $nsUsData['usnc'] = urlencode($whmcsNexusCategory);

    $applicationPurposeCodes = [
        ['name' => 'Business use for profit', 'code' => 'P1'],
        ['name' => 'Club', 'code' => 'P2'],
        ['name' => 'Association', 'code' => 'P2'],
        ['name' => 'Religious Organization', 'code' => 'P2'],
        ['name' => 'Non-profit business', 'code' => 'P2'],
        ['name' => 'Personal Use', 'code' => 'P3'],
        ['name' => 'Educational purposes', 'code' => 'P4'],
        ['name' => 'Government purposes', 'code' => 'P5']
    ];

    $nsUsData['usap'] = '';

    foreach ($applicationPurposeCodes as $ap) {
        if ($ap['name'] == $whmcsApplicationPurpose) {
            $nsUsData['usap'] = urlencode($ap['code']);
            break;
        }
    }

    return $nsUsData;
}

function namesilo__convertCaParams($whmcslegalType, $whmcsCiraWhoisPivacy)
{
    $nsCaData = [];

    $nsCaData['caln'] = urlencode('en');
    $nsCaData['caag'] = urlencode('2.0');

    //API requires inverted values for WHOIS privacy
    if ($whmcsCiraWhoisPivacy == 'on') {
        $nsCaData['cawd'] = urlencode('0');
    } else {
        $nsCaData['cawd'] = urlencode('1');
    }

    $nsCaData['calf'] = '';

    $legalTypeCodes = [
        ['name' => 'Corporation', 'code' => 'CCO'],
        ['name' => 'Canadian Citizen', 'code' => 'CCT'],
        ['name' => 'Permanent Resident of Canada', 'code' => 'RES'],
        ['name' => 'Government', 'code' => 'GOV'],
        ['name' => 'Canadian Educational Institution', 'code' => 'EDU'],
        ['name' => 'Canadian Unincorporated Association', 'code' => 'ASS'],
        ['name' => 'Canadian Hospital', 'code' => 'HOP'],
        ['name' => 'Partnership Registered in Canada', 'code' => 'PRT'],
        ['name' => 'Trade-mark registered in Canada', 'code' => 'TDM'],
        ['name' => 'Canadian Trade Union', 'code' => 'TRD'],
        ['name' => 'Canadian Political Party', 'code' => 'PLT'],
        ['name' => 'Canadian Library Archive or Museum', 'code' => 'LAM'],
        ['name' => 'Trust established in Canada', 'code' => 'TRS'],
        ['name' => 'Aboriginal Peoples', 'code' => 'ABO'],
        ['name' => 'Legal Representative of a Canadian Citizen', 'code' => 'LGR'],
        ['name' => 'Official mark registered in Canada', 'code' => 'OMK']
    ];

    foreach ($legalTypeCodes as $lt) {
        if ($lt['name'] == $whmcslegalType) {
            $nsCaData['calf'] = urlencode($lt['code']);
            break;
        }
    }

    return $nsCaData;
}

/*****************************************/
/* Process WHOIS privacy support         */
/*****************************************/
function namesilo__whoisPrivacySupported($tld)
{
    $notSupportedTlds = ['us', 'in', 'tickets', 'nl', 'eu', 'uk', 'at'];

    $tld = preg_replace('/^\./', '', $tld);

    if (in_array($tld, $notSupportedTlds)) {
        return false;
    }

    return true;
}

/*****************************************/
/* Remove all DNS records                */
/*****************************************/
function namesilo__deleteDnsRecords($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);

    $result = [];
    # Get domain DNS records
    $response = namesilo_transactionCall('listDNS', $apiServerUrl . "/api/dnsListRecords?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);

    if (!(isset($response['error']))) {
        foreach ($response as $dnsRecord) {
            #Remove each record using its code, collect responses from transactionCall
            $result[] = namesilo_transactionCall('Standard', $apiServerUrl . "/api/dnsDeleteRecord?version=1&type=xml&key=$apiKey&domain=$sld.$tld&rrid=" . urlencode($dnsRecord['record_id']), $params);
        }
    } else {
        $result[] = $response['error'];
    }

    return $result;
}

/*****************************************/
/* Create contact and run action         */
/*****************************************/
function namesilo__registerContactAction($contactAddCall, $actionCall, $params, $vars)
{
    //Registers a contact then uses that contact to run an API call
    //Required apiServerUrl, apiKey in $vars

    $contactAddResult = namesilo_transactionCall('contactAdd', $contactAddCall, $params);

    if (isset($contactAddResult['error'])) {
        return ['error' => $contactAddResult['error']];
    }

    $actionCall .= '&contact_id=' . $contactAddResult['new_contact_id'];

    $actionResult = namesilo_transactionCall("Standard", $actionCall, $params);

    if (isset($actionResult['error'])) {
        namesilo_transactionCall("Standard", $vars['apiServerUrl'] . "/api/contactDelete?version=1&type=xml&key=" . $vars['apiKey'] . "&contact_id=" . $contactAddResult['new_contact_id'], $params);
    }

    return $actionResult;
}

/*****************************************/
/* Retrieve Domain's Name Servers        */
/*****************************************/
function namesilo_GetNameservers($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    return namesilo_transactionCall("getNameServers", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
}

/*****************************************/
/* Update Domain's Name Servers          */
/*****************************************/
function namesilo_SaveNameservers($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Prepare Name Server Information to Send
    $nameserver1 = urlencode($params["ns1"]);
    $nameserver2 = urlencode($params["ns2"]);
    $nameserver3 = urlencode($params["ns3"]);
    $nameserver4 = urlencode($params["ns4"]);
    $nameserver5 = urlencode($params["ns5"]);
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/changeNameServers?version=1&type=xml&key=$apiKey&domain=$sld.$tld&ns1=$nameserver1&ns2=$nameserver2&ns3=$nameserver3&ns4=$nameserver4&ns5=$nameserver5", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Retrieve Registrar Lock Status        */
/*****************************************/
function namesilo_GetRegistrarLock($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    $lockstatus = namesilo_transactionCall("getRegistrarLock", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    # Return Results
    return $lockstatus;
}

/*****************************************/
/* Update Registrar Lock Status          */
/*****************************************/
function namesilo_SaveRegistrarLock($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Determine Lock Status and Run Appropriate Call
    if ($params["lockenabled"] == "unlocked") {
        # Transaction Call
        $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/domainUnlock?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    } else {
        # Transaction Call
        $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/domainLock?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    }
    # Return Results
    return $values;
}

/*
/*****************************************/
/* Retrieve DNS Records for a Domain     */
/*****************************************/
function namesilo_GetDNS($params)
{
    //subdomain forwarding handling
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    $hostrecords = namesilo_transactionCall("listDNS", $apiServerUrl . "/api/dnsListRecords?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    # Do domain info looking for forwarding details if domain is forwarded
    $result = namesilo_transactionCall("getForwardingInfo", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    if ($result['traffic_type'] == 'Forwarded') {

        preg_match('/(https*)\:\/\/(.*)/is', $result['forward_url'], $m);
        if (@$m[1] && @$m[2]) {

            $forward_host = $m[1];
            $forward_address = $m[2];
            $forward_type = (stripos($result['forward_type'], 'cloak') !== FALSE) ? 'FRAME' : 'URL';
        }

        $hostrecords[] = array("hostname" => $forward_host, "type" => $forward_type, "address" => $forward_address, "record_id" => 'ns_force_forward', "priority" => '');
    }
    # Return Results
    return $hostrecords;
}

/*****************************************/
/* Update DNS Records for a Domain       */
/*****************************************/
function namesilo_SaveDNS($params)
{

    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Retrieve Record IDs for existing records
    $hostrecords = namesilo_transactionCall("listDNS", $apiServerUrl . "/api/dnsListRecords?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    foreach ($hostrecords as $host) {
        $record_id = $host["record_id"];
        # Remove existing records (except base domain)
        namesilo_transactionCall("Standard", $apiServerUrl . "/api/dnsDeleteRecord?version=1&type=xml&key=$apiKey&domain=$sld.$tld&rrid=$record_id", $params);
    }
    # First, check for forwarding
    foreach (($params["dnsrecords"] ?? []) as $key => $values) {

        if (($values['type'] == 'URL' || $values['type'] == 'FRAME') && ($values['hostname'] == 'http' || $values['hostname'] == 'https') && @$values['address']) {
            $forward_used = 1;
        }

        if (($values['type'] == 'URL' || $values['type'] == 'FRAME') && $values['hostname'] != 'http' && $values['hostname'] != 'https') {

            if (@$values['hostname']) {
                $value_error_override = 'You must use either "http" or "https" in the Host Name field for Domain Forwarding.';
            } else {
                $value_error_override = 'A value for Host Name must be entered. Domain Forwarding has been disabled.';
            }
        } elseif (($values['type'] == 'URL' || $values['type'] == 'FRAME') && ($values['hostname'] == 'http' || $values['hostname'] == 'https') && !@$values['address']) {

            $value_error_override = 'A value for Address must be entered. Domain Forwarding has been disabled.';
        }
    }
    # If no forwarding used, set domain to custom DNS
    if (!@$forward_used) {
        namesilo_transactionCall("Standard", $apiServerUrl . "/api/forceDomainTrafficType?version=1&type=xml&key=$apiKey&domain=$sld.$tld&traffic_type=3", $params);
    }
    # Now, add each record
    $api_dns_calls = array();

    foreach (($params["dnsrecords"] ?? []) as $key => $values) {
        $hostname = urlencode(trim($values["hostname"]));
        $type = $values["type"];
        $address = urlencode(trim($values["address"]));
        $priority = urlencode(trim($values["priority"]));

        # Check for forwarding and handle differently if it is a forward
        if (($values['type'] == 'URL' || $values['type'] == 'FRAME') && ($values['hostname'] == 'http' || $values['hostname'] == 'https') && @$values['address']) {

            $forward_method = ($values['type'] == 'URL' ? '301' : 'cloaked');
            $apicall = "/api/domainForward?version=1&type=xml&key=$apiKey&domain=$sld.$tld&protocol={$hostname}&address={$address}&method=$forward_method";
        } else {
            # Check to make sure there is something to add
            //if (empty($hostname) || empty($address)) { continue; }
            if (empty($address)) {
                continue;
            }
            if ($type == 'MX' && empty($priority)) {
                continue;
            }

            if ($forward_used && ($hostname == $sld)) {
                continue;
            }

            # Build API Call
            $apicall = "/api/dnsAddRecord?version=1&type=xml&key=$apiKey&domain=$sld.$tld&rrtype=$type&rrhost=$hostname&rrvalue=$address&rrttl=7207";
            if ($type == 'MX' && !empty($priority)) {
                $apicall .= "&rrdistance=$priority";
            }
        }

        # API call to add record
        if (!in_array($apicall, $api_dns_calls)) {

            $api_dns_calls[] = $apicall;
            $values = namesilo_transactionCall("Standard", $apiServerUrl . $apicall, $params);
        }
    }
    # Return Results
    if (@$value_error_override) {
        $values['error'] = $value_error_override;
    }

    return $values;
}

/*****************************************/
/* Register New Domain                   */
/*****************************************/
function namesilo_RegisterDomain($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Set Appropriate Private Trigger
    $private = "0";
    # Set Appropriate Auto-Renew Trigger
    $auto_renew = ($params['Auto_Renew'] == "on") ? '1' : '0';
    # Register Variables;
    $paymentid = ($params['Test_Mode'] == 'on') ? $params['Sandbox_Payment_ID'] : $params['Payment_ID'];
    $deleteDefaultDns = $params['Delete_Default_DNS'] == 'on';
    //$coupon = urlencode($params["Coupon"]);
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $regperiod = $params["regperiod"];
    $nameserver1 = $params["ns1"];
    $nameserver2 = $params["ns2"];
    $nameserver3 = $params["ns3"];
    $nameserver4 = $params["ns4"];
    $nameserver5 = $params["ns5"];
    # Registrant Details
    $RegistrantFirstName = urlencode($params["firstname"]);
    $RegistrantLastName = urlencode($params["lastname"]);
    $RegistrantAddress1 = urlencode($params["address1"]);
    $RegistrantAddress2 = urlencode($params["address2"]);
    $RegistrantCity = urlencode($params["city"]);
    $RegistrantStateProvince = urlencode($params["state"]);
    $RegistrantPostalCode = urlencode($params["postcode"]);
    $RegistrantCountry = urlencode($params["country"]);
    $RegistrantEmailAddress = urlencode($params["email"]);
    $RegistrantPhone = urlencode($params["phonenumber"]);

    if (($params["idprotection"] || $params['Default_Privacy'] == "on") && namesilo__whoisPrivacySupported($tld)) {
        $private = "1";
    }

    # Transaction Call
    //US-CA handling
    if (strtolower($params['tld']) == 'ca' || strtolower($params['tld']) == 'us') {
        $vars = array(
            'apiServerUrl' => $apiServerUrl,
            'apiKey' => $apiKey,
            'RegistrantFirstName' => $RegistrantFirstName,
            'RegistrantLastName' => $RegistrantLastName,
            'RegistrantAddress1' => $RegistrantAddress1,
            'RegistrantAddress2' => $RegistrantAddress2,
            'RegistrantCity' => $RegistrantCity,
            'RegistrantStateProvince' => $RegistrantStateProvince,
            'RegistrantPostalCode' => $RegistrantPostalCode,
            'RegistrantCountry' => $RegistrantCountry,
            'RegistrantEmailAddress' => $RegistrantEmailAddress,
            'RegistrantPhone' => $RegistrantPhone,
            'sld' => $sld,
            'tld' => $tld,
            'regperiod' => $regperiod,
            'paymentid' => $paymentid,
            'private' => $private,
            'nameserver1' => $nameserver1,
            'nameserver2' => $nameserver2,
            'nameserver3' => $nameserver3,
            'nameserver4' => $nameserver4,
            'nameserver5' => $nameserver5,
            'auto_renew' => $auto_renew
        );

        if (strtolower($params['tld']) == 'ca') {
            $values = namesilo__registerCaDomain($vars, $params);
        } elseif (strtolower($params['tld']) == 'us') {
            $values = namesilo__registerUsDomain($vars, $params);
        }
    } else {
        //Regular registration call
        $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/registerDomain?version=1&type=xml&key=$apiKey&domain=$sld.$tld&years=$regperiod&payment_id=$paymentid&private=$private&ns1=$nameserver1&ns2=$nameserver2&ns3=$nameserver3&ns4=$nameserver4&ns5=$nameserver5&fn=$RegistrantFirstName&ln=$RegistrantLastName&ad=$RegistrantAddress1&ad2=$RegistrantAddress2&cy=$RegistrantCity&st=$RegistrantStateProvince&zp=$RegistrantPostalCode&ct=$RegistrantCountry&em=$RegistrantEmailAddress&ph=$RegistrantPhone&auto_renew=$auto_renew", $params);
    }

    # Delete default DNS records if the domain uses namesilo name servers
    if ($deleteDefaultDns) {
        //Use API to get name servers
        //Default name servers are used when the API call doesn't include them, when the name servers have errors or when requested
        $domainNameServers = namesilo_transactionCall("getNameServers", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
        $namesiloNameServers = ['ns1.dnsowl.com', 'ns2.dnsowl.com', 'ns3.dnsowl.com' . 'premium-ns1.dnsowl.com', 'premium-ns2.dnsowl.com', 'premium-ns3.dnsowl.com'];

        foreach ($domainNameServers as $nsKey => $nsValue) {
            if (in_array(strtolower($nsValue), $namesiloNameServers)) {
                //If a name server matches the defaults, delete (default) DNS records
                namesilo__deleteDnsRecords($params);
                break;
            }
        }
    }

    if (isset($values['error'])) {
        if ($values['error'] == 'Invalid number of years, or no years provided.' && $regperiod > 0 && $regperiod <= 10) {
            $values['error'] = 'Invalid number of years, or no years provided. If a valid number was entered the domain does not support multiple year registrations at the moment, to add extra years please regsiter the domain for one year then  use the renewal process to add extra years.';
        }
    }

    # Return Results
    return $values;
}

/*****************************************/
/* TLD specific registrations            */
/*****************************************/
function namesilo__registerContactDomain($contactAddCall, $registrationCall, $params, $vars)
{
    //Registers a contact then uses that contact to register a domain
    //Required apiKey in $vars

    return namesilo__registerContactAction($contactAddCall, $registrationCall, $params, $vars);
}

function namesilo__registerUsDomain($vars, $params)
{
    //Required vars: apiServerUrl, apiKey, RegistrantFirstName, RegistrantLastName, RegistrantAddress1, RegistrantAddress2, RegistrantCity, RegistrantStateProvince, RegistrantPostalCode, RegistrantCountry, RegistrantEmailAddress, RegistrantPhone, sld, tld, regperiod, paymentid, private, nameserver1, nameserver2, nameserver3, nameserver4, nameserver5, auto_renew

    $tldParams = namesilo__convertUsParams($params['additionalfields']['Nexus Category'], $params['additionalfields']['Application Purpose']);

    $newContactCall = $vars['apiServerUrl'] . "/api/contactAdd?version=1&type=xml&key=" . $vars['apiKey'] . "&fn=" . $vars['RegistrantFirstName'] . "&ln=" . $vars['RegistrantLastName'] . "&ad=" . $vars['RegistrantAddress1'] . "&ad2=" . $vars['RegistrantAddress2'] . "&cy=" . $vars['RegistrantCity'] . "&st=" . $vars['RegistrantStateProvince'] . "&zp=" . $vars['RegistrantPostalCode'] . "&ct=" . $vars['RegistrantCountry'] . "&em=" . $vars['RegistrantEmailAddress'] . "&ph=" . $vars['RegistrantPhone'];

    foreach ($tldParams as $apiParam => $apiVal) {
        $newContactCall .= '&' . $apiParam . '=' . $apiVal;
    }

    $newRegistrationCall = $vars['apiServerUrl'] . "/api/registerDomain?version=1&type=xml&key=" . $vars['apiKey'] . "&domain=" . $vars['sld'] . "." . $vars['tld'] . "&years=" . $vars['regperiod'] . "&payment_id=" . $vars['paymentid'] . "&private=" . $vars['private'] . "&ns1=" . $vars['nameserver1'] . "&ns2=" . $vars['nameserver2'] . "&ns3=" . $vars['nameserver3'] . "&ns4=" . $vars['nameserver4'] . "&ns5=" . $vars['nameserver5'] . "&auto_renew=" . $vars['auto_renew'];

    return namesilo__registerContactDomain($newContactCall, $newRegistrationCall, $params, $vars);
}

function namesilo__registerCaDomain($vars, $params)
{
    //Required vars: apiServerUrl, apiKey, RegistrantFirstName, RegistrantLastName, RegistrantAddress1, RegistrantAddress2, RegistrantCity, RegistrantStateProvince, RegistrantPostalCode, RegistrantCountry, RegistrantEmailAddress, RegistrantPhone, sld, tld, regperiod, paymentid, private, nameserver1, nameserver2, nameserver3, nameserver4, nameserver5, auto_renew

    if ($params['additionalfields']['CIRA Agreement'] != 'on') {
        return ['error' => 'CIRA agreement not accepted.'];
    }

    $params['additionalfields']['WHOIS Opt-out'] == 'on' || $vars['private'] == "1" ? $caWhoisPrivacy = 'on' : $caWhoisPrivacy = '';

    $tldParams = namesilo__convertCaParams($params['additionalfields']['Legal Type'], $caWhoisPrivacy);

    $newContactCall = $vars['apiServerUrl'] . "/api/contactAdd?version=1&type=xml&key=" . $vars['apiKey'] . "&fn=" . $vars['RegistrantFirstName'] . "&ln=" . $vars['RegistrantLastName'] . "&ad=" . $vars['RegistrantAddress1'] . "&ad2=" . $vars['RegistrantAddress2'] . "&cy=" . $vars['RegistrantCity'] . "&st=" . $vars['RegistrantStateProvince'] . "&zp=" . $vars['RegistrantPostalCode'] . "&ct=" . $vars['RegistrantCountry'] . "&em=" . $vars['RegistrantEmailAddress'] . "&ph=" . $vars['RegistrantPhone'];

    foreach ($tldParams as $apiParam => $apiVal) {
        $newContactCall .= '&' . $apiParam . '=' . $apiVal;
    }

    $newRegistrationCall = $vars['apiServerUrl'] . "/api/registerDomain?version=1&type=xml&key=" . $vars['apiKey'] . "&domain=" . $vars['sld'] . "." . $vars['tld'] . "&years=" . $vars['regperiod'] . "&payment_id=" . $vars['paymentid'] . "&private=" . $vars['private'] . "&ns1=" . $vars['nameserver1'] . "&ns2=" . $vars['nameserver2'] . "&ns3=" . $vars['nameserver3'] . "&ns4=" . $vars['nameserver4'] . "&ns5=" . $vars['nameserver5'] . "&auto_renew=" . $vars['auto_renew'];

    return namesilo__registerContactDomain($newContactCall, $newRegistrationCall, $params, $vars);
}

/*****************************************/
/* Initiate Domain Transfer              */
/*****************************************/
function namesilo_TransferDomain($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Set Appropriate Private Trigger
    $private = "0";
    # Set Appropriate Auto-Renew Trigger
    $auto_renew = ($params['Auto_Renew'] == "on") ? '1' : '0';
    # Register Variables
    $paymentid = ($params['Test_Mode'] == 'on') ? $params['Sandbox_Payment_ID'] : $params['Payment_ID'];
    //$coupon = urlencode($params["Coupon"]);
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $transfersecret = urlencode($params["transfersecret"]);
    # Registrant Details
    $RegistrantFirstName = urlencode($params["firstname"]);
    $RegistrantLastName = urlencode($params["lastname"]);
    $RegistrantAddress1 = urlencode($params["address1"]);
    $RegistrantAddress2 = urlencode($params["address2"]);
    $RegistrantCity = urlencode($params["city"]);
    $RegistrantStateProvince = urlencode($params["state"]);
    $RegistrantPostalCode = urlencode($params["postcode"]);
    $RegistrantCountry = urlencode($params["country"]);
    $RegistrantEmailAddress = urlencode($params["email"]);
    $RegistrantPhone = urlencode($params["phonenumber"]);

    if (($params["idprotection"] || $params['Default_Privacy'] == "on") && namesilo__whoisPrivacySupported($tld)) {
        $private = "1";
    }

    # Transaction Call
    //US-CA handling
    if (strtolower($params['tld']) == 'ca' || strtolower($params['tld']) == 'us') {
        $vars = array(
            'apiServerUrl' => $apiServerUrl,
            'apiKey' => $apiKey,
            'RegistrantFirstName' => $RegistrantFirstName,
            'RegistrantLastName' => $RegistrantLastName,
            'RegistrantAddress1' => $RegistrantAddress1,
            'RegistrantAddress2' => $RegistrantAddress2,
            'RegistrantCity' => $RegistrantCity,
            'RegistrantStateProvince' => $RegistrantStateProvince,
            'RegistrantPostalCode' => $RegistrantPostalCode,
            'RegistrantCountry' => $RegistrantCountry,
            'RegistrantEmailAddress' => $RegistrantEmailAddress,
            'RegistrantPhone' => $RegistrantPhone,
            'sld' => $sld,
            'tld' => $tld,
            'transfersecret' => $transfersecret,
            'paymentid' => $paymentid,
            'private' => $private,
            'auto_renew' => $auto_renew
        );

        if (strtolower($params['tld']) == 'ca') {
            $values = namesilo__transferCaDomain($vars, $params);
        } elseif (strtolower($params['tld']) == 'us') {
            $values = namesilo__transferUsDomain($vars, $params);
        }
    } else {
        //Regular transfer call
        $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/transferDomain?version=1&type=xml&key=$apiKey&domain=$sld.$tld&auth=$transfersecret&payment_id=$paymentid&private=$private&fn=$RegistrantFirstName&ln=$RegistrantLastName&ad=$RegistrantAddress1&ad2=$RegistrantAddress2&cy=$RegistrantCity&st=$RegistrantStateProvince&zp=$RegistrantPostalCode&ct=$RegistrantCountry&em=$RegistrantEmailAddress&ph=$RegistrantPhone&auto_renew=$auto_renew", $params);
    }

    # Return Results
    return $values;
}

/*****************************************/
/* TLD specific transfers                */
/*****************************************/
function namesilo__transferContactDomain($contactAddCall, $registrationTransferCall, $params, $vars)
{
    //Registers a contact then uses that contact to register or transfer a domain
    //Required apiKey in $vars

    return namesilo__registerContactAction($contactAddCall, $registrationTransferCall, $params, $vars);
}

function namesilo__transferUsDomain($vars, $params)
{
    //Required vars: apiServerUrl, apiKey, RegistrantFirstName, RegistrantLastName, RegistrantAddress1, RegistrantAddress2, RegistrantCity, RegistrantStateProvince, RegistrantPostalCode, RegistrantCountry, RegistrantEmailAddress, RegistrantPhone, sld, tld, transfersecret, paymentid, private, auto_renew

    $tldParams = namesilo__convertUsParams($params['additionalfields']['Nexus Category'], $params['additionalfields']['Application Purpose']);

    $newContactCall = $vars['apiServerUrl'] . "/api/contactAdd?version=1&type=xml&key=" . $vars['apiKey'] . "&fn=" . $vars['RegistrantFirstName'] . "&ln=" . $vars['RegistrantLastName'] . "&ad=" . $vars['RegistrantAddress1'] . "&ad2=" . $vars['RegistrantAddress2'] . "&cy=" . $vars['RegistrantCity'] . "&st=" . $vars['RegistrantStateProvince'] . "&zp=" . $vars['RegistrantPostalCode'] . "&ct=" . $vars['RegistrantCountry'] . "&em=" . $vars['RegistrantEmailAddress'] . "&ph=" . $vars['RegistrantPhone'];

    foreach ($tldParams as $apiParam => $apiVal) {
        $newContactCall .= '&' . $apiParam . '=' . $apiVal;
    }

    $newTransferCall = $vars['apiServerUrl'] . "/api/transferDomain?version=1&type=xml&key=" . $vars['apiKey'] . "&domain=" . $vars['sld'] . "." . $vars['tld'] . "&auth=" . $vars['transfersecret'] . "&payment_id=" . $vars['paymentid'] . "&private=" . $vars['private'] . "&auto_renew=" . $vars['auto_renew'];

    return namesilo__transferContactDomain($newContactCall, $newTransferCall, $params, $vars);
}

function namesilo__transferCaDomain($vars, $params)
{
    //Required vars: apiServerUrl, apiKey, RegistrantFirstName, RegistrantLastName, RegistrantAddress1, RegistrantAddress2, RegistrantCity, RegistrantStateProvince, RegistrantPostalCode, RegistrantCountry, RegistrantEmailAddress, RegistrantPhone, sld, tld, transfersecret, paymentid, private, auto_renew

    if ($params['additionalfields']['CIRA Agreement'] != 'on') {
        return ['error' => 'CIRA agreement not accepted.'];
    }

    $params['additionalfields']['WHOIS Opt-out'] == 'on' || $vars['private'] == "1" ? $caWhoisPrivacy = 'on' : $caWhoisPrivacy = '';

    $tldParams = namesilo__convertCaParams($params['additionalfields']['Legal Type'], $caWhoisPrivacy);

    $newContactCall = $vars['apiServerUrl'] . "/api/contactAdd?version=1&type=xml&key=" . $vars['apiKey'] . "&fn=" . $vars['RegistrantFirstName'] . "&ln=" . $vars['RegistrantLastName'] . "&ad=" . $vars['RegistrantAddress1'] . "&ad2=" . $vars['RegistrantAddress2'] . "&cy=" . $vars['RegistrantCity'] . "&st=" . $vars['RegistrantStateProvince'] . "&zp=" . $vars['RegistrantPostalCode'] . "&ct=" . $vars['RegistrantCountry'] . "&em=" . $vars['RegistrantEmailAddress'] . "&ph=" . $vars['RegistrantPhone'];

    foreach ($tldParams as $apiParam => $apiVal) {
        $newContactCall .= '&' . $apiParam . '=' . $apiVal;
    }

    $newTransferCall = $vars['apiServerUrl'] . "/api/transferDomain?version=1&type=xml&key=" . $vars['apiKey'] . "&domain=" . $vars['sld'] . "." . $vars['tld'] . "&auth=" . $vars['transfersecret'] . "&payment_id=" . $vars['paymentid'] . "&private=" . $vars['private'] . "&auto_renew=" . $vars['auto_renew'];

    return namesilo__transferContactDomain($newContactCall, $newTransferCall, $params, $vars);
}

/*****************************************/
/* Renew Domain                          */
/*****************************************/
function namesilo_RenewDomain($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $paymentid = ($params['Test_Mode'] == 'on') ? $params['Sandbox_Payment_ID'] : $params['Payment_ID'];
    //$coupon = urlencode($params["Coupon"]);
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $regperiod = $params["regperiod"];
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/renewDomain?version=1&type=xml&key=$apiKey&domain=$sld.$tld&years=$regperiod&payment_id=$paymentid", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Retrieve Domain Contact Details       */
/*****************************************/
function namesilo_GetContactDetails($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    $contactId = namesilo_transactionCall("getContactID", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);

    $roles = ['Registrant', 'Admin', 'Tech'];
    $values = [];

    foreach ($roles as $role) {
        $details = namesilo_transactionCall("getContactDetails", "$apiServerUrl/api/contactList?version=1&type=xml&key=$apiKey&contact_id={$contactId[\strtolower($role)]}", $params);

        $values[$role]["First Name"] = (string)$details['firstname'];
        $values[$role]["Last Name"] = (string)$details['lastname'];
        $values[$role]["Company"] = (string)$details['company'];
        $values[$role]["Address"] = (string)$details['address'];
        $values[$role]["Address 2"] = (string)$details['address2'];
        $values[$role]["City"] = (string) $details['city'];
        $values[$role]["State"] = (string)$details['state'];
        $values[$role]["Postal Code"] = (string)$details['postalcode'];
        $values[$role]["Country"] = (string)$details['country'];
        $values[$role]["Email"] = (string)$details['email'];
        $values[$role]["Phone"] = (string)$details['phone'];
        $values[$role]["Fax"] = (string)$details['fax'];
    }

    return $values;
}

/*****************************************/
/* Update Domain Contact Details         */
/*****************************************/
function namesilo_SaveContactDetails($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Data is returned as specified in the GetContactDetails() function

    # Get the contact IDs that are currently associated with the domain
    $contactid = namesilo_transactionCall("getContactID", $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);

    # Default IDs to associate with their current values (only change if needed based on code below)
    $update_reg_id = $contactid['registrant'];
    $update_admin_id = $contactid['admin'];
    $update_tech_id = $contactid['tech'];

    //Regsitrant

    //Get Current Registrant Info
    $current_registrant = namesilo_transactionCall("getContactDetails", $apiServerUrl . "/api/contactList?version=1&type=xml&key=$apiKey&contact_id={$contactid['registrant']}", $params);

    $c_reg_firstname = trim(urlencode($current_registrant['firstname']));
    $c_reg_lastname = trim(urlencode($current_registrant['lastname']));
    $c_reg_company = trim(urlencode($current_registrant['company']));
    $c_reg_address = trim(urlencode($current_registrant['address']));
    $c_reg_address2 = trim(urlencode($current_registrant['address2']));
    $c_reg_city = trim(urlencode($current_registrant['city']));
    $c_reg_state = trim(urlencode($current_registrant['state']));
    $c_reg_zip = trim(urlencode($current_registrant['postalcode']));
    $c_reg_country = trim(urlencode($current_registrant['country']));
    $c_reg_email = trim(urlencode($current_registrant['email']));
    $c_reg_phone = trim(urlencode($current_registrant['phone']));
    $c_reg_fax = trim(urlencode($current_registrant['fax']));

    $md5_c_reg = md5($c_reg_firstname . $c_reg_lastname . $c_reg_company . $c_reg_address . $c_reg_address2 . $c_reg_city . $c_reg_state . $c_reg_zip . $c_reg_country . $c_reg_email . $c_reg_phone . $c_reg_fax);

    //Get Entered Registrant Info
    $e_reg_firstname = trim(urlencode($params["contactdetails"]["Registrant"]["First Name"]));
    $e_reg_lastname = trim(urlencode($params["contactdetails"]["Registrant"]["Last Name"]));
    $e_reg_company = trim(urlencode($params["contactdetails"]["Registrant"]["Company"]));
    $e_reg_address = trim(urlencode($params["contactdetails"]["Registrant"]["Address"]));
    $e_reg_address2 = trim(urlencode($params["contactdetails"]["Registrant"]["Address 2"]));
    $e_reg_city = trim(urlencode($params["contactdetails"]["Registrant"]["City"]));
    $e_reg_state = trim(urlencode($params["contactdetails"]["Registrant"]["State"]));
    $e_reg_zip = trim(urlencode(@$params["contactdetails"]["Registrant"]["Postcode"] ? $params["contactdetails"]["Registrant"]["Postcode"] : $params["contactdetails"]["Registrant"]["Postal Code"]));
    $e_reg_country = trim(urlencode($params["contactdetails"]["Registrant"]["Country"]));
    $e_reg_email = trim(urlencode($params["contactdetails"]["Registrant"]["Email"]));
    $e_reg_phone = trim(urlencode($params["contactdetails"]["Registrant"]["Phone"]));
    $e_reg_fax = trim(urlencode($params["contactdetails"]["Registrant"]["Fax"]));

    $md5_e_reg = md5($e_reg_firstname . $e_reg_lastname . $e_reg_company . $e_reg_address . $e_reg_address2 . $e_reg_city . $e_reg_state . $e_reg_zip . $e_reg_country . $e_reg_email . $e_reg_phone . $e_reg_fax);

    //Create new contact profile and associate it if necessary
    if ($md5_c_reg != $md5_e_reg) {

        $new_reg_id = namesilo_transactionCall("contactAdd", $apiServerUrl . "/api/contactAdd?version=1&type=xml&key=$apiKey&fn=$e_reg_firstname&ln=$e_reg_lastname&cp=$e_reg_company&ad=$e_reg_address&ad2=$e_reg_address2&cy=$e_reg_city&st=$e_reg_state&zp=$e_reg_zip&ct=$e_reg_country&em=$e_reg_email&ph=$e_reg_phone&fx=$e_reg_fax", $params);
        if (@$new_reg_id['error']) {
            return $new_reg_id;
        }

        $update_reg_id = $new_reg_id['new_contact_id'];
    }

    //Admin

    //Get Current Admin Info
    $current_admin = namesilo_transactionCall("getContactDetails", $apiServerUrl . "/api/contactList?version=1&type=xml&key=$apiKey&contact_id={$contactid['admin']}", $params);

    $c_admin_firstname = trim(urlencode($current_admin['firstname']));
    $c_admin_lastname = trim(urlencode($current_admin['lastname']));
    $c_admin_company = trim(urlencode($current_admin['company']));
    $c_admin_address = trim(urlencode($current_admin['address']));
    $c_admin_address2 = trim(urlencode($current_admin['address2']));
    $c_admin_city = trim(urlencode($current_admin['city']));
    $c_admin_state = trim(urlencode($current_admin['state']));
    $c_admin_zip = trim(urlencode($current_admin['postalcode']));
    $c_admin_country = trim(urlencode($current_admin['country']));
    $c_admin_email = trim(urlencode($current_admin['email']));
    $c_admin_phone = trim(urlencode($current_admin['phone']));
    $c_admin_fax = trim(urlencode($current_admin['fax']));

    $md5_c_admin = $c_admin_firstname . $c_admin_lastname . $c_admin_company . $c_admin_address . $c_admin_address2 . $c_admin_city . $c_admin_state . $c_admin_zip . $c_admin_country . $c_admin_email . $c_admin_phone . $c_admin_fax;

    //Get Entered Admin Info
    $e_admin_firstname = trim(urlencode($params["contactdetails"]["Admin"]["First Name"]));
    $e_admin_lastname = trim(urlencode($params["contactdetails"]["Admin"]["Last Name"]));
    $e_admin_company = trim(urlencode($params["contactdetails"]["Admin"]["Company"]));
    $e_admin_address = trim(urlencode($params["contactdetails"]["Admin"]["Address"]));
    $e_admin_address2 = trim(urlencode($params["contactdetails"]["Admin"]["Address 2"]));
    $e_admin_city = trim(urlencode($params["contactdetails"]["Admin"]["City"]));
    $e_admin_state = trim(urlencode($params["contactdetails"]["Admin"]["State"]));
    $e_admin_zip = trim(urlencode(@$params["contactdetails"]["Admin"]["Postcode"] ? $params["contactdetails"]["Admin"]["Postcode"] : $params["contactdetails"]["Admin"]["Postal Code"]));
    $e_admin_country = trim(urlencode($params["contactdetails"]["Admin"]["Country"]));
    $e_admin_email = trim(urlencode($params["contactdetails"]["Admin"]["Email"]));
    $e_admin_phone = trim(urlencode($params["contactdetails"]["Admin"]["Phone"]));
    $e_admin_fax = trim(urlencode($params["contactdetails"]["Admin"]["Fax"]));

    $md5_e_admin = $e_admin_firstname . $e_admin_lastname . $e_admin_company . $e_admin_address . $e_admin_address2 . $e_admin_city . $e_admin_state . $e_admin_zip . $e_admin_country . $e_admin_email . $e_admin_phone . $e_admin_fax;
    //echo $md5_c_admin. '<br>'. $md5_e_admin; exit;

    //Create new contact profile and associate it if necessary
    if ($md5_c_admin != $md5_e_admin) {

        $new_admin_id = namesilo_transactionCall("contactAdd", $apiServerUrl . "/api/contactAdd?version=1&type=xml&key=$apiKey&fn=$e_admin_firstname&ln=$e_admin_lastname&cp=$e_admin_company&ad=$e_admin_address&ad2=$e_admin_address2&cy=$e_admin_city&st=$e_admin_state&zp=$e_admin_zip&ct=$e_admin_country&em=$e_admin_email&ph=$e_admin_phone&fx=$e_admin_fax", $params);

        if (@$new_admin_id['error']) {
            return $new_admin_id;
        }

        $update_admin_id = $new_admin_id['new_contact_id'];
    }

    //Tech

    //Get Current Tech Info
    $current_tech = namesilo_transactionCall("getContactDetails", $apiServerUrl . "/api/contactList?version=1&type=xml&key=$apiKey&contact_id={$contactid['tech']}", $params);

    $c_tech_firstname = trim(urlencode($current_tech['firstname']));
    $c_tech_lastname = trim(urlencode($current_tech['lastname']));
    $c_tech_company = trim(urlencode($current_tech['company']));
    $c_tech_address = trim(urlencode($current_tech['address']));
    $c_tech_address2 = trim(urlencode($current_tech['address2']));
    $c_tech_city = trim(urlencode($current_tech['city']));
    $c_tech_state = trim(urlencode($current_tech['state']));
    $c_tech_zip = trim(urlencode($current_tech['postalcode']));
    $c_tech_country = trim(urlencode($current_tech['country']));
    $c_tech_email = trim(urlencode($current_tech['email']));
    $c_tech_phone = trim(urlencode($current_tech['phone']));
    $c_tech_fax = trim(urlencode($current_tech['fax']));

    $md5_c_tech = md5($c_tech_firstname . $c_tech_lastname . $c_tech_company . $c_tech_address . $c_tech_address2 . $c_tech_city . $c_tech_state . $c_tech_zip . $c_tech_country . $c_tech_email . $c_tech_phone . $c_tech_fax);

    //Get Entered Admin Info
    $e_tech_firstname = trim(urlencode($params["contactdetails"]["Tech"]["First Name"]));
    $e_tech_lastname = trim(urlencode($params["contactdetails"]["Tech"]["Last Name"]));
    $e_tech_company = trim(urlencode($params["contactdetails"]["Tech"]["Company"]));
    $e_tech_address = trim(urlencode($params["contactdetails"]["Tech"]["Address"]));
    $e_tech_address2 = trim(urlencode($params["contactdetails"]["Tech"]["Address 2"]));
    $e_tech_city = trim(urlencode($params["contactdetails"]["Tech"]["City"]));
    $e_tech_state = trim(urlencode($params["contactdetails"]["Tech"]["State"]));
    $e_tech_zip = trim(urlencode(@$params["contactdetails"]["Tech"]["Postcode"] ? $params["contactdetails"]["Tech"]["Postcode"] : $params["contactdetails"]["Tech"]["Postal Code"]));
    $e_tech_country = trim(urlencode($params["contactdetails"]["Tech"]["Country"]));
    $e_tech_email = trim(urlencode($params["contactdetails"]["Tech"]["Email"]));
    $e_tech_phone = trim(urlencode($params["contactdetails"]["Tech"]["Phone"]));
    $e_tech_fax = trim(urlencode($params["contactdetails"]["Tech"]["Fax"]));

    $md5_e_tech = md5($e_tech_firstname . $e_tech_lastname . $e_tech_company . $e_tech_address . $e_tech_address2 . $e_tech_city . $e_tech_state . $e_tech_zip . $e_tech_country . $e_tech_email . $e_tech_phone . $e_tech_fax);

    //Create new contact profile and associate it if necessary
    if ($md5_c_tech != $md5_e_tech) {

        $new_tech_id = namesilo_transactionCall("contactAdd", $apiServerUrl . "/api/contactAdd?version=1&type=xml&key=$apiKey&fn=$e_tech_firstname&ln=$e_tech_lastname&cp=$e_tech_company&ad=$e_tech_address&ad2=$e_tech_address2&cy=$e_tech_city&st=$e_tech_state&zp=$e_tech_zip&ct=$e_tech_country&em=$e_tech_email&ph=$e_tech_phone&fx=$e_tech_fax", $params);

        if (@$new_tech_id['error']) {
            return $new_tech_id;
        }

        $update_tech_id = $new_tech_id['new_contact_id'];
    }

    //echo "Registrant: $update_reg_id <br> Admin: $update_admin_id <br> Tech: $update_tech_id"; exit;

    # Now, update the domain to use the IDs
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/contactDomainAssociate?version=1&type=xml&key=$apiKey&domain=$sld.$tld&registrant=$update_reg_id&administrative=$update_admin_id&technical=$update_tech_id", $params);

    # Return Results
    return $values;
}

/*****************************************/
/* Send EPP Code to Domain Admin Contact */
/*****************************************/
function namesilo_GetEPPCode($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/retrieveAuthCode?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Register a new name server */
/*****************************************/
function namesilo_RegisterNameserver($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $nameserver = trim(urlencode(str_replace(".$sld.$tld", '', $params["nameserver"])));
    $ipaddress = trim(urlencode($params["ipaddress"]));
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/addRegisteredNameServer?version=1&type=xml&key=$apiKey&domain=$sld.$tld&new_host=$nameserver&ip1=$ipaddress", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Modify a name server */
/*****************************************/
function namesilo_ModifyNameserver($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $nameserver = trim(urlencode(str_replace(".$sld.$tld", '', $params["nameserver"])));
    $currentipaddress = trim(urlencode($params["currentipaddress"]));
    $newipaddress = trim(urlencode($params["newipaddress"]));
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/modifyRegisteredNameServer?version=1&type=xml&key=$apiKey&domain=$sld.$tld&current_host=$nameserver&new_host=$nameserver&ip1=$newipaddress", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Delete a name server */
/*****************************************/
function namesilo_DeleteNameserver($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    $nameserver = trim(urlencode(str_replace(".$sld.$tld", '', $params["nameserver"])));
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/deleteRegisteredNameServer?version=1&type=xml&key=$apiKey&domain=$sld.$tld&current_host=$nameserver", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* ID Protection */
/*****************************************/
function namesilo_IDProtectToggle($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Determine ID Protection Status and Run Appropriate Call
    $api_op = ($params['protectenable'] == 'on') ? 'addPrivacy' : 'removePrivacy';
    # Transaction Call
    $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/$api_op?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    # Return Results
    return $values;
}

/*****************************************/
/* Retrieve Domain's Email Forwards      */
/*****************************************/
function namesilo_GetEmailForwarding($params)
{

    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Transaction Call
    $email_forwards = namesilo_transactionCall("listEmailForwards", $apiServerUrl . "/api/listEmailForwards?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    # Register Results
    $valuesi = 0;
    foreach ($email_forwards as $value) {
        $valuesi++;
        $values[$valuesi]["prefix"] = ($value['email'] == 'Catch-all') ? '*' : $value["email"];
        $values[$valuesi]["forwardto"] = $value["forward"];
    }
    $values["error"] = $email_forwards["error"];
    # Return Results
    return $values;
}

/*****************************************/
/* Set Domain's Email Forwards           */
/*****************************************/
function namesilo_SaveEmailForwarding($params)
{

    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    # Register Variables
    $tld = urlencode($params["tld"]);
    $sld = urlencode($params["sld"]);
    # Get all current forwards
    $current_emails = array();
    $current_email_forwards = namesilo_transactionCall("listEmailForwards", $apiServerUrl . "/api/listEmailForwards?version=1&type=xml&key=$apiKey&domain=$sld.$tld", $params);
    foreach ($current_email_forwards as $value) {
        $current_emails[] = ($value['email'] == 'Catch-all') ? '*' : $value["email"];
    }
    # Get form submission values
    $submitted_emails = array();
    foreach (($params["prefix"] ?? []) as $key => $value) {
        $forwardarray[$key]["prefix"] = $params["prefix"][$key];
        $forwardarray[$key]["forwardto"] = $params["forwardto"][$key];
    }
    # Transaction Calls
    foreach ($forwardarray as $emails) {
        if (@$emails['prefix']) {
            $prefix = trim($emails['prefix']);
            $forward = trim($emails['forwardto']);
            $submitted_emails[] = $prefix;
            $values = namesilo_transactionCall("Standard", $apiServerUrl . "/api/configureEmailForward?version=1&type=xml&key=$apiKey&domain=$sld.$tld&email=$prefix&forward1=$forward", $params);
        }
    }
    # Delete any emails no longer present
    $deleted_emails = array_diff($current_emails, $submitted_emails);
    foreach ($deleted_emails as $deleted_email) {
        namesilo_transactionCall("Standard", $apiServerUrl . "/api/deleteEmailForward?version=1&type=xml&key=$apiKey&domain=$sld.$tld&email=$deleted_email", $params);
    }
    # Return Results
    return $values;
}

function namesilo_Sync($params)
{
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];

    //Fix whmcs 8.0.0.3 domain param bug
    if ($params['domain']) {
        $domainName = $params['domain'];
    } else {
        $domainName = $params['sld'] . '.' . $params['tld'];
    }


    try {
        /** @var SimpleXMLElement $result */
        $result = namesilo_transactionCall('domainSync', $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$domainName", $params);

        $code = (int)$result->code;

        if ($code === 200) {

            // Domain was transferred away
            if ('Outbound Transfer' === $result->inactive_type) {
                return [
                    'transferredAway' => true,
                ];
            }

            // Domain is not active, or does not belong to this user
            return [
                'active' => false,
                'cancelled' => true,
                'expirydate' => (new \DateTime)->format('Y-m-d'),
            ];
        }

        if ($code !== 300) {
            return ['error' => 'ERROR: ' . $domainName . ' - Code:' . $code . ' Detail: ' . (string)$result->detail];
        }

        $status = (string)$result->status;
        $active = in_array($status, ['Active', 'Grace', 'Redemption'], true);

        return [
            'active' => $active,
            'cancelled' => !$active,
            'transferredAway' => false,
            'expirydate' => (string)$result->expires,
        ];
    } catch (\Throwable $e) {
        return ['error' => 'ERROR: ' . $domainName . ' - ' . $e->getMessage()];
    }
}

function namesilo_TransferSync($params)
{
    //logActivity('sync start');
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];
    $domainName = $params['domain'];

    $transfer_failed_statuses = array(
        'Missing Authorization Code',
        'Transfer Rejected',
        'Registry Transfer Request Failed',
        'Registrar Rejected',
        'Incorrect Authorization Code',
        'Domain is Locked',
        'On Hold - Created in last 60 days',
        'On Hold - Transferred in last 60 days',
        'Registry Rejected',
        'Domain Transferred Elsewhere',
        'User Cancelled',
        'Domain has a pendingDelete status',
        'Domain has a pendingTransfer status'
    );

    try {
        /** @var SimpleXMLElement $result */
        $result = namesilo_transactionCall('domainSync', $apiServerUrl . "/api/checkTransferStatus?version=1&type=xml&key=$apiKey&domain=$domainName", $params);

        $code = (int)$result->code;

        if ($code !== 300) {
            return ['error' => 'ERROR: ' . $domainName . ' - Code:' . $code . ' Detail: ' . (string)$result->detail];
        }

        $status = (string)$result->status;
        if ($status === 'Transfer Completed') {

            /** @var SimpleXMLElement $result */
            $domainInfoResult = namesilo_transactionCall('domainSync', $apiServerUrl . "/api/getDomainInfo?version=1&type=xml&key=$apiKey&domain=$domainName", $params);

            $code = (int)$domainInfoResult->code;
            if ($code !== 300) {
                return ['error' => 'ERROR: ' . $domainName . ' - Code:' . $code . ' Domain Info Detail: ' . (string)$domainInfoResult->detail];
            }

            $status = (string)$domainInfoResult->status;
            if ($status === 'Active') {
                return array(
                    'completed' => true, // Return as true upon successful completion of the transfer
                    'expirydate' => (string)$result->expiration, // The expiry date of the domain
                );
            }
        } else if (in_array($status, $transfer_failed_statuses)) {
            return array(
                'failed' => true,
                'reason' => $status
            );
        }

        return array(
            'completed' => false,
            'failed' => false
        );
    } catch (\Throwable $e) {
        return ['error' => 'ERROR: ' . $domainName . ' - ' . $e->getMessage()];
    }
}

function namesilo_CheckAvailability($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];


    # Register Variables;
    //$tld = $params["tld"]; //tld is always empty for this function, search tlds are passed as an array in tldsToInclude
    $sld = $params["sld"];
    $tldsToInclude = $params["tldsToInclude"]; //if the tld is a match of one of the supported tlds it is sent in the array, if there is no match, the first supported TLD is used (.com on most cases)
    //$premiumEnabled = (bool)$params["premiumEnabled"];

    $searchDomains = [];
    $searchResults = new ResultsList();


    //Prepare search array
    foreach ($tldsToInclude as $iTld) {
        $searchDomains[] = array("tld" => $iTld, "sld" => $sld, "searchTerm" => $sld  . $iTld, "searchResult" => null);
    }

    $searchTerms = "";
    foreach ($searchDomains as $sDomain) {
        $searchTerms .= $sDomain["searchTerm"] . ",";
    }
    $searchTerms = substr($searchTerms, 0, -1);

    # Transaction Call
    $values = namesilo_transactionCall("domainAvailability", $apiServerUrl . "/api/checkRegisterAvailability?version=1&type=xml&key=$apiKey&domains=$searchTerms", $params);

    //If results are returned (implies there is no error), match the results to the search array and create a SearchResults instance
    if (isset($values["domains"])) {
        foreach ($searchDomains as &$sDomain) {
            foreach ($values["domains"] as $vDomain) {
                if ($sDomain["searchTerm"] === $vDomain["domain"]) {
                    $sResult = new SearchResult($sDomain["sld"], $sDomain["tld"]);

                    if ($vDomain["status"] === "available") {
                        $sResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);
                    } elseif ($vDomain["status"] === "unavailable") {
                        $sResult->setStatus(SearchResult::STATUS_REGISTERED);
                    } elseif ($vDomain["status"] === "invalid") {
                        $sResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                    }

                    if ($vDomain["premium"] == "1") {
                        $sResult->setPremiumDomain(true);

                        $sResult->setPremiumCostPricing(array("register" => $vDomain["price"], /*"renew" => "",*/ "CurrencyCode" => "USD"));
                        //Fix-me: the API doesn't return a renewal price for premium domains
                    }

                    $sDomain["searchResult"] = $sResult;
                }
            }
        }

        //Create a SearchResult instance for any non-matched search item
        foreach ($searchDomains as &$sDomain) {
            if (is_null($sDomain["searchResult"])) {
                $sResult = new SearchResult($sDomain["sld"], $sDomain["tld"]);

                $sResult->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);

                $sDomain["searchResult"] = $sResult;
            }
        }
        unset($sDomain);
    } else {
        //logActivity($values["error"]);
        throw new Exception($values["error"]);
        //return ['error' => 'ERROR: ' . $values["error"]]; //WHMCS refuses to accept this as valid
    }

    //Fill the results object from the search array
    foreach ($searchDomains as $sDomain) {
        $searchResults->append($sDomain["searchResult"]);
    }

    return $searchResults;
}

function namesilo_GetDomainSuggestions($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];

    # Register Variables
    //$tld = $params["tld"]; //tld is always empty for this function, search tlds are passed as an array in tldsToInclude
    $sld = $params["searchTerm"]; //sld is empty for this function
    $tldsToInclude = $params["tldsToInclude"];
    //$premiumEnabled = (bool)$params["premiumEnabled"];

    $searchDomains = [];
    $searchResults = new ResultsList();


    //Prepare search array

    foreach ($tldsToInclude as $iTld) {
        $searchDomains[] = array("tld" => "." . $iTld, "sld" => $sld, "searchTerm" => $sld  . "." . $iTld);
    }

    $searchTerms = "";
    foreach ($searchDomains as $sDomain) {
        $searchTerms .= $sDomain["searchTerm"] . ",";
    }
    $searchTerms = substr($searchTerms, 0, -1);

    # Transaction Call
    $values = namesilo_transactionCall("domainAvailability", $apiServerUrl . "/api/checkRegisterAvailability?version=1&type=xml&key=$apiKey&domains=$searchTerms", $params);

    //If results are returned (implies there is no error), match the results to the search array and create a SearchResults instance
    //Results are matched to avoid dealing with tld, sld separation when creating the SearchResult instance
    if (isset($values["domains"])) {
        foreach ($searchDomains as $sDomain) {
            foreach ($values["domains"] as $vDomain) {
                if ($sDomain["searchTerm"] === $vDomain["domain"]) {
                    if ($vDomain["status"] === "available") { //Only available domains are accepted in the suggestion list
                        $sResult = new SearchResult($sDomain["sld"], $sDomain["tld"]);

                        $sResult->setStatus(SearchResult::STATUS_NOT_REGISTERED);

                        if ($vDomain["premium"] == "1") {
                            $sResult->setPremiumDomain(true);

                            $sResult->setPremiumCostPricing(array("register" => $vDomain["price"], /*"renew" => "",*/ "CurrencyCode" => "USD"));
                            //Fix-me: the API doesn't return a renewal price for premium domains
                        }

                        $searchResults->append($sResult);
                    }
                }
            }
        }
        unset($sDomain);
    } else {
        //logActivity($values["error"]);
        throw new Exception($values["error"]);
        //return ['error' => 'ERROR: ' . $values["error"]]; //WHMCS refuses to accept this as valid
    }

    return $searchResults;
}

function namesilo_GetTldPricing($params)
{
    # Set Appropriate API Server
    $apiServerUrl = ($params['Test_Mode'] == 'on') ? TEST_API_SERVER : LIVE_API_SERVER;
    # Set Appropriate API Key
    $apiKey = ($params['Test_Mode'] == 'on') ? $params['Sandbox_API_Key'] : $params['Live_API_Key'];

    # Register Variables
    $results = new ResultsList();

    # Transaction Call
    $values = namesilo_transactionCall("domainPricing", $apiServerUrl . "/api/getPrices?version=1&type=xml&key=$apiKey", $params);

    //To-Do: add grace and redemtion fee days (some TLDs don't have redemption)
    //To-Do: add redemption fee price

    if (isset($values["prices"])) { //If prices are returned transform them to a result list
        foreach ($values["prices"] as $price) {
            $item = new ImportItem();
            $item->setExtension('.' . $price["tld"]);
            $item->setCurrency('USD');
            $item->setRegisterPrice((float)$price["registration"]);
            $item->setRenewPrice((float)$price["renew"]);
            $item->setTransferPrice((float)$price["transfer"]);

            $results->append($item);
        }

        return $results;
    } else {
        //logActivity($values["error"]);
        return ['error' => 'ERROR: ' . $values["error"]];
    }
}