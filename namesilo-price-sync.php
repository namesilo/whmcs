<?php
/*
Script for synchronizing TLD prices with namesilo, adds information directly to the database, needs to be called using CLI/cron

php namesilo-price-sync.php [-margin=0.00[%/p]] [-update=none/already-added/namesilo-only/all] [-make-default-registrar=false/true] [-round-to-next=0.00] [-template=.tld] [-exclude=.tld,...] [-include=.tld,...]

Arguments:
-margin=0.0[%/p]
Profit margin to add on each price, it can be a fixed amount or a percentage (by using p or % at the end of the number)
Examples: 5.10, 3p, 22%
Default value is: 0.00
*Cron treats percent signs in a special way, if used escape the percent sign or use p instead

-update=[all/already-added/namesilo-only/none]
TLDs that will be updated or added by the script
all - Adds all the TLDs from namesilo's price list
already-added - Updates all TLDs already registered on WHMCS (excluding those not supported)
namesilo-only - Updates only the TLDs that use namesilo as their registrar
none - Doesn't add any TLD, TLDs in the inclusion or exclusion lists are still considered
Example: all
Default value: none

-make-default=registrar=[true/false]
Changes the registrar on all updated TLDs to namesilo
Example: true
Default: false

-round-to-next=0.00
Rounds each price to the next decimal specified
Example: 0.20

-template=.tld
The TLD to take as a base for newly added TLDs, prices are not copied
Example: .com

-exclude=.tld,...
TLDs to exclude during the update, accepts a comma separated list
Example: .com,.net,.org

-include=.tld,...
TLDs to include during the update, accepts a comma separated list
Example: .com,.net,.org

Sample call:
php namesilo-price-sync.php -margin=15% -update=namesilo-only -make-default-registrar=true -round-to-next=0.50 -template=.com -exclude=.xyz -include=.buzz,.top
*/

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
/* Process arguments					 */
/*****************************************/
foreach ($argv as $ar) {
	if (preg_match('/^-margin=/i', $ar)) {
		$margin = explode('=', $ar);
		(count($margin) > 1) ? $margin = $margin[1] : $margin = null;
		
		if ($margin) {
			$marginType = 'fixed';
			
			if (preg_match('/(%|p)/i', $margin)) {
				$marginType = 'percentage';
				
				$margin = preg_replace('/(%|p)/i', '', $margin);
			}
		
		
			$margin = (float)$margin;
		}
		
		break;
	}
}
if (!isset($margin) || is_null($margin)) {
	$margin = 0.0;
	$marginType = 'fixed';
}



foreach ($argv as $ar) {
	if (preg_match('/^-update=/i', $ar)) {
		$updateTarget = explode('=', $ar);
		(count($updateTarget) > 1) ? $updateTarget = $updateTarget[1] : $updateTarget = null;
		
		if ($updateTarget) {
			if (preg_match('/^all/i', $updateTarget)) {
				$updateTarget = 'all';
			} else if (preg_match('/^already-added/i', $updateTarget)) {
				$updateTarget = 'alreadyAdded';
			} else if (preg_match('/^namesilo-only/i', $updateTarget)) {
				$updateTarget = 'namesiloOnly';
			} else {
				$updateTarget = 'none';
			}
		}
		
		break;
	}
}
if (!isset($updateTarget) || is_null($updateTarget)) {
	$updateTarget = 'none';
}

foreach ($argv as $ar) {
	if (preg_match('/^-make-default-registrar=/i', $ar)) {
		$makeDefaultRegistrar = explode('=', $ar);
		(count($makeDefaultRegistrar) > 1) ? $makeDefaultRegistrar = $makeDefaultRegistrar[1] : $makeDefaultRegistrar = null;
		
		if ($makeDefaultRegistrar) {
			if (preg_match('/^true/i', $makeDefaultRegistrar)) {
				$makeDefaultRegistrar = true;
			} else {
				$makeDefaultRegistrar = false;
			}
		}
		
		break;
	}
}
if (!isset($makeDefaultRegistrar) || is_null($makeDefaultRegistrar)) {
	$makeDefaultRegistrar = false;
}

foreach ($argv as $ar) {
	if (preg_match('/^-round-to-next=/i', $ar)) {
		$roundToNext = explode('=', $ar);
		(count($roundToNext) > 1) ? $roundToNext = $roundToNext[1] : $roundToNext = null;
		(is_numeric($roundToNext)) ? $roundToNext = (float)$roundToNext : $roundToNext = null;
		
		if ($roundToNext !== null) {
			$roundToNext = round($roundToNext , 2);
			
			if ($roundToNext >= 1.00 || $roundToNext < 0.00) {
				$roundToNext = null;
			}
		}
		
		break;
	}
}
if (!isset($roundToNext)) {
	$roundToNext = null;
}

