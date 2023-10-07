<?php
require_once(APPROOT.'collectors/msbase/src/MSCollectionPlan.class.inc.php');
require_once(APPROOT.'collectors/msbase/src/MSJsonCollector.class.inc.php');

class AzureCollectionPlan extends MSCollectionPlan
{
	private $bTeemIpIsInstalled;
    private $bTeemIpIpDiscoveryIsInstalled;
    private $bTeemIpNMEIsInstalled;
    private $bTeemIpZoneMgmtIsInstalled;

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

		// Fetch from iTop the list of subscriptions to discover
		Utils::Log(LOG_INFO, '---------- Fetch from iTop the list of Subscriptions to discover ----------');
		$oRestClient = new RestClient();
		try {
			$aResult = $oRestClient->Get('AzureSubscription', 'SELECT AzureSubscription WHERE discover_objects = \'yes\'');
			if ($aResult['code'] != 0) {
				Utils::Log(LOG_ERR, "{$aResult['message']} ({$aResult['code']})");
			} else {
				if (empty($aResult['objects'])) {
					// No object found
					Utils::Log(LOG_INFO, "There is no Azure subscription stored in iTop for which objects need to be discovered.");
				} else {
					foreach ($aResult['objects'] as $sKey => $aData) {
						$aAzureSubscriptionAttributes = $aData['fields'];
						$iSubscriptionId = $aAzureSubscriptionAttributes['subscriptionid'];
						$this->AddMSObjectsToConsider(['class' => MSJsonCollector::URI_PARAM_SUBSCRIPTION, 'id' => $iSubscriptionId], [], []);

						Utils::Log(LOG_INFO, 'Name: '.$aAzureSubscriptionAttributes['name'].' - ID: '.$iSubscriptionId);
					}
				}
			}
		} catch (Exception $e) {
			$sMessage = 'Cannot fetch subscriptions from iTop: '.$e->getMessage();
			if (is_a($e, "IOException")) {
				Utils::Log(LOG_ERR, $sMessage);
				throw $e;
			}
		}

		// Fetch from iTop the list of Resource Groups that belong to subscriptions to discover
		if ($this->IsSubscriptionToConsider()) {
			Utils::Log(LOG_INFO, '---------- Fetch from iTop the list of Resource groups ----------');
			$bFirstEntry = true;
			$sSubscriptionList = '';
			foreach ($this->aMSObjectsToConsider[MSJsonCollector::URI_PARAM_SUBSCRIPTION] as $sSubscription => $aSubscription) {
				$sSubscriptionList .= ($bFirstEntry) ? "'".$sSubscription."'" : ",'".$sSubscription."'";
				$bFirstEntry = false;
			}
			$oRestClient = new RestClient();
			try {
				$aResult = $oRestClient->Get('AzureResourceGroup', 'SELECT AzureResourceGroup AS rg JOIN AzureSubscription AS s ON rg.azuresubscription_id =  s.id WHERE s.subscriptionid IN ('.$sSubscriptionList.')');
				if ($aResult['code'] != 0) {
					Utils::Log(LOG_ERR, "{$aResult['message']} ({$aResult['code']})");
				} else {
					if (empty($aResult['objects'])) {
						// No object found
						Utils::Log(LOG_INFO,
							"There is no Azure resource groups already stored in iTop within the subscriptions to discover.");
					} else {
						foreach ($aResult['objects'] as $sKey => $aData) {
							$aAzureResourceGroupAttributes = $aData['fields'];
							$sResourceGroupName = $aAzureResourceGroupAttributes['name'];
							$this->AddMSObjectsToConsider(['class' => MSJsonCollector::URI_PARAM_SUBSCRIPTION, 'id' => $aAzureResourceGroupAttributes['azuresubscription_subscriptionid']],
								['class' => MSJsonCollector::URI_PARAM_RESOURCEGROUP, 'id' => $sResourceGroupName], []);

							Utils::Log(LOG_INFO,
								'Subscription ID: '.$aAzureResourceGroupAttributes['azuresubscription_name'].' - Name: '.$sResourceGroupName);
						}
					}
				}
			} catch (Exception $e) {
				$sMessage = 'Cannot fetch subscriptions from iTop: '.$e->getMessage();
				if (is_a($e, "IOException")) {
					Utils::Log(LOG_ERR, $sMessage);
					throw $e;
				}
			}
		}

