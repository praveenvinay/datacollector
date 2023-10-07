<?php

abstract class MSCollectionPlan extends CollectionPlan
{
	// List of objects that can be used within requests
	protected $aMSObjectsToConsider = [];

	/**
	 * Enrich the list of objects that can be considered during collections
	 *
	 * @param $aObjL1 : 1st level object : ex URI_PARAM_SUBSCRIPTION or URI_PARAM_GROUP
	 * @param $aObjL2 : 2nd level object : ex URI_PARAM_RESOURCEGROUP
	 * @param $aObjL3 : 3rd level object : ex URI_PARAM_SERVER
	 * Each $aObjLi = array('class', 'id')
	 *
	 * @return void
	 */
	public function AddMSObjectsToConsider($aObjL1, $aObjL2, $aObjL3)
	{
		// Process 1st level first
		if (!empty($aObjL1)) {
			if (!array_key_exists($aObjL1['class'], $this->aMSObjectsToConsider)) {
				$this->aMSObjectsToConsider[$aObjL1['class']] = [];
				$this->aMSObjectsToConsider[$aObjL1['class']][$aObjL1['id']] = [];
			} elseif (!array_key_exists($aObjL1['id'], $this->aMSObjectsToConsider[$aObjL1['class']])) {
				$this->aMSObjectsToConsider[$aObjL1['class']][$aObjL1['id']] = [];
			}

			// Next process 2nd level if required
			if (!empty($aObjL2)) {
				$aL1ToConsider = $this->aMSObjectsToConsider[$aObjL1['class']][$aObjL1['id']];
				if (!array_key_exists($aObjL2['class'], $aL1ToConsider)) {
					$aL1ToConsider[$aObjL2['class']] = [];
					$aL1ToConsider[$aObjL2['class']][$aObjL2['id']] = [];
				} elseif (!array_key_exists($aObjL2['id'], $aL1ToConsider[$aObjL2['class']])) {
					$aL1ToConsider[$aObjL2['class']][$aObjL2['id']] = [];
				}
				$this->aMSObjectsToConsider[$aObjL1['class']][$aObjL1['id']] = $aL1ToConsider;

				// Then process 3rd level if required
				if (!empty($aObjL3)) {
					$aL2ToConsider = $aL1ToConsider[$aObjL2['class']][$aObjL2['id']];
					if (!array_key_exists($aObjL3['class'], $aL2ToConsider)) {
						$aL2ToConsider[$aObjL3['class']] = [];
						$aL2ToConsider[$aObjL3['class']][$aObjL3['id']] = [];
					} elseif (!array_key_exists($aObjL3['id'], $aL2ToConsider[$aObjL3['class']])) {
						$aL2ToConsider[$aObjL3['class']][$aObjL3['id']] = [];
					}
					$this->aMSObjectsToConsider[$aObjL1['class']][$aObjL1['id']][$aObjL2['class']][$aObjL2['id']] = $aL2ToConsider;
				}
			}
		}
	}

	/**
	 * Provide the list of objects to consider during the collection
	 *
	 * @return array|\string[][][]
	 */
	public function GetMSObjectsToConsider(): array
	{

		return $this->aMSObjectsToConsider;
	}

	/**
	 * Is there any subscription to consider in the collection plan ?
	 *
	 * @return bool
	 */
	public function IsSubscriptionToConsider(): bool
	{
		if (array_key_exists(MSJsonCollector::URI_PARAM_SUBSCRIPTION, $this->aMSObjectsToConsider) && !empty($this->aMSObjectsToConsider[MSJsonCollector::URI_PARAM_SUBSCRIPTION])) {
			return true;
		}

		return false;
	}

	/**
	 * Is there any resource group to consider during the collection ?
	 *
	 * @return bool
	 */
	public function IsResourceGroupToConsider(): bool
	{
		if ($this->IsSubscriptionToConsider()) {
			$aSubscriptions = $this->aMSObjectsToConsider[MSJsonCollector::URI_PARAM_SUBSCRIPTION];
			foreach ($aSubscriptions as $aSubscription) {
				if (array_key_exists(MSJsonCollector::URI_PARAM_RESOURCEGROUP, $aSubscription) && !empty($aSubscription[MSJsonCollector::URI_PARAM_RESOURCEGROUP])) {
					return true;
				}
			}
		}

		return false;
	}
}
