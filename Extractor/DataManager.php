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

	public function saveToCsv(array $data, $tableName, Profile $profile, CsvFile $csv)
	{
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
