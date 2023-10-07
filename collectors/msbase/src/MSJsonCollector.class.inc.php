<?php

/**
 * Base class for MS JSON collectors
 *
 */
abstract class MSJsonCollector extends JsonCollector
{
	// Defaults to handle the authentication query
	const DEFAULT_MICROSOFT_LOGIN_URL = 'https://login.microsoftonline.com/';
	const DEFAULT_MICROSOFT_AUTH_MODE = '/oauth2/token';

	// Name of URI parameters that can be used within requests
	const URI_PARAM_GROUP = 'Group';
	const URI_PARAM_LOADBALANCER = 'LoadBalancer';
	const URI_PARAM_NETWORKINTERFACE = 'NetworkInterface';
	const URI_PARAM_RESOURCEGROUP = 'ResourceGroup';
	const URI_PARAM_MARIADB_SERVER = 'MariaDBServer';
	const URI_PARAM_MSSQL_SERVER = 'MSSQLServer';
	const URI_PARAM_MySQL_SERVER = 'MySQLServer';
	const URI_PARAM_POSTGRE_SERVER = 'PostgreServer';
	const URI_PARAM_SUBSCRIPTION = 'Subscription';
	const URI_PARAM_VNET = 'VNet';

	// Parameters of the file where the token is stored
	const BEARER_TOKEN_FILE_NAME = 'BearerToken.csv';
	const BEARER_TOKEN_NAME = 'TokenName';
	const BEARER_TOKEN_REQUEST_TIME = 'TokenRequestTime';
	const BEARER_TOKEN_EXPIRATION_DELAY_NAME = 'TokenExpirationDelay';
	const BEARER_EXPIRATION_GRACE_PERIOD = 5;

	const PRIMARY_KEY_MAX_LENGTH = 255;

	private $sLoginUrl;
	private $sAuthMode;
	protected $sResource;
	private $sClientId;
	private $sClientSecret;
	protected $sTenantId;
	private $bIsAuthenticated = false;
	protected $sBearerToken = '';
	private $sBearerTokenRequestTime;
	private $sBearerTokenExpirationDelay;
	protected $aParamsSourceJson = [];
	protected $sMSClass = '';
	protected $sApiVersion = '';
	protected static $aURIParameters = [];
	protected $sJsonFile = '';
	protected $aFieldsPos = [];
	protected $oMSCollectionPlan;

	/**
	 * @inheritdoc
	 */
	public function __construct()
	{
		parent::__construct();
	}

	/**
	 * @inheritdoc
	 */
	public function Init(): void
	{
		parent::Init();

		$this->sLoginUrl = Utils::GetConfigurationValue('microsoft_login_url', self::DEFAULT_MICROSOFT_LOGIN_URL);
		$this->sAuthMode = Utils::GetConfigurationValue('microsoft_auth_mode', self::DEFAULT_MICROSOFT_AUTH_MODE);
		$this->sResource = Utils::GetConfigurationValue('microsoft_resource', '');
		$this->sClientId = Utils::GetConfigurationValue('ms_clientid', '');
		$this->sClientSecret = Utils::GetConfigurationValue('ms_clientsecret', '');
		$this->sTenantId = Utils::GetConfigurationValue('ms_tenantid', '');

		$this->aParamsSourceJson = Utils::GetConfigurationValue(strtolower(get_class($this)), array());
		if (isset($this->aParamsSourceJson['ms_class'])) {
			$this->sMSClass = $this->aParamsSourceJson['ms_class'];
		}
		if (isset($this->aParamsSourceJson['api_version'])) {
			$this->sApiVersion = $this->aParamsSourceJson['api_version'];
		}
		if (isset($this->aParamsSourceJson['jsonfile'])) {
			$this->sJsonFile = $this->aParamsSourceJson['jsonfile'];
		}

		$this->oMSCollectionPlan = MSCollectionPlan::GetPlan();
	}

