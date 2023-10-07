<?php
require_once(APPROOT.'collectors/msbase/src/MSJsonCollector.class.inc.php');

class AzureNetworkInterfaceIPConfigAzureCollector extends MSJsonCollector
{
	// Required parameters to build URL
	protected static $aURIParameters = [
		1 => self::URI_PARAM_SUBSCRIPTION,
		2 => self::URI_PARAM_RESOURCEGROUP,
		3 => self::URI_PARAM_NETWORKINTERFACE,
	];

	/**
	 * @inheritdoc
	 */
	public function AttributeIsOptional($sAttCode): bool
	{
		if ($sAttCode == 'services_list') {
			return true;
		}

		if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
			if ($sAttCode == 'private_ip') return true;
			if ($sAttCode == 'ip_id') return false;
		} else {
			if ($sAttCode == 'private_ip') return false;
			if ($sAttCode == 'ip_id') return true;
		}

		return parent::AttributeIsOptional($sAttCode);
	}

	/**
	 * @inheritdoc
	 */
	protected function BuildUrl($aParameters): string
	{
		if (!array_key_exists(self::URI_PARAM_SUBSCRIPTION, $aParameters) || !array_key_exists(self::URI_PARAM_RESOURCEGROUP,
				$aParameters) || !array_key_exists(self::URI_PARAM_NETWORKINTERFACE, $aParameters)) {
			return '';
		} else {
			$sUrl = $this->sResource.'subscriptions/'.$aParameters[self::URI_PARAM_SUBSCRIPTION];
			$sUrl .= '/resourceGroups/'.$aParameters[self::URI_PARAM_RESOURCEGROUP];
			$sUrl .= '/providers/Microsoft.Network/networkInterfaces/'.$aParameters[self::URI_PARAM_NETWORKINTERFACE];
			$sUrl .= '/ipConfigurations?api-version='.$this->sApiVersion;

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
		if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
			$this->oIPv4AddressMapping = new LookupTable('SELECT IPv4Address WHERE azureip = \'yes\'', array('org_id_friendlyname', 'ip'));
			$this->oIPv6AddressMapping = new LookupTable('SELECT IPv6Address WHERE azureip = \'yes\'', array('org_id_friendlyname', 'ip'));
		}

	}

	/**
	 * @inheritdoc
	 */
	public function Prepare(): bool
	{
		// Create MappingTable
		$this->oSubnetMaskMapping = new MappingTable('subnet_mask_mapping');

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

			case 'azurenetworkinterface_id':
				if (array_key_exists('primary_key', $aLookupKey) && ($aLookupKey['primary_key'] != '')) {
					$sData = strstr($aLookupKey['primary_key'], 'networkInterfaces');
					if ($sData !== false) {
						$aData = explode('/', $sData);
						$sData = $aData[1];
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
			throw new IgnoredRowException('Unknown resource group');
		}
		if (!$this->Lookup($aLineData, array('primary_key'), 'azuresubscription_id', $iLineIndex, true, false)) {
			throw new IgnoredRowException('Unknown subscription');
		}
		if (!$this->Lookup($aLineData, array('primary_key'), 'azurenetworkinterface_id', $iLineIndex, true, false)) {
			throw new IgnoredRowException('Unknown network interface');
		}
		if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
			if ($iLineIndex == 0) {
				// Make sure both lookup tables are correctly initialized
				$this->oIPv4AddressMapping->Lookup($aLineData, array('org_id', 'ip_id'), 'ip_id', 0);
				$this->oIPv6AddressMapping->Lookup($aLineData, array('org_id', 'ip_id'), 'ip_id', 0);
			} else {
				// Try to map IP - non mandatory
				if (!$this->oIPv4AddressMapping->Lookup($aLineData, array('org_id', 'ip_id'), 'ip_id', $iLineIndex) &&
					!$this->oIPv6AddressMapping->Lookup($aLineData, array('org_id', 'ip_id'), 'ip_id', $iLineIndex)) {
					Utils::Log(LOG_WARNING, '|->No IPv4 and no IPv6 has been found in the given organization.');
				}
			}
		}
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
			$sProperties = $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties'];

			if (array_key_exists('privateIPAddress', $sProperties)) {
				$sIP = $sProperties['privateIPAddress'];
			} else {
				$sIP = '';
			}
			if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
				if (array_key_exists('private_ip', $aData)) {
					unset($aData['private_ip']);
				}
				$aData['ip_id'] = $sIP;
			} else {
				$aData['private_ip'] = $sIP;
				if (array_key_exists('ip_id', $aData)) {
					unset($aData['ip_id']);
				}
			}
			$aData['private_ip_version'] = strtolower($sProperties['privateIPAddressVersion']);
			$aData['private_ip_allocation_method'] = $sProperties['privateIPAllocationMethod'];
			$aData['primary'] = ($sProperties['primary'] == 'true') ? 'yes' : 'no';
			if (array_key_exists('publicIPAddress', $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties'])) {
				$aData['public_ip_id'] = $this->aJson[$this->aJsonKey[$iJsonIdx]]['properties']['publicIPAddress']['id'];
			} else {
				$aData['public_ip_id'] = '';
			}
			if (array_key_exists('subnet', $sProperties)) {
				$aData['azuresubnet_id'] = $sProperties['subnet']['id'];
			}
		}

		// Add entry to IP Address csv file
		if ($this->oMSCollectionPlan->IsTeemIpInstalled()) {
			// Add entry to IP Address csv file
			if (is_array($aData) && array_key_exists('private_ip_version', $aData) && ($aData['ip_id'] != '')) {
				if (strtolower($aData['private_ip_version']) == 'ipv4') {
					IPv4AddressAzureCollector::RegisterLine($aData);
				} else {
					IPv6AddressAzureCollector::RegisterLine($aData);
				}
			}
		}

		return $aData;
	}

}