foreach ($argv as $ar) {
	if (preg_match('/^-template=/i', $ar)) {
		$templateTld = explode('=', $ar);
		(count($templateTld) > 1) ? $templateTld = $templateTld[1] : $templateTld = null;
		
		if ($templateTld !== null) {
			$templateTld = preg_replace('/(\'|"|\s)/', '', $templateTld);
			$templateTld = preg_replace('/^\./', '', $templateTld);
			$templateTld = strtolower($templateTld);
			$templateTld = '.' . $templateTld;
		}
		
		break;
	}
}
if (!isset($templateTld)) {
	$templateTld = null;
}

foreach ($argv as $ar) {
	if (preg_match('/^-exclude=/i', $ar)) {
		$excludedTld = explode('=', $ar);
		(count($excludedTld) > 1) ? $excludedTld = $excludedTld[1] : $excludedTld = null;
		
		
		if ($excludedTld !== null) {
			$excludedTld = preg_replace('/(\'|"|\s)/', '', $excludedTld);
			$excludedTld = strtolower($excludedTld);
			$excludedTld = explode(',', $excludedTld);
			$excludedTld = preg_replace('/^\./', '', $excludedTld);
			$excludedTld = preg_replace('/^/', '.', $excludedTld);
		}
		
		break;
	}
}
if (!isset($excludedTld) || is_null($excludedTld)) {
	$excludedTld = [];
}

foreach ($argv as $ar) {
	if (preg_match('/^-include=/i', $ar)) {
		$includedTld = explode('=', $ar);
		(count($includedTld) > 1) ? $includedTld = $includedTld[1] : $includedTld = null;
		
		
		if ($includedTld !== null) {
			$includedTld = preg_replace('/(\'|"|\s)/', '', $includedTld);
			$includedTld = strtolower($includedTld);
			$includedTld = explode(',', $includedTld);
			$includedTld = preg_replace('/^\./', '', $includedTld);
			$includedTld = preg_replace('/^/', '.', $includedTld);
		}
		
		break;
	}
}
if (!isset($includedTld) || is_null($includedTld)) {
	$includedTld = [];
}

/*****************************************/
/* Define Clases                         */
/*****************************************/
Class NamesiloPrices {
	public $priceList;
	
	private $nsListCall;
	
	function __construct($apiCall) {
		 $this->nsListCall = $apiCall;
		 $this->priceList = [];
		 
		 //$this->updateList();
	}
	
	function getPrice($tld, $operation) {
		$price = null;
		
		foreach ($this->priceList as $entry) {
			if ($tld == $entry['tld']) {
				foreach ($entry['prices'] as $entryOperation => $entryPrice) {
					if ($entryOperation == $operation) {
						$price = $entryPrice;
						
						break;
					}
				}
				
				break;
			}
		}
		
		return $price;
	}
	
	function updateList() {
		$apiPrices = $this->_transactionProcessor();
		
		if (!isset($apiPrices['prices'])) {
			throw new Exception('API Error: ' . $apiPrices['error']);
		}
		
		foreach ($apiPrices['prices'] as $apiPrice) {
			$listTld = $apiPrice['tld'];
			
			unset($apiPrice['tld']);
			
			$this->priceList[] = array('tld' => '.' . $listTld, 'prices' => $apiPrice);
		}
	}
	
	function _transactionProcessor() {
		$call = $this->nsListCall;
		
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
			throw new Exception('CURL Error: ' . curl_errno ( $ch ) . ' - ' . curl_error ( $ch ));
			//exit ( 'CURL Error: ' . curl_errno ( $ch ) . ' - ' . curl_error ( $ch ) );
		}
		# Log error(s)
		if ($curl_err) { $cronreport .= "Error connecting to API: $curl_err"; }	
		curl_close( $ch );
		# Process XML result
		$xml = new SimpleXMLElement($content);
		
		$code = (int) $xml->reply->code;
		$detail = (string) $xml->reply->detail;
		
		$result = [];
		
		if ($code == "300") {
			$result["prices"] = [];
			foreach ($xml->reply->children() as $tld) {
				if ($tld->count() === 3) {
					$result["prices"][] = array("tld" => (string)$tld->getName(), "register" => (string)$tld->registration, "renew" => (string)$tld->renew, "transfer" => (string)$tld->transfer);
				}
			}
		}
		
		$result['error'] = $detail;
		
		# Return result
		return $result;
	}
}

