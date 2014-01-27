<?php
/**
 * DataManager.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use Keboola\Csv\CsvFile;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\Result;
use Keboola\StorageApi\Table;
use Syrup\ComponentBundle\Filesystem\TempService;

class DataManager
{
	/** @var Configuration */
	protected $configuration;

	/** @var TempService */
	protected $temp;

	public function __construct(Configuration $configuration, TempService $temp)
	{
		$this->configuration = $configuration;
		$this->temp = $temp;
	}

	public function save($data, $tableName, $accountId, Profile $profile)
	{
		$response = null;

		if (!empty($data)) {
			$this->configuration->initDataBucket($accountId);
			$csvFile = $this->saveToCsv($data, $tableName, $profile);
			$response = $this->uploadCsv($csvFile, $accountId, $tableName, true);
		}

		return $response;
	}

	public function saveToCsv(array $data, $tableName, Profile $profile)
	{
		$fileName = str_replace(' ', '-', $tableName)
			. '-' . str_replace('/', '', $profile->getName())
			. "-" . microtime()
			. "-" . uniqid("", true)
			. ".csv";

		$tmpFileInfo = $this->temp->createFile($fileName);
		$csv = new CsvFile($tmpFileInfo->getPathname());

		$cnt = 0;
		/** @var Result $result */
		foreach ($data as $result) {
			$metrics = $result->getMetrics();
			$dimensions = $result->getDimensions();
			if (isset($dimensions['date'])) {
				$dimensions['date'] = date('Y-m-d', strtotime($dimensions['date']));
			}
			$row = array_merge(array_values($dimensions), array_values($metrics));
			$outRow = array();

			// CSV Header
			if ($cnt == 0) {
				$headerRow = array_merge(
					array('id', 'idProfile'),
					array_keys($dimensions),
					array_keys($metrics)
				);
				$csv->writeRow($headerRow);
			}

			$outRow = array_merge(
				array(sha1($profile->getGoogleId() . implode('', $dimensions)), $profile->getGoogleId()),
				$row
			);
			$csv->writeRow($outRow);
			$cnt++;
		}

		return $tmpFileInfo->getPathname();
	}

	public function uploadCsv($file, $accountId, $tableName, $incremental=false)
	{
		$bucketId = $this->configuration->getInBucketId($accountId);
		$tableId = $bucketId . '.' . $tableName;
		$sapi = $this->configuration->getStorageApi();

		$table = new Table($sapi, $tableId, $file, 'id', false, ',', '"', true);
		$table->save(true);
	}

}
