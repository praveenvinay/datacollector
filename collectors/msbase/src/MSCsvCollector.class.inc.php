<?php

/**
 * Base class for MS CSV collectors
 *
 */
abstract class MSCsvCollector extends CSVCollector
{
	protected $oMSCollectionPlan;

	/**
	 * @inheritdoc
	 */
	public function Init(): void
	{
		parent::Init();

		// Get a copy of the collection plan
		$this->oMSCollectionPlan = MSCollectionPlan::GetPlan();
	}

	/**
	 * Find out where the CSV source file should be located
	 *
	 * @return string
	 * @throws Exception
	 */
	protected static function GetCsvSourceFilePath(): string
	{
		// Path has not been computed yet. Do it now.
		$sCsvFilePath = '';
		$aClassConfig = Utils::GetConfigurationValue(strtolower(get_called_class()));
		if (is_array($aClassConfig)) {
			if (array_key_exists('csv_file', $aClassConfig)) {
				$sCsvFilePath = $aClassConfig['csv_file'];
			}
			if ($sCsvFilePath === '') {
				Utils::Log(LOG_ERR, get_called_class().': no CSV file has been defined in param file !');

				return $sCsvFilePath;
			}
			if (strpos($sCsvFilePath, '/') != 0) {
				$sCsvFilePath = APPROOT.$sCsvFilePath;
			}
		}

		return $sCsvFilePath;
	}

	/**
	 * Define the header of the CSV source file
	 *
	 * @return array
	 * @throws Exception
	 */
	protected static function GetCsvSourceFileHeader(): array
	{
		// Header has not been computed yet. Do it now.
		$aHeaderColumns = [];
		$aClassConfig = Utils::GetConfigurationValue(strtolower(get_called_class()));
		if (is_array($aClassConfig)) {
			if (array_key_exists('fields', $aClassConfig)) {
				$aFields = $aClassConfig['fields'];
				if (!is_array($aFields)) {
					Utils::Log(LOG_ERR, get_called_class().': fields section configuration is not correct. Please see documentation.');
				} else {
					foreach ($aFields as $key => $value) {
						$aHeaderColumns[] = $key;
					}
				}
			}
		}

		return $aHeaderColumns;
	}

	/**
	 * List the JSON attributes used to build the CSV file
	 *
	 * @return array
	 * @throws Exception
	 */
	protected static function GetJsonToCsv(): array
	{
		// JsonToCsv has not been computed yet. Do it now.
		$aJsonToCsv = [];
		$aClassConfig = Utils::GetConfigurationValue(strtolower(get_called_class()));
		if (is_array($aClassConfig)) {
			if (array_key_exists('fields', $aClassConfig)) {
				$aFields = $aClassConfig['fields'];
				if (!is_array($aFields)) {
					Utils::Log(LOG_ERR, get_called_class().': fields section configuration is not correct. Please see documentation.');
				} else {
					foreach ($aFields as $key => $value) {
						$aJsonToCsv[] = $value;
					}
				}
			}
		}

		return $aJsonToCsv;
	}

	/**
	 * Initialise CSV source file if required
	 *
	 * @return bool
	 * @throws Exception
	 */
	protected static function InitCsvSourceFile($sCsvFilePath, $aHeaderData): bool
	{
		if ($sCsvFilePath == '') {
			return false;
		}

		// Erase file built in previous collects
		if (file_exists($sCsvFilePath)) {
			unlink($sCsvFilePath);
		}

		// Create file and initialize it
		$hOutputCSV = fopen($sCsvFilePath, 'w');
		if ($hOutputCSV === false) {
			Utils::Log(LOG_ERR, "Failed to open '$sCsvFilePath' for writing...");

			return false;
		} else {
			// Copy header parameters
			if (!empty($aHeaderData)) {
				try {
					// Write the CSV data
					fputcsv($hOutputCSV, $aHeaderData, ';');
				} catch (IgnoredRowException $e) {
					// Skip this line
					Utils::Log(LOG_DEBUG, "Ignoring the line $iLineIndex. Reason: ".$e->getMessage());
				}
			}
		}
		fclose($hOutputCSV);

		return true;
	}

	/**
	 * Add a line to the CSV source file
	 *
	 * @param $aData
	 * @param $sCsvFilePath
	 * @param $aJsonToCsvData
	 * @return bool
	 * @throws Exception
	 */
	public static function AddLineToCsvSourceFile($aData, $sCsvFilePath, $aJsonToCsvData): bool
	{
		if ($sCsvFilePath == '') {
			return false;
		}

		// Set aNewLine according to the list of provided parameters and the list of parameters expected in the CSV file
		$aNewLine = [];
		foreach ($aJsonToCsvData as $sColumn) {
			$aNewLine[] = (array_key_exists($sColumn, $aData)) ? $aData[$sColumn] : '';
		}

		// Append the line to the CQV file
		try {
			$hHandle = file_put_contents($sCsvFilePath, implode(';', $aNewLine)."\n", FILE_APPEND);
		} catch (Exception $e) {
			Utils::Log(LOG_INFO, get_called_class().": Cannot add line to CSV file $sCsvFilePath");

			return false;
		}
		if ($hHandle === false) {
			Utils::Log(LOG_ERR, get_called_class().": Cannot add line to CSV file $sCsvFilePath");

			return false;
		}

		return true;
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
	 * @inheritdoc
	 */
	public function Synchronize($iMaxChunkSize = 0): bool
	{
		Utils::Log(LOG_INFO, '----------------');

		return parent::Synchronize($iMaxChunkSize);
	}

}