Class WhmcsDbHandler {
	//Handles connection betweed the database and local data
	//Returns false on failed operations, exceptions are sent to log callback and hidden
	
	public $entryList;
	//Main entry list, contains local data from the database
	
	private $dbTableName;
	//Table name used
	
	private $logFn;
	//Callback used for loggin errors
	//logFn($exception)
	//$exception can be an Exception or just a string
	
	public $primaryKey;
	//Element/field to be used as primary identifier
	//[element, dbBinding]
	//Default: ['id', 'id']
	
	public $requiredElements;
	//Elements required to add an entry
	//[element1, element2]
	public $uniqueElements;
	//Values that are verified to be unique in the list while adding a new entry, can be bundled using commas
	//['element1', 'element2,element3']
	
	public $databaseTemplate;
	//Default database values to use while adding new elements
	//[[dbColumn, defaultValue]]
	public $elements;
	//Values/fields to be used in entryList and their database column binding
	//[[element, dbBinding]]
	
	function __construct($tableName, $logFn=null, $elements=null, $dbTemplate=null, $primaryKey=null, $requiredElements=null, $uniqueElements=null) {
		$this->entryList = [];
		
		$this->dbTableName = $tableName;
		
		$this->logFn = $logFn;
		
		is_null($elements) ? $this->elements = [] : $this->elements = $elements;
		is_null($dbTemplate) ? $this->dbTemplate = [] : $this->dbTemplate = $dbTemplate;
		is_null($requiredElements) ? $this->requiredElements = [] : $this->requiredElements = $requiredElements;
		is_null($uniqueElements) ? $this->uniqueElements = [] : $this->uniqueElements = $uniqueElements;
		
		is_null($primaryKey) ? $this->primaryKey = ['id', 'id'] : $this->primaryKey = $primaryKey;
	}
	
	function updateList() {
		//Updates the local list with database data
		
		//Clear entry values
		$this->entryList = [];
		
		//Create selector for database data
		$dbSelector = [];
		
		$dbSelector[] = $this->primaryKey[1];
		
		foreach ($this->elements as $element) {
			$dbSelector[] = $element[1];
		}
		
		$dbData = $this->_getTable()->select($dbSelector)->get()->toArray();
		
		//Convert and add each database row to entry list
		foreach ($dbData as $dbEntry) {
			$this->entryList[] = $this->_dbToEntry((array) $dbEntry);
		}
	}
	
	function addEntry($entryData) {
	//$entryData: assoc array using $elements and $items ['element' => 'value']
	//Add an entry to the database and list
	//Returns array with new entry data
		
		//Required value handling
		if (!$this->_hasRequiredFields($entryData)) {
			$this->_log(serialize($entryData) . ': Required values not found');
			return false;
		}
		
		//Duplicate handling
		if ($this->_isDuplicate($entryData)) {
			$this->_log(serialize($entryData) . ': Duplicate entry exists');
			return false;
		}
		
		
		//Insert to database
		try {
			$dbData= $this->_pushToDatabase($entryData);
			$dbData = $dbData['dbData'];
		} catch (Exception $ex) {
			$this->_log($ex);
			return false;
		}
		
		//Load data from database result
		$newEntry = $this->_dbToEntry($dbData);
		
		//Add to list
		$this->entryList[] = $newEntry;
		
		return $newEntry;
	}
	
	function updateEntry($entryData, $selector) {
	//$entryData: assoc array using $elements and $items ['element' => 'value'], data is inserted to database
	//$selector: assoc array using elements and items ['element' => 'value'], used to locate an entry to be updated
	//  the selector must match only one entry, it can accept a non array value, this value will be converted to an array using the primary key as the key
	//Updates an entry in the database and list
		
		$updatedEntry = [];
		
		
		//Find entry index using selector
		try {
			$entryIndex = $this->_find($selector);
		} catch (Exception $ex) {
			$this->_log($ex);
			return false;
		}
		
		if (is_null($entryIndex)) {
			$this->_log(serialize($selector) . ': Entry not found');
			return false;
		}
		
		$originalEntry = $this->entryList[$entryIndex];
		
		//Add original data to new list entry
		foreach ($originalEntry as $oKey => $oValue) {
			$updatedEntry[$oKey] = $oValue;
		}
		
		//Update entry data with new data
		foreach ($entryData as $dKey => $dValue) {
			if (isset($updatedEntry[$dKey])) {
				$updatedEntry[$dKey] = $dValue;
			}
		}
		
		//Check duplicates
		$this->entryList[$entryIndex] = [];
		
		$duplicate = $this->_isDuplicate($updatedEntry);
		
		$this->entryList[$entryIndex] = $originalEntry;
		
		if ($duplicate) {
			$this->_log(serialize($selector) . ': Duplicate entry exists');
			return false;
		}
		
		if ($originalEntry == $updatedEntry) {
			$this->_log(serialize($selector) . ': No data changes found');
			return false;
		}
		
		//Send data to database
		try {
			if (!$this->_pushToDatabase($entryData, $selector)['result']) {
				$this->_log(serialize($selector) . ': Database update failed');
				return false;
			}
		} catch (Exception $ex) {
			$this->_log($ex);
			return false;
		}
		
		//Insert into local list
		$this->entryList[$entryIndex] = $updatedEntry;
	}
	
	function deleteEntry($selector) {
		//$selector: assoc array using elements and items ['element' => 'value'], used to locate an entry to be updated
		//  the selector must match only one entry, it can accept a non array value, this value will be converted to an array using the primary key as the key
		//Deletes an entry in the database and list
		
		//Find entry
		try {
			$entryIndex = $this->_find($selector);
		} catch (Exception $ex) {
			$this->_log($ex);
			return false;
		}
		
		if (is_null($entryIndex)) {
			$this->_log(serialize($selector) . ': Entry not found');
			return false;
		}
		
		//Delete data on database
		try {
			if (!$this->_databaseDelete($selector)) {
				$this->_log(serialize($selector) . ': Database delete failed');
				return false;
			}
		} catch (Exception $ex) {
			$this->_log($ex);
			return false;
		}
		
		//Remove data on local list
		array_splice($this->entryList, $entryIndex, 1);
	}
	
	function getEntry($selector) {
		//$selector: assoc array using elements and items ['element' => 'value'], used to locate an entry to be updated
		//  the selector for this method can match multiple items, it can accept a non array value, this value will be converted to an array using the primary key as the key
		//This method returns an array with the entries found
		
		$resultEntries = [];
		
		//Find entries
		$indexArr = $this->_findAll($selector);
		
		//Copy entries to result
		foreach ($indexArr as $idx) {
			$newResult = [];
			
			foreach ($this->entryList[$idx] as $element => $value) {
				$newResult[$element] = $value;
			}
			
			$resultEntries[] = $newResult;
		}
		
		return $resultEntries;
	}
	
	function _isDuplicate($entryData) {
		//$entryData: assoc array using elements and items ['element' => 'value']
		//Checks if an entry already exists using the instance's unique fields
		
		//Prepare unique element check array from unique elements declared and entry data
		foreach ($this->uniqueElements as $uVal) {
			$uValKeys = explode(',', $uVal);
			$uValArr = [];
			
			foreach ($uValKeys as $uKey) {
				if (isset($entryData[$uKey])) {
					$uValArr[$uKey] = $entryData[$uKey];
				} else {
					$uValArr[$uKey] = null;
				}
			}
			
			//Check entry list  using check array
			$duplicate = false;
			
			foreach ($this->entryList as $entry) {
				if (count($entry) == 0) {
					continue;
				}
				
				foreach ($uValArr as $uKey => $uVal) {
					$duplicate = $entry[$uKey] == $uVal;
					
					if (!$duplicate) {
						break;
					}
				}
				
				if ($duplicate) {
					break;
				}
			}
			
			if ($duplicate) {
				return true;
			}
		}
		
		return false;
	}
	
	function _hasRequiredFields($entryData) {
		//$entryData: assoc array using elements and items ['element' => 'value']
		//Checks if the data has the elements defined as required by the instance
		
		//Check required elements in entry data
		foreach ($this->requiredElements as $rVal) {
			if (!isset($entryData[$rVal])) {
				return false;
			}
		}
		
		return true;
	}
	
	function _createDbSelector($selector) {
		//$selector: assoc array using elements and items ['element' => 'value']
		//Converts a selector to be used with the database
		//Returns converted array
		
		//Convert selector elements and items using their database bindings
		$newDbSelector = $this->_entryToDb($selector, false);
		
		//Replace primary key
		if (isset($selector[$this->primaryKey[0]])) {
			$newDbSelector[$this->primaryKey[1]] = $selector[$this->primaryKey[0]];
		}
		
		return $newDbSelector;
	}
	
	function _find($selector) {
		//$selector: assoc array using elements and items ['element' => 'value'], used to locate an entry
		//  the selector must match only one entry, it can accept a non array value, this value will be converted to an array using the primary key as the key
		//Finds an entry using the selector
		//The method returns the index of the element found (int) or null
		//Throws an exception if more than one item is found
		
		//Find entries on list using selector
		$indexArr = $this->_findAll($selector);
		
		//If there are more than one results throw an exception
		if (count($indexArr) > 1) {
			throw new Exception('Non-unique selector');
		}
		
		//Return the index of the element found
		if (isset($indexArr[0])) {
			return $indexArr[0];
		}
		
		return null;
	}
	
	function _findAll($selector) {
		//$selector: assoc array using elements and items ['element' => 'value'], used to locate an entry
		//  the selector for this method can match multiple items, it can accept a non array value, this value will be converted to an array using the primary key as the key
		//Finds all entries matching the selector
		//The method returns an array with the indexes of the items found
		
		//Convert selector to array if required
		if (!is_array($selector)) {
			$selectorValue = $selector;
			$selector = [];
			$selector[$this->primaryKey] = $selectorValue;
		}
		
		$entryIndexes = [];
		
		//Parse entry list and check each item using the selector
		for ($i = 0; $i < count($this->entryList); $i++) {
			$selected = true;
			foreach ($selector as $sKey => $sValue) {
				if (!(isset($this->entryList[$i][$sKey]) && $this->entryList[$i][$sKey] == $sValue)) {
					$selected = false;
					break;
				}
			}
			
			if ($selected) {
				$entryIndexes[] = $i;
			}
		}
		
		return $entryIndexes;
	}
	
	function _pushToDatabase($data, $selector=null) {
		//$entryData: assoc array using elements and items ['element' => 'value'], elements are converted to database bindings, this data is sent to the database
		//$selector assoc array using elements and items ['element' => 'value'], elements are converted to database bindings, this data is used to select the row that will be updated
		//  if null, the data in entryData is added to a new row
		//Adds data to database
		//Returns the data sent to the database
		
		//Check if data will be inserted or updated on database
		is_null($selector) ? $pushType = 'insert' : $pushType = 'update';
		
		//Replace entries with database bindings on selector and data
		if ($pushType == 'insert') {
			$dbEntry = $this->_entryToDb($data, true);
			$querySelector = [];
		} else {
			$dbEntry = $this->_entryToDb($data, false);
			$querySelector = $this->_createDbSelector($selector);
		}
		
		$queryResult = [];
		
		//Send data to database
		if ($pushType == 'insert') {
			$id = $this->_getTable()->insertGetId($dbEntry, $this->primaryKey[1]);
			
			//Add id to data set
			$dbEntry[$this->primaryKey[1]] = $id;
			
			$queryResult['result'] = true;
		} else {
			$queryResult['result'] = (bool) $this->_getTable()->where($querySelector, '=')->take(1)->update($dbEntry);
		}
		
		$queryResult['dbData'] = $dbEntry;
		
		return $queryResult;
	}
	
	function _databaseDelete($selector) {
		//$selector assoc array using elements and items ['element' => 'value'], elements are converted to database bindings, this data is used to select the row that will be deleted
		//Deletes a database row using the selector
		
		
		$selector = $this->_createDbSelector($selector);
		
		return (bool) $this->_getTable()->where($selector, '=')->take(1)->delete();
	}
	
	function _getTable() {
		//Returns a table instance
		
		return Capsule::table($this->dbTableName);
	}
	
	function _entryToDb($entry, $useTemplate) {
		//$entryData: assoc array using elements and items ['element' => 'value'], elements are converted to database bindings
		//$useTemplate: use the data in the instance's template
		//Replaces elements in entries with their database bindings
		//Returns an array with database ready data
		
		$newDbEntry = [];
		
		//Load template data for db
		if ($useTemplate) {
			foreach ($this->databaseTemplate as $tField) {
				$newDbEntry[$tField[0]] = $tField[1];
			}
		}
		
		//Replace elements
		foreach ($this->elements as $element) {
			if (isset($entry[$element[0]])) {
				$newDbEntry[$element[1]] = $entry[$element[0]];
			}
		}
		
		return $newDbEntry;
	}
	
	function _dbToEntry($dbData) {
		//$dbData: assoc array with database ready data ['dbBinding' => 'value']
		//Replaces database bindings with elements and creates entries
		//Returns an entry made from the database data, elements not found get a null value
		
		$newEntry = [];
		
		//Add primary key to new entry
		if (isset($dbData[$this->primaryKey[1]])) {
			$newEntry[$this->primaryKey[0]] = $dbData[$this->primaryKey[1]];
		} else {
			$newEntry[$this->primaryKey[0]] = null;
		}
		
		//Add database data to new entry
		foreach ($this->elements as $element) {
			if (isset($dbData[$element[1]])) {
				$newEntry[$element[0]] = $dbData[$element[1]];
			} else {
				$newEntry[$element[0]] = null;
			}
		}
		
		return $newEntry;
	}
	
	function _log($excp) {
		//$excp: Exception or string
		//Logs errors using callback function (if provided)
		
		if (is_callable($this->logFn)) {
			call_user_func($this->logFn, $excp);
		}
	}
}