	/**
	 * @inheritdoc
	 */
	public function CheckToLaunch($aOrchestratedCollectors): bool
	{
		$sMyClassName = get_class($this);
		$aURIParameters = $this->GetURIParameters();
		foreach ($aURIParameters as $index => $sParameter) {
			$sParameterClass = 'Azure'.$sParameter.'AzureCollector';
			switch ($sParameter) {
				case MSJsonCollector::URI_PARAM_SUBSCRIPTION:
					if (!$this->oMSCollectionPlan->IsSubscriptionToConsider()) {
						// All Azure objects being attached to a subscription, their discovery is only possible in the case where there is at least one subscription to discover.
						Utils::Log(LOG_INFO, '> '.$sMyClassName.' cannot be launched as required subscriptions will not be discovered');

						return false;
					}
					break;

				case MSJsonCollector::URI_PARAM_RESOURCEGROUP:
					if (!$this->oMSCollectionPlan->IsResourceGroupToConsider()) {
						// If no resource group is already identified, let's check that discovery of resource group is enable.
						if (!array_key_exists($sParameterClass, $aOrchestratedCollectors) ||
							($aOrchestratedCollectors[$sParameterClass] == false)) {
							Utils::Log(LOG_INFO, '> '.$sMyClassName.' cannot be launched as required resource group will not be discovered');

							return false;
						}
					}
					break;

				default:
					if (!array_key_exists($sParameterClass, $aOrchestratedCollectors) ||
						($aOrchestratedCollectors[$sParameterClass] == false)) {
						Utils::Log(LOG_INFO, '> '.$sMyClassName.' cannot be launched as required '.$sParameter.' will not be discovered');

						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Read authentication parameters stored in file
	 *
	 * @return boolean
	 * @throws \Exception
	 */
	private function ReadAuthParamsFromFile(): bool
	{
		$bStatus = false;
		$sTokenFile = Utils::GetDataFilePath(self::BEARER_TOKEN_FILE_NAME);

		if (!file_exists($sTokenFile)) {
			Utils::Log(LOG_DEBUG, 'File '.$sTokenFile.' doesn\'t exist');
		} else {
			$hCSV = fopen($sTokenFile, 'r');
			if ($hCSV === false) {
				Utils::Log(LOG_ERR, "Failed to open '$sTokenFile' for reading...");
			} else {
				while (($aData = fgetcsv($hCSV, 0, $this->sSeparator)) !== false) {
					//process
					switch ($aData[0]) {
						case self::BEARER_TOKEN_NAME:
							$this->sBearerToken = $aData[1];
							break;

						case self::BEARER_TOKEN_REQUEST_TIME:
							$this->sBearerTokenRequestTime = $aData[1];
							break;

						case self::BEARER_TOKEN_EXPIRATION_DELAY_NAME:
							$this->sBearerTokenExpirationDelay = $aData[1];
							break;

						default:
							break;
					}
				}
				$bStatus = true;
				Utils::Log(LOG_DEBUG, 'File '.$sTokenFile.' has been read');
			}
			fclose($hCSV);
		}

		return $bStatus;
	}

	/**
	 * Tells if authentication is already done
	 *
	 * @return boolean
	 * @throws \Exception
	 */
	private function IsAuthenticated(): bool
	{
		if (!$this->bIsAuthenticated) {
			// Read stored parameters
			if ($this->ReadAuthParamsFromFile()) {
				if ($this->sBearerToken == '') {
					Utils::Log(LOG_WARNING, "No Bearer Token found in file.");
				} else {
					// Check expiration date is not over
					$sExpirationTime = $this->sBearerTokenRequestTime + $this->sBearerTokenExpirationDelay;
					if ($sExpirationTime <= time()) {
						Utils::Log(LOG_INFO, "Bearer Token has expired.");
					} elseif ($sExpirationTime <= (time() - self::BEARER_EXPIRATION_GRACE_PERIOD)) {
						Utils::Log(LOG_INFO, "Bearer Token is about to expire.");
					} else {
						$this->bIsAuthenticated = true;
					}
				}
			}
		}

		if ($this->bIsAuthenticated) {
			Utils::Log(LOG_INFO, 'Collector is already authenticated.');
		} else {
			Utils::Log(LOG_INFO, 'Collector is not authenticated yet or needs to re-authenticate !');
		}

		return $this->bIsAuthenticated;
	}

	/**
	 * Perform the authentication to MS. A token is expected in return.
	 *
	 * @return boolean
	 * @throws \Exception
	 */
	private function Authenticate(): bool
	{
		// Check we are notalready authenticated, first
		if ($this->IsAuthenticated()) {
			return true;
		}

		Utils::Log(LOG_INFO, "Start authentication.");

		$sURL = $this->sLoginUrl.$this->sTenantId.$this->sAuthMode;
		$aData = [
			'grant_type'    => "client_credentials",
			'client_id'     => $this->sClientId,
			'client_secret' => $this->sClientSecret,
			'resource'      => $this->sResource,
		];
		$aEmpty = [];

		try {
			$sResponse = utils::DoPostRequest($sURL, $aData, null, $aEmpty, $aEmpty);
			Utils::Log(LOG_DEBUG, "Response to authentication request :".$sResponse);
			$aResults = json_decode($sResponse, true);
		} catch (Exception $e) {
			Utils::Log(LOG_ERR, "Authentication failed: ".$e->getMessage());

			return false;
		}

		if (!array_key_exists('access_token', $aResults)) {
			Utils::Log(LOG_ERR, "Authentication failed: no access_token parameter has been found in Azure response to authentication request.");

			return false;
		}
		$this->sBearerToken = $aResults['access_token'];
		if (!array_key_exists('expires_in', $aResults)) {
			Utils::Log(LOG_ERR, "Authentication failed: no expiration delay has been found in Azure response to authentication request.");

			return false;
		}
		$this->sBearerTokenExpirationDelay = $aResults['expires_in'];

		// Remove token in file
		$sTokenFile = Utils::GetDataFilePath(self::BEARER_TOKEN_FILE_NAME);
		if (file_exists($sTokenFile)) {
			$bResult = @unlink($sTokenFile);
			Utils::Log(LOG_DEBUG, "Erasing previous token file. unlink('$sTokenFile') returned ".($bResult ? 'true' : 'false'));
		}

		// Store token in file
		$hCSV = fopen($sTokenFile, 'w');
		if ($hCSV === false) {
			Utils::Log(LOG_ERR, "Failed to open '$sTokenFile' for writing !");
		} else {
			$aData = [
				array(self::BEARER_TOKEN_NAME, $this->sBearerToken),
				array(self::BEARER_TOKEN_REQUEST_TIME, time()),
				array(self::BEARER_TOKEN_EXPIRATION_DELAY_NAME, $this->sBearerTokenExpirationDelay),
			];
			foreach ($aData as $aValue) {
				fputcsv($hCSV, $aValue, $this->sSeparator);
			}
			fclose($hCSV);

			$this->bIsAuthenticated = true;
		}

		Utils::Log(LOG_INFO, "Authentication succeeded !");

		return true;
	}

	/**
	 *  Build the URL used to collect the requested class
	 *
	 * @param $aParameters
	 *
	 * @return string
	 */
	protected function BuildUrl($aParameters): string
	{
		return '';
	}

	/**
	 *  Report list of discovered objects to the collection plan
	 *
	 * @param $aData
	 * @param $sObjectL1
	 * @param $sObjectL2
	 *
	 * @return void
	 */
	protected function ReportObjects($aData, $sObjectL1, $sObjectL2, $sObjectL3)
	{
	}

	/**
	 * Post URL and process errors from microsoft
	 *
	 * @param $iSubscription
	 * @param $sUrl
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function Post($sUrl, $iSubscription = null): array
	{
		$bSucceed = false;
		$aResults = [];
		$aEmpty = [];
		$aOptionnalHeaders = [
			'Content-type: application/json',
			'Authorization: Bearer '.$this->sBearerToken,
		];
		$sOptionnalHeaders = implode("\n", $aOptionnalHeaders);
		$aCurlOptions = array(CURLOPT_POSTFIELDS => "");
		try {
			$sResponse = utils::DoPostRequest($sUrl, $aEmpty, $sOptionnalHeaders, $aEmpty, $aCurlOptions);
			$aResults = json_decode($sResponse, true);
			if (isset($aResults['error'])) {
				Utils::Log(LOG_ERR,
					"Data collection for ".$this->sMSClass." failed: 
					                Error code: ".$aResults['error']['code']."
					                Message: ".$aResults['error']['message']);
				switch ($aResults['error']['code']) {
					// Some errors should not stop the collection
					case 'ResourceNotFound':
					case 'ParentResourceNotFound':
						$bSucceed = true;
						break;

					default:
						break;
				}
			} else {
				$bSucceed = true;
				$iCount = isset($aResults['value']) ? count($aResults['value']) : 0;
				Utils::Log(LOG_DEBUG, 'Data for class '.$this->sMSClass.' have been retrieved from MS environment '.$iSubscription.'. Count Total = '.$iCount);
			}
		} catch (Exception $e) {
			Utils::Log(LOG_WARNING, "Resource group query failed for subscription '.$iSubscription.': ".$e->getMessage());
		}

		// Return array of objects
		return [$bSucceed, $aResults];
	}

	/**
	 *  Retrieve data from MS for the class that implements the method and store them in given file
	 *
	 * @return bool
	 */
	protected function RetrieveDataFromMS(): array
	{
		$bUrlPosted = false;
		$bSucceed = false;
		$aObjectsToConsider = $this->oMSCollectionPlan->GetMSObjectsToConsider();
		$aConcatenatedResults = [];
		switch (count(static::$aURIParameters)) {
			case 0:
				$sUrl = $this->BuildUrl([]);
				list($bSucceed, $aResults) = $this->Post($sUrl);
				$bUrlPosted = true;
				if ($bSucceed && !empty($aResults['value'])) {
					$aConcatenatedResults = $aResults;
					// Report list of discovered objects to the collection plan
					$this->ReportObjects($aResults, null, null, null);
				}
				break;

			case 1:
				if (array_key_exists(static::$aURIParameters[1], $aObjectsToConsider)) {
					foreach ($aObjectsToConsider[static::$aURIParameters[1]] as $sObjectL1 => $aObjectL1) {
						$sUrl = $this->BuildUrl([static::$aURIParameters[1] => $sObjectL1]);
						list($bSucceed, $aResults) = $this->Post($sUrl, $sObjectL1);
						$bUrlPosted = true;
						if ($bSucceed && !empty($aResults['value'])) {
							if (empty($aConcatenatedResults)) {
								$aConcatenatedResults = $aResults;
							} else {
								$aConcatenatedResults['value'] = array_merge($aConcatenatedResults['value'], $aResults['value']);
							}
							// Report list of discovered objects to the collection plan
							$this->ReportObjects($aResults, $sObjectL1, null, null);
						}
					}
				}
				break;

			case 2:
				if (array_key_exists(static::$aURIParameters[1], $aObjectsToConsider)) {
					foreach ($aObjectsToConsider[static::$aURIParameters[1]] as $sObjectL1 => $aObjectL1) {
						if (array_key_exists(static::$aURIParameters[2], $aObjectL1)) {
							foreach ($aObjectL1[static::$aURIParameters[2]] as $sObjectL2 => $aObjectL2) {
								$sUrl = $this->BuildUrl([static::$aURIParameters[1] => $sObjectL1, static::$aURIParameters[2] => $sObjectL2]);
								list($bSucceed, $aResults) = $this->Post($sUrl, $sObjectL1);
								$bUrlPosted = true;
								if ($bSucceed && !empty($aResults['value'])) {
									if (empty($aConcatenatedResults)) {
										$aConcatenatedResults = $aResults;
									} else {
										$aConcatenatedResults['value'] = array_merge($aConcatenatedResults['value'], $aResults['value']);
									}
									// Report list of discovered objects to the collection plan
									$this->ReportObjects($aResults, $sObjectL1, $sObjectL2, null);
								}
							}
						}
					}
				}
				break;

			case 3:
				if (array_key_exists(static::$aURIParameters[1], $aObjectsToConsider)) {
					foreach ($aObjectsToConsider[static::$aURIParameters[1]] as $sObjectL1 => $aObjectL1) {
						if (array_key_exists(static::$aURIParameters[2], $aObjectL1)) {
							foreach ($aObjectL1[static::$aURIParameters[2]] as $sObjectL2 => $aObjectL2) {
								if (array_key_exists(static::$aURIParameters[3], $aObjectL2)) {
									foreach ($aObjectL2[static::$aURIParameters[3]] as $sObjectL3 => $aObjectL3) {
										$sUrl = $this->BuildUrl([
											static::$aURIParameters[1] => $sObjectL1,
											static::$aURIParameters[2] => $sObjectL2,
											static::$aURIParameters[3] => $sObjectL3,
										]);
										list($bSucceed, $aResults) = $this->Post($sUrl, $sObjectL1);
										$bUrlPosted = true;
										if ($bSucceed && !empty($aResults['value'])) {
											if (empty($aConcatenatedResults)) {
												$aConcatenatedResults = $aResults;
											} else {
												$aConcatenatedResults['value'] = array_merge($aConcatenatedResults['value'], $aResults['value']);
											}
											// Report list of discovered resource group to the collection plan
											$this->ReportObjects($aResults, $sObjectL1, $sObjectL2, $sObjectL3);
										}
									}
								}
							}
						}
					}
				}
				break;

			default:
				break;
		}

		return [$bUrlPosted, $bSucceed, $aConcatenatedResults];
	}

	/**
	 * Get list of parameters required for the collection query
	 *
	 * @return bool
	 */
	public function GetURIParameters(): array
	{
		return static::$aURIParameters;
	}

	/**
	 * Runs the configured query to start fetching the data from the database
	 * Store result in json data file
	 *
	 * @see jsonCollector::Prepare()
	 */
	public function Prepare(): bool
	{
		// Check MS class is set
		if ($this->sMSClass == '') {
			Utils::Log(LOG_ERR, 'Parameter "class" is not defined within the current collector parameters!');

			return false;
		}

		// Make sure we are authenticated
		if (!$this->Authenticate()) {
			Utils::Log(LOG_ERR, 'Collect of '.$this->sMSClass.' is not possible: collector cannot authenticate!');

			return false;
		}

		// Check JSON file name where tor store collection exists
		if ($this->sJsonFile == '') {
			Utils::Log(LOG_ERR, "No file path where to store the retrieved data has been defined!");

			return false;
		}

		// Retrieve data from MS
		Utils::Log(LOG_DEBUG, 'Retrieve '.$this->sMSClass.' data from MS');
		list ($bUrlPosted, $bSucceed, $aResults) = $this->RetrieveDataFromMS();
		if (!$bUrlPosted) {
			Utils::Log(LOG_DEBUG, 'No request have been posted !');
		} else {
			if (!$bSucceed) {
				Utils::Log(LOG_DEBUG, 'Retrieval failed !');

				return false;
			}
		}

		// Store JSON data in file
		// JSON_FORCE_OBJECT makes sure that an empty json file ( {} ) is created if $aResults is empty
		$hJSON = file_put_contents($this->sJsonFile, json_encode($aResults, JSON_FORCE_OBJECT));
		if ($hJSON === false) {
			Utils::Log(LOG_ERR, "Failed to write retrieved data in '$this->sJsonFile' !");

			return false;
		}
		if (empty($aResults)) {
			Utils::Log(LOG_INFO, "Result of collect is empty !");
			$this->RemoveDataFiles();

			return true;    // It is important to return true here as the synchro should proceed even if no object have been retrieved.
		}

		return parent::Prepare();
	}

	/**
	 * @inheritdoc
	 */
	public function Collect($iMaxChunkSize = 0): bool
	{
		Utils::Log(LOG_INFO, '----------------');

		return parent::Collect($iMaxChunkSize);
	}

	/**
	 * Initializes the mapping between the column names (given by the first line of the CSV) and their index, for the given columns
	 *
	 * @param array $aLineHeaders An array of strings (the "headers" i.e. first line of the CSV file)
	 * @param array $aFields The fields for which a mapping is requested, as an array of strings
	 */
	protected function InitLineMappings($aLineHeaders, $aFields)
	{
		foreach ($aLineHeaders as $idx => $sHeader) {
			if (in_array($sHeader, $aFields)) {
				$this->aFieldsPos[$sHeader] = $idx;
			}
		}

		// Check that all requested fields were found in the headers
		foreach ($aFields as $sField) {
			if (!array_key_exists($sField, $this->aFieldsPos)) {
				Utils::Log(LOG_ERR, "'$sField' is not a valid column name in the CSV file. Mapping will fail.");
			}
		}
	}

	/**
	 * Compute the lookup
	 *
	 * @param $aLookupKey
	 * @param $sDestField
	 *
	 * @return array
	 */
	protected function DoLookup($aLookupKey, $sDestField): array
	{
		return [false, ''];
	}

	/**
	 * Replaces a given field in the CSV data by the content of given lookup fields
	 *
	 * @param $aLineData
	 * @param $aLookupFields
	 * @param $sDestField
	 * @param $iLineIndex
	 * @param $bIgnoreMappingErrors
	 *
	 * @return bool
	 * @throws \Exception
	 */
	protected function Lookup(&$aLineData, $aLookupFields, $sDestField, $iLineIndex, $bIgnoreMappingErrors): bool
	{
		$bRet = true;
		if ($iLineIndex == 0) {
			$this->InitLineMappings($aLineData, array_merge($aLookupFields, array($sDestField)));
		} else {
			$aLookupKey = array();
			foreach ($aLookupFields as $sField) {
				$iPos = $this->aFieldsPos[$sField];
				if ($iPos !== null) {
					$aLookupKey[$sField] = $aLineData[$iPos];
				} else {
					$aLookupKey[$sField] = ''; // missing column ??
				}
			}
			list($bResult, $sField) = $this->DoLookup($aLookupKey, $sDestField);
			if (!$bResult) {
				if ($bIgnoreMappingErrors) {
					// Mapping *errors* are expected, just report them in debug mode
					Utils::Log(LOG_DEBUG, "No mapping found for attribute '$sDestField' which will be set to zero.");
				} else {
					Utils::Log(LOG_WARNING, "No mapping found for attribute '$sDestField' which will be set to zero.");
				}
				$bRet = false;
			} else {
				$iPos = $this->aFieldsPos[$sDestField];
				if ($iPos !== null) {
					$aLineData[$iPos] = $sField;
				} else {
					Utils::Log(LOG_WARNING, "'$sDestField' is not a valid column name in the CSV file. Mapping will be ignored.");
				}
			}
		}

		return $bRet;
	}

	/**
	 * @inheritdoc
	 */
	public function Synchronize($iMaxChunkSize = 0): bool
	{
		Utils::Log(LOG_INFO, '----------------');

		return parent::Synchronize($iMaxChunkSize);
	}

	/**
	 * @inheritdoc
	 */
	public function Fetch()
	{
		$aData = parent::Fetch();
		if ($aData !== false) {
			// Make sure primary_key is not too long
			$aData['primary_key'] = substr($aData['primary_key'], -self::PRIMARY_KEY_MAX_LENGTH);

			// Then process specific data
			$iJsonIdx = $this->iIdx - 1; // Increment is done at the end of parent::Fetch()

			if (!$this->AttributeIsOptional('azuretags')) {
				// Get the TAGs, a common attribute to most Azure classes
				$sTags = '';
				if (array_key_exists('tags', $this->aJson[$this->aJsonKey[$iJsonIdx]])) {
					$aTags = $this->aJson[$this->aJsonKey[$iJsonIdx]]['tags'];
					foreach ($aTags as $sKey => $sValue) {
						$sTags .= $sKey.' : '.$sValue."\n";
					}
				}
				$aData['azuretags'] = $sTags;
			}
		}

		return $aData;
	}

}
