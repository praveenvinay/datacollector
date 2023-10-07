<?php
require_once(APPROOT.'collectors/msbase/src/MSJsonCollector.class.inc.php');

class AzureDiskAzureCollector extends MSJsonCollector
{
	// Required parameters to build URL
	protected static $aURIParameters = [
		1 => self::URI_PARAM_SUBSCRIPTION,
		2 => self::URI_PARAM_RESOURCEGROUP,
	];

	/**
	 * @inheritdoc
	 */
	public function AttributeIsOptional($sAttCode): bool
	{
		if ($sAttCode == 'services_list') {
			return true;
		}

		return parent::AttributeIsOptional($sAttCode);
	}

	/**
	 * @inheritdoc
	 */
	protected function BuildUrl($aParameters): string
	{
		if (!array_key_exists(self::URI_PARAM_SUBSCRIPTION, $aParameters) || !array_key_exists(self::URI_PARAM_RESOURCEGROUP,
				$aParameters)) {
			return '';
		} else {
			$sUrl = $this->sResource.'subscriptions/'.$aParameters[self::URI_PARAM_SUBSCRIPTION];
			$sUrl .= '/resourceGroups/'.$aParameters[self::URI_PARAM_RESOURCEGROUP];
			$sUrl .= '/providers/Microsoft.Compute/disks?api-version='.$this->sApiVersion;

			return $sUrl;
		}
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
		// Create address mapping table
		$this->oSKUMapping = new LookupTable('SELECT AzureSKU WHERE type = \'disks\'', array('name', 'maxsizegib'));

	}

	/**
	 * @inheritdoc
	 */
	public function Prepare(): bool
	{
		// Create MappingTable
		$this->oDiskEncryptionMapping = new MappingTable('disk_encryption_mapping');

		return parent::Prepare();
	}

	/**
	 * @inheritdoc
	 */
	protected function DoLookup($aLookupKey, $sDestField): array
	{
		$sResult = false;
		$sData = '';
		switch ($sDestField) {
			case 'azureresourcegroup_id':
				if (array_key_exists('primary_key', $aLookupKey) && ($aLookupKey['primary_key'] != '')) {
					$sData = strstr($aLookupKey['primary_key'], 'resourceGroups');
					if ($sData !== false) {
						$aData = explode('/', $sData);
						$sData = $aData[1];
						$sResult = true;
					}
				}
				break;

			case 'azuresubscription_id':
				if (array_key_exists('primary_key', $aLookupKey) && ($aLookupKey['primary_key'] != '')) {
					$sData = strstr($aLookupKey['primary_key'], 'subscriptions');
					if ($sData !== false) {
						$aData = explode('/', $sData);
						$sData = $aData[1];
						$sResult = true;
					}
				}
				break;

			case 'azurevirtualmachine_id':
				if (array_key_exists('primary_key', $aLookupKey) && ($aLookupKey['primary_key'] != '')) {
					$sData = strstr($aLookupKey['primary_key'], 'disks');
					if ($sData !== false) {
						$aData = explode('/', $sData);
						$aData = explode('_', $aData[1]);
						$sData = $aData[0];
						$sResult = true;
					}
				}
				break;

			default:
				break;
		}

		return [$sResult, $sData];
	}

	/**
	 * @inheritdoc
	 */
	protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex)
	{
		// Process each line of the CSV
		if (!$this->Lookup($aLineData, array('primary_key'), 'azureresourcegroup_id', $iLineIndex, true, false)) {
			throw new IgnoredRowException('Unknown code');
		}
		if (!$this->Lookup($aLineData, array('primary_key'), 'azuresubscription_id', $iLineIndex, true, false)) {
			throw new IgnoredRowException('Unknown code');
		}
		if (!$this->Lookup($aLineData, array('primary_key'), 'azurevirtualmachine_id', $iLineIndex, true, false)) {
			throw new IgnoredRowException('Unknown code');
		}

		$this->oSKUMapping->Lookup($aLineData, array('azuresku_id', 'size'), 'azuresku_id', $iLineIndex);

	}

	/**
	 * @inheritdoc
	 */
	public function Fetch()
	{
		$aData = parent::Fetch();
		if ($aData !== false) {
			// Then process specific data
			$iJsonIdx = $this->iIdx - 1; // Increment is done at the end of parent::Fetch()
			$aData['azurestatus'] = strtolower($this->aJson[$this->aJsonKey[$iJsonIdx]]['properties']['diskState']);
			if (array_key_exists('encryptionSettingsCollection', $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties'])) {
				$aData['encryption'] = $this->oDiskEncryptionMapping->MapValue($this->aJson[$this->aJsonKey[$iJsonIdx]]['properties']['encryptionSettingsCollection']['enabled'], 'disabled');
			} else {
				$aData['encryption'] = 'disabled';
			}
			if (array_key_exists('osType', $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties'])) {
				$aData['osfamily_id'] = $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties']['osType'];
			} else {
				$aData['osfamily_id'] = '';
			}
		}

		return $aData;
	}

}