Class WhmcsPriceDbHandler extends WhmcsDbHandler {
	//Adds item support for handling row values in WHMCS database structure
	
	public $items;
	//Operations (eg: registrations or renewals), their database value and their element/binding (column)
	//Uses an optional database template, added to new items, the template has database columns and database values, this item can be omited
	//[[item, dbValue, dbBinding, [dbTemplateColumn => dbTemplateData]]]
	
	function __construct($tableName, $logFn=null, $elements=null, $items=null, $dbTemplate=null, $primaryKey=null, $requiredElements=null, $uniqueElements=null) {
		parent::__construct($tableName, $logFn, $elements, $dbTemplate, $primaryKey, $requiredElements, $uniqueElements);
		
		is_null($items) ? $this->items = [] : $this->items = $items;
	}
	
	function updateList() {
	//Updates entry lists, removes any entries not using the item list values, for items active in the element list
		
		parent::updateList();
		
		$activeItems = [];
		//List of item values and their elements
		//[[element, [value1, value2, ...]], ...]
		
		//Get active items
		foreach ($this->items as $item) {
			foreach ($this->elements as $element) {
				//If the item has an entry in element list
				if ($item[2] == $element[1]) {
					//Check activeItem list, if the element is already there, add the item to it, else create new element
					$isNewActiveItem = true;
					//'&' Allow $activeItems modifications
					foreach ($activeItems as &$aItem) {
						if ($element[0] == $aItem[0]) {
							$aItem[1][] = $item[0];
							$isNewActiveItem = false;
							
							break;
						}
					}
					
					if ($isNewActiveItem) {
						$activeItems[] = [$element[0], [$item[0]]];
					}

					break;
				}
			}
		}
		
		//Check item values on entry list, if an entry doesn't have elements with the values defined for the items, remove it
		//This removes unrelated entries from the list, which is used for other prices in WHMCS
		for ($i = count($this->entryList) - 1; $i >= 0; $i--) {
			foreach ($activeItems as $aItem) {
				if (!(isset($this->entryList[$i][$aItem[0]]) && in_array($this->entryList[$i][$aItem[0]], $aItem[1]))) {
					array_splice($this->entryList, $i, 1);
					
					break;
				}
			}
		}
	}
	
	function _entryToDb($entry, $useTemplate) {
		//$entryData: assoc array using elements and items ['element' => 'value'], elements are converted to database bindings
		//$useTemplate: use the data in the instance's template
		//Replaces elements in entries with their database bindings
		//Returns an array with database ready data
		
		$newDbEntry = parent::_entryToDb($entry, $useTemplate);
		
		//Replace item values
		foreach ($this->items as $item) {
			if (isset($newDbEntry[$item[2]])) {
				if ($newDbEntry[$item[2]] == $item[0]) {
					$newDbEntry[$item[2]] = $item[1];
					
					//Add item database template
					if ($useTemplate) {
						if (isset($item[3])) {
							foreach ($item[3] as $tKey => $tValue) {
								if (!isset($newDbEntry[$tKey])) {
									$newDbEntry[$tKey] = $tValue;
								}
							}
						}
					}
				}
			}
		}
		
		return $newDbEntry;
	}
	
	function _dbToEntry($dbData) {
		//$dbData: assoc array with database ready data ['dbBinding' => 'value']
		//Replaces database bindings with elements and creates entries
		//Returns an entry made from the database data, elements not found get a null value
		
		//Replace database bindings with items
		foreach ($this->items as $item) {
			if (isset($dbData[$item[2]])) {
				if ($dbData[$item[2]] == $item[1]) {
					$dbData[$item[2]] = $item[0];
				}
			}
		}
		
		$newEntry = parent::_dbToEntry($dbData);
		
		return $newEntry;
	}
}