		// If TeemIp should be considered, check if it is installed or not
		Utils::Log(LOG_INFO, '---------- Check TeemIp installation ----------');
		$this->bTeemIpIsInstalled = false;
        $this->bTeemIpIpDiscoveryIsInstalled = false;
        $this->bTeemIpNMEIsInstalled = false;
        $this->bTeemIpZoneMgmtIsInstalled = false;
		$oRestClient = new RestClient();
		try {
			$aResult = $oRestClient->Get('IPAddress', 'SELECT IPAddress WHERE id = 0');
			if ($aResult['code'] == 0) {
				$this->bTeemIpIsInstalled = true;
				Utils::Log(LOG_INFO, 'TeemIp is installed');
			} else {
				Utils::Log(LOG_INFO, $sMessage = 'TeemIp is NOT installed');
			}
		} catch (Exception $e) {
			$sMessage = 'TeemIp is considered as NOT installed due to: '.$e->getMessage();
			if (is_a($e, "IOException")) {
				Utils::Log(LOG_ERR, $sMessage);
				throw $e;
			}
		}

		if ($this->bTeemIpIsInstalled) {
            // Check if TeemIp IpDiscovery is installed or not
            $oRestClient = new RestClient();
            try {
                $aResult = $oRestClient->Get('IPDiscovery', 'SELECT IPDiscovery WHERE id = 0');
                if ($aResult['code']==0) {
                    $this->bTeemIpIpDiscoveryIsInstalled = true;
                    Utils::Log(LOG_INFO, 'TeemIp IP Discovery is installed');
                } else {
                    Utils::Log(LOG_INFO, 'TeemIp IP Discovery is NOT installed');
                }
            } catch (Exception $e) {
                $sMessage = 'TeemIp IP Discovery is considered as NOT installed due to: '.$e->getMessage();
                if (is_a($e, "IOException")) {
                    Utils::Log(LOG_ERR, $sMessage);
                    throw $e;
                }
            }

            // Check if TeemIp Network Management Extended is installed or not
            $oRestClient = new RestClient();
            try {
                $aResult = $oRestClient->Get('InterfaceSpeed', 'SELECT InterfaceSpeed WHERE id = 0');
                if ($aResult['code']==0) {
                    $this->bTeemIpNMEIsInstalled = true;
                    Utils::Log(LOG_INFO, 'TeemIp Network Management Extended is installed');
                } else {
                    Utils::Log(LOG_INFO, 'TeemIp Network Management Extended is NOT installed');
                }
            } catch (Exception $e) {
                $sMessage = 'TeemIp Network Management Extended is considered as NOT installed due to: '.$e->getMessage();
                if (is_a($e, "IOException")) {
                    Utils::Log(LOG_ERR, $sMessage);
                    throw $e;
                }
            }

            // Check if TeemIp Zone Management is installed or not
            $oRestClient = new RestClient();
            try {
                $aResult = $oRestClient->Get('Zone', 'SELECT Zone WHERE id = 0');
                if ($aResult['code']==0) {
                    $this->bTeemIpZoneMgmtIsInstalled = true;
                    Utils::Log(LOG_INFO, 'TeemIp Zone Management extension is installed');
                } else {
                    Utils::Log(LOG_INFO, 'TeemIp Zone Management extension is NOT installed');
                }
            } catch (Exception $e) {
                $sMessage = 'TeemIp Zone Management extension is considered as NOT installed due to: '.$e->getMessage();
                if (is_a($e, "IOException")) {
                    Utils::Log(LOG_ERR, $sMessage);
                    throw $e;
                }
            }
        }
	}

    /**
     * Check if TeemIp is installed
     *
     * @return bool
     */
    public function IsTeemIpInstalled(): bool
    {
        return $this->bTeemIpIsInstalled;
    }

    /**
     * Check if TeemIp Ip Discovey extension is installed
     *
     * @return bool
     */
    public function IsTeemIpIpDiscoveryinstalled(): bool
    {
        return $this->bTeemIpIpDiscoveryIsInstalled;
    }

    /**
     * Check if TeemIp Network Management Extended extension is installed
     *
     * @return bool
     */
    public function IsTeemIpNMEInstalled(): bool
    {
        return $this->bTeemIpNMEIsInstalled;
    }

    /**
     * Check if TeemIp Zone Management is installed
     *
     * @return bool
     */
    public function IsTeemIpZoneMgmtInstalled(): bool
    {
        return $this->bTeemIpZoneMgmtIsInstalled;
    }

	/**
	 * @inheritdoc
	 */
	public function AddCollectorsToOrchestrator(): bool
	{
		Utils::Log(LOG_INFO, "---------- Azure Collectors to launched ----------");

		return parent::AddCollectorsToOrchestrator();
	}
}
