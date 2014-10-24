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
use Syrup\ComponentBundle\Filesystem\Temp;

class DataManager
{
	/** @var Configuration */
	protected $configuration;

	/** @var Temp */
	protected $temp;

	public function __construct(Configuration $configuration, Temp $temp)
	{
		$this->configuration = $configuration;
		$this->temp = $temp;
	}

	public function saveToCsv(array $data, Profile $profile, CsvFile $csv, $incremental = false)
	{
		$cnt = 0;
		/** @var Result $result */
		foreach ($data as $result) {
			$metrics = $result->getMetrics();
			$dimensions = $result->getDimensions();

			// CSV Header
			if ($cnt == 0 && !$incremental) {
				$headerRow = array_merge(
					array('id', 'idProfile'),
					array_keys($dimensions),
					array_keys($metrics)
				);
				$csv->writeRow($headerRow);
			}

			if (isset($dimensions['date'])) {
				$dimensions['date'] = date('Y-m-d', strtotime($dimensions['date']));
			}
			$row = array_merge(array_values($dimensions), array_values($metrics));
			$outRow = array_merge(
				array(sha1($profile->getGoogleId() . implode('', $dimensions)), $profile->getGoogleId()),
				$row
			);
			$csv->writeRow($outRow);

			$cnt++;
		}
	}

	public function uploadCsv($file, $tableId, $incremental=false)
	{
		$sapi = $this->configuration->getStorageApi();

		$table = new Table($sapi, $tableId, $file, 'id', false, ',', '"', $incremental);
		$table->save(true);
	}

}