Class ScriptLogger {
	//Logger class, adds all data to $logStore, can be a tring or an array
	//$msgAppend gets appended to all log entries

	public $logStore;
	public $msgAppend;
	
	function __construct($logStore=null) {
		is_null($logStore) ?  $this->logStore = [] : $this->logStore = $logStore;
		
		$this->msgAppend = '';
	}
	
	function log($msg) {
		if ($msg instanceof Exception) {
			$this->_append($msg->getMessage());
		} else {
			$this->_append($msg);
		}
	}
	
	function _append($msg) {
		if (is_array($this->logStore)) {
			$this->logStore[] = $msg . $this->msgAppend;
		} else {
			$this->logStore .= $msg . $this->msgAppend;
		}
	}
}

/*****************************************/
/* Helper functions						 */
/*****************************************/
function roundToNextDecimal($number, $decimal) {
	//Round number to next decimal provided ($decimal must be a value between 0 and 1)
	//$number - The number being rounded, $decimal - round target

	$next = floor($number) + $decimal;
	
	if ($next >= $number) {
		return $next;
	} else {
		return $next + 1;
	}
}

/*****************************************/
/* Script data							 */
/*****************************************/
$apiCall = "/api/getPrices?version=1&type=xml&key=#apiKey#";

//WHMCS table names
$currencyTable = 'tblcurrencies';

