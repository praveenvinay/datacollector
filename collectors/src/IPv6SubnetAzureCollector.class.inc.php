<?php
require_once(APPROOT.'collectors/msbase/src/MSCsvCollector.class.inc.php');

class IPv6SubnetAzureCollector extends MSCsvCollector
{
	protected static $sCsvSourceFilePath = null;
	protected static $aHeaderColumns = null;
	protected static $aJsonToCsv = null;
	protected static $bCsvSourceFileExits = false;
	protected static $bHasStaticBeenInitialized = false;

	/**
	 * @inheritdoc
	 */
	public function Init(): void
	{
		parent::Init();

		if (!static::$bHasStaticBeenInitialized) {
			// Init variables
			static::$sCsvSourceFilePath = static::GetCsvSourceFilePath();
			static::$aHeaderColumns = static::GetCsvSourceFileHeader();
			static::$aJsonToCsv = static::GetJsonToCsv();

			// Init CSV source file
			static::$bCsvSourceFileExits = static::InitCsvSourceFile(static::$sCsvSourceFilePath, static::$aHeaderColumns);

			static::$bHasStaticBeenInitialized = true;
		}
	}


	/**
	 * Register a new line into the CSV source file
	 *
	 * @param $aData
	 * @return bool
	 * @throws Exception
	 */
	public static function RegisterLine($aData): bool
	{
		if (static::$bCsvSourceFileExits) {
			return parent::AddLineToCsvSourceFile($aData, static::$sCsvSourceFilePath, static::$aJsonToCsv);
		} else {
			return false;
		}

	}

	/**
	 * @inheritdoc
	 */
	public function CheckToLaunch($aOrchestratedCollectors): bool
	{
		if (parent::CheckToLaunch($aOrchestratedCollectors)) {
			if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
				return true;
			} else {
				Utils::Log(LOG_INFO, '> IPv6SubnetAzureCollector will not be launched as TeemIp is not installed');
			}
		}

		return false;
	}

	/**
	 * @inheritdoc
	 */
	public function AttributeIsOptional($sAttCode): bool
	{
		if ($sAttCode == 'services_list') return true;

		return parent::AttributeIsOptional($sAttCode);
	}

	/**
	 * @inheritdoc
	 */
	protected function MustProcessBeforeSynchro(): bool
	{
		return true;
	}

	/**
	 * @inheritdoc
	 */
	protected function InitProcessBeforeSynchro(): void
	{
		// Create IPConfig mapping table
		$this->oIPv6SubnetIPConfigMapping = new LookupTable('SELECT IPConfig', array('org_id_friendlyname'));
	}

	/**
	 * @inheritdoc
	 */
	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex)
	{
		if (!$this->oIPv6SubnetIPConfigMapping->Lookup($aLineData, array('org_id'), 'ipconfig_id', $iLineIndex)) {
			throw new IgnoredRowException('Unknown IP Config');
		}
	}
}
