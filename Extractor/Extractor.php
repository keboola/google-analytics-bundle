<?php
/**
 * Extractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use Keboola\Csv\CsvFile;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\RestApi;

class Extractor
{
	/** @var RestApi */
	protected $gaApi;

	/** @var Configuration */
	protected $configuration;

	/** @var DataManager */
	protected $dataManager;

	public function __construct(RestApi $gaApi, $configuration)
	{
		$this->gaApi = $gaApi;
		$this->configuration = $configuration;
		$this->dataManager = new DataManager($configuration);
	}

	public function run($options = null)
	{
		$accounts = $this->configuration->getAccounts();
		$status = array();

		$dateFrom = isset($options['since'])?date('Y-m-d', $options['since']):date('Y-m-d', strtotime('-4 days'));
		$dateTo = isset($options['until'])?date('Y-m-d', $options['until']):date('Y-m-d', strtotime('-1 day'));
		$dataset = isset($options['dataset'])?$options['dataset']:null;

		/** @var Account $account */
		foreach ($accounts as $accountId => $account) {
			$this->gaApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());

			$profilesCsv = new CsvFile(ROOT_PATH . "/app/tmp/profiles-" . microtime() . ".csv");

			/** @var Profile $profile */
			foreach ($account->getProfiles() as $profile) {

				$profilesCsv->writeRow(array($profile->getGoogleId(), $profile->getName()));

				foreach ($account->getConfiguration() as $tableName => $cfg) {

					// Download just the dataset specified in request
					if ($dataset != null && $dataset != $tableName) {
						continue;
					}

					// Download dataset only for specific profile
					if (isset($cfg['profile']) && $cfg['profile'] != $profile->getGoogleId()) {
						continue;
					}

					$antisampling = isset($cfg['antisampling'])?$cfg['antisampling']:false;

					$status[$accountId][$profile->getName()][$tableName] = 'ok';
					try {
						$this->getData($account, $profile, $tableName, $dateFrom, $dateTo, $antisampling);
					} catch (\Exception $e) {
						$status[$accountId][$profile->getName()][$tableName] = array('error' => $e->getMessage());
					}
				}
			}


		}

		return $status;
	}

	protected function getData(Account $account, Profile $profile, $tableName, $dateFrom, $dateTo, $antisampling = false)
	{
		// Optimize sampling if configured
		if ($antisampling) {
			$dt = new \DateTime($dateFrom);
			$dtTo = new \DateTime($dateTo);
			$dtTo->modify("+1 day");

			while ($dt->format('Y-m-d') != $dtTo->format('Y-m-d')) {
				$this->extract($account, $profile, $tableName, $dt->format('Y-m-d'), $dt->format('Y-m-d'));
				$dt->modify("+1 day");
			}
		} else {
			$this->extract($account, $profile, $tableName, $dateFrom, $dateTo);
		}
	}

	protected function extract(Account $account, Profile $profile, $tableName, $dateFrom, $dateTo)
	{
		$config = $account->getConfiguration();
		$cfg = $config[$tableName];

		$filters = (isset($cfg['filters'][0]))?$cfg['filters'][0]:null;

		$resultSet = $this->gaApi->getData($profile->getGoogleId(), $cfg['dimensions'], $cfg['metrics'], $filters, $dateFrom, $dateTo);
		$this->dataManager->save($resultSet, $tableName, $account->getAccountId(), $profile);

		// Paging
		$params = $this->gaApi->getDataParameters();
		if ($params['totalResults'] > $params['itemsPerPage']) {

			$pages = ceil($params['totalResults'] / $params['itemsPerPage']);
			for ($i=1; $i<$pages; $i++) {
				$start = $i*$params['itemsPerPage']+1;
				$end = $start+$params['itemsPerPage']-1;

				$resultSet = $this->gaApi->getData($profile->getGoogleId(), $cfg['dimensions'], $cfg['metrics'],
					$filters, $dateFrom, $dateTo, 'ga:date', $start, $params['itemsPerPage']);
				$this->dataManager->save($resultSet, $tableName, $account->getAccountId(), $profile);
			}
		}
	}

}