$tldTable = 'tbldomainpricing';
$priceTable = 'tblpricing';

//Tld fields not considered for template
$tldTemplateExclusions = ['id', 'extension', 'autoreg', 'order', 'created_at', 'updated_at'];

//Operations/prices to synchronize
$priceOperationList = ['register', 'renew', 'transfer'];

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

$ns_sync_next_due_date = @$params['Sync_Next_Due_Date'];

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

# Query WHMCS for USD's currency ID
$currencyId = Capsule::table($currencyTable)->where('code', 'USD')->value('id');
if (is_null($currencyId)) {
	exit ('ERROR: Unable to find USD currency ID in database<br>' . "\n");
}

# Create log and logger instance
$cronreport = '';
$logger = new ScriptLogger();
//Set logStore to $cronreport to append all messages to the same report (sets variable by ref)
$logger->logStore = &$cronreport;
$logger->msgAppend = "<br>\n";

# Setup database
$whmcsTldList = new WhmcsDbHandler($tldTable, [$logger, 'log']);
$whmcsPriceList = new WhmcsPriceDbHandler($priceTable, [$logger, 'log']);

$whmcsTldList->elements = [
	['tld', 'extension'],
	['registrar', 'autoreg'],
	['listOrder', 'order'],
	['creationDate', 'created_at'],
	['updateDate', 'updated_at']
];

$whmcsTldList->uniqueElements = [
	'tld'
];

$whmcsTldList->requiredElements = [
	'tld'
];

$whmcsPriceList->elements = [
	['operation', 'type'],
	['currency', 'currency'],
	['domainId', 'relid'],
	['price', 'msetupfee']
];

$whmcsPriceList->uniqueElements = [
	'operation,currency,domainId'
];

$whmcsPriceList->requiredElements = [
	'operation', 'currency', 'domainId'
];

//Items match operation list
$whmcsPriceList->items = [
	['register', 'domainregister', 'type',
		['qsetupfee' => '-1.00', 'ssetupfee' => '-1.00', 'asetupfee' => '-1.00', 'bsetupfee' => '-1.00', 'monthly' => '-1.00', 'quarterly' => '-1.00', 'semiannually' => '-1.00', 'annually' => '-1.00', 'biennially' => '-1.00']],
	['renew', 'domainrenew', 'type'],
	['transfer', 'domaintransfer', 'type']
];

#Setup default tld template
if (!is_null($templateTld)) {
	//Get data from database
	$templateData = Capsule::table($tldTable)->where('extension', $templateTld)->get()->toArray();
	
	if (isset($templateData[0])) {
		$templateData = (array) $templateData[0];
		$templateArray = [];
		
		//Skip excluded items
		foreach ($templateData as $temKey => $temValue) {
			$excluded = false;
			
			foreach ($tldTemplateExclusions as $exc) {
				if ($temKey == $exc) {
					$excluded = true;
					
					break;
				}
			}
			
			if (!$excluded) {
				$templateArray[] = [$temKey, $temValue];
			}
		}
		
		$whmcsTldList->databaseTemplate = $templateArray;
	} else {
		$cronreport .= 'Template data not found<br>' . "\n";
	}
}

#Setup default registrar
if ($makeDefaultRegistrar) {
	$defaultRegistrar = 'namesilo';
} else {
	if (!is_null($templateTld)) {
		try {
			$defaultRegistrar = Capsule::table($tldTable)->where('extension', $templateTld)->value('autoreg');
		} catch(Exception $ex) {
			$defaultRegistrar = null;
			
			$cronreport .= 'Template default registrar error<br>' . "\n";
		}
		
	} else {
		$defaultRegistrar = null;
	}
}

# Get last list order number
try {
	$dbTldListOrder = (int) Capsule::table('tbldomainpricing')->max('order');
	$dbTldListOrder++;
} catch (Exception $ex) {
	$dbTldListOrder = 0;
	
	$cronreport .= 'List order data error<br>' . "\n";
}


# Set current time for create and update dates
//YYYY-MM-DD HH:MM:SS (utc)
$currentTime = date('Y-m-d H:i:s', time());

# Setup namesilo price list
$nsPriceList = new NamesiloPrices($apiServerUrl . str_replace('#apiKey#', $apiKey, $apiCall));

# Update data
try {
	$whmcsTldList->updateList();
	$whmcsPriceList->updateList();
	$nsPriceList->updateList();
} catch (Exception $ex) {
	exit($ex->getMessage());
}


# Prepare TLD work list
//Default is none
$tldWorkList = [];

if ($updateTarget == 'all') {
	foreach ($nsPriceList->priceList as $price) {
		$tldWorkList[] = $price['tld'];
	}
} elseif ($updateTarget == 'alreadyAdded') {
	foreach ($whmcsTldList->entryList as $entry) {
		$tldWorkList[] = $entry['tld'];
	}
} elseif ($updateTarget == 'namesiloOnly') {
	foreach ($whmcsTldList->entryList as $entry) {
		if ($entry['registrar'] == 'namesilo') {
			$tldWorkList[] = $entry['tld'];
		}
	}
}

//Add param include TLD list
foreach ($includedTld as $iTld) {
	if (!in_array($iTld, $tldWorkList)) {
		$tldWorkList[] = $iTld;
	}
}

//Remove param exclude TLD list
for ($i = count($tldWorkList) - 1; $i >= 0; $i--) {
	foreach($excludedTld as $eTld) {
		if ($tldWorkList[$i] == $eTld) {
			array_splice($tldWorkList, $i, 1);
			
			break;
		}
	}
}

/*****************************************/
/* Implement Updates					 */
/*****************************************/

# Start cron report
$cronreport .= "NameSilo Price Sync Report<br>
---------------------------------------------------<br>
";

# Validate TLD work list
for ($i = count($tldWorkList) - 1; $i >= 0; $i--) {
	//Check if all the doamins in $tldWorklist have prices on namesilo
	
	$supportedTld = false;
	
	foreach ($nsPriceList->priceList as $price) {
		if ($tldWorkList[$i] == $price['tld']) {
			$supportedTld = true;
			
			break;
		}
	}
	
	if (!$supportedTld) {
		$cronreport .= 'TLD not supported: ' . $tldWorkList[$i] . '<br>' . "\n";
		
		array_splice($tldWorkList, $i, 1);
	}
}

# Add missing TLDs
foreach ($tldWorkList as $wTld) {
	if (count($whmcsTldList->getEntry(['tld' => $wTld])) == 0) {
		$addResult = $whmcsTldList->addEntry([
			'tld' => $wTld,
			'registrar' => $defaultRegistrar,
			'listOrder' => $dbTldListOrder,
			'creationDate' => $currentTime,
			'updateDate' => $currentTime
		]);
		
		if ($addResult !== false) {
			$dbTldListOrder++;
		}
	}
}

# Change default registrar
if ($makeDefaultRegistrar) {
	foreach ($tldWorkList as $wTld) {
		$tldEntry = $whmcsTldList->getEntry(['tld' => $wTld])[0];
		
		if ($tldEntry['registrar'] != $defaultRegistrar) {
			 $whmcsTldList->updateEntry(['registrar' => $defaultRegistrar, 'updateDate' => $currentTime], ['tld' => $wTld]);
		}
	}
}

# Update prices
foreach ($tldWorkList as $wTld) {
	//Get TLD ID
	$tldEntry = $whmcsTldList->getEntry(['tld' => $wTld])[0];
	
	$tldId = $tldEntry['id'];
	
	//Get all prices for TLD id
	$oldPrices = $whmcsPriceList->getEntry(['domainId' => $tldId, 'currency' => $currencyId]);
	
	//Get/Calculate new prices
	$newPrices = [];
	foreach ($priceOperationList as $pOperation) {
		//Get price from namesilo using the TLD from the worklist and the operation from the operation list
		$newPrice = $nsPriceList->getPrice($wTld, $pOperation);
		
		//Skip operation if namesilo doesn't have a price
		if (is_null($newPrice)) {
			continue;
		}
		
		//Add margin to price
		if ($marginType == 'fixed') {
			$newPrice += $margin;
		} elseif ($marginType == 'percentage') {
			$newPrice += $newPrice * ($margin/100);
		}
		
		//Round to next decimal
		if (!is_null($roundToNext)) {
			$newPrice = roundToNextDecimal($newPrice, $roundToNext);
		}
		
		//Round price to 2 decimal places
		$newPrice = round($newPrice, 2);
		
		$newPrices[$pOperation] = $newPrice;
	}
	
	//Add new prices to database
	foreach ($newPrices as $nPriceKey => $nPriceValue) {
		$operationFound = false;
		
		//Find current operation in old prices
		foreach ($oldPrices as $oldPrice) {
			if ($oldPrice['operation'] == $nPriceKey) {
				$operationFound = true;
				
				//If new price is different from old price update database
				if ($oldPrice['price'] != $nPriceValue) {
					$whmcsPriceList->updateEntry(['price' => $nPriceValue], ['domainId' => $tldId, 'currency' => $currencyId, 'operation' => $nPriceKey]);
				}
				
				break;
			}
		}
		
		//If price was not in database add it
		if (!$operationFound) {
			$whmcsPriceList->addEntry(['operation' => $nPriceKey, 'currency' => $currencyId, 'domainId' => $tldId, 'price' => $nPriceValue]);
		}
	}
}

/*****************************************/
/* Echo to the screen 					 */
/*****************************************/
echo $cronreport;

/*****************************************/
/* Log System Activity					 */
/*****************************************/
logactivity('NameSilo Domain Sync Run');

/*****************************************/
/* Send Cron Report						 */
/*****************************************/
sendadminnotification('system', 'NameSilo Domain Syncronization Report', $cronreport);
