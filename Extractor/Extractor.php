<?php
/**
 * Extractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Keboola\Csv\CsvFile;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Exception\ConfigurationException;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\RestApi;
use Monolog\Logger;
use Syrup\ComponentBundle\Exception\ApplicationException;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Filesystem\Temp;

class Extractor
{
	/** @var RestApi */
	protected $gaApi;

	/** @var Configuration */
	protected $configuration;

	/** @var DataManager */
	protected $dataManager;

	protected $currAccountId;

	/** @var Temp */
	protected $temp;

	/** @var Logger */
	protected $logger;

	public function __construct(RestApi $gaApi, Logger $logger, Temp $temp)
	{
		$this->gaApi = $gaApi;
		$this->temp = $temp;
		$this->logger = $logger;
	}

	public function setConfiguration($configuration)
	{
		$this->configuration = $configuration;
	}

	public function getDataManager()
	{
		if (null == $this->configuration) {
			throw new ApplicationException('Configuration must be set before DataManager can be created.');
		}

		if (null == $this->dataManager) {
			$this->dataManager = new DataManager($this->configuration, $this->temp);
		}

		return $this->dataManager;
	}

	public function run($options = null)
	{
		$accounts = $this->configuration->getAccounts();
		$status = array();

		$dateFrom = isset($options['since'])?date('Y-m-d', strtotime($options['since'])):date('Y-m-d', strtotime('-4 days'));
		$dateTo = isset($options['until'])?date('Y-m-d', strtotime($options['until'])):date('Y-m-d', strtotime('-1 day'));
		$dataset = isset($options['dataset'])?$options['dataset']:null;

		if (isset($options['account'])) {
			if (isset($accounts[$options['account']])) {
				$accounts = array(
					$options['account'] => $accounts[$options['account']]
				);
			}
		}

		if (isset($options['config'])) {
			if (isset($accounts[$options['config']])) {
				$accounts = array(
					$options['config'] => $accounts[$options['config']]
				);
			}
		}

		/** @var Account $account */
		foreach ($accounts as $accountId => $account) {

			// check if account has been authorized
			if (null == $account->getAttribute('accessToken')) {
				continue;
			}

			$this->currAccountId = $accountId;

			if (null == $account->getAttribute('outputBucket')) {
				$this->configuration->initDataBucket($account->getAccountId());
			} else {
				if (!$this->configuration->getStorageApi()->bucketExists($account->getAttribute('outputBucket'))) {
					throw new ConfigurationException("Output bucket '".$account->getAttribute('outputBucket')."' doesn't exist.");
				}
			}

			$this->gaApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());
			$this->gaApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));

			$tmpFileInfo = $this->temp->createFile("profiles-" . $accountId . "-" . microtime() . ".csv");
			$profilesCsv = new CsvFile($tmpFileInfo->getPathname());
			$profilesCsv->writeRow(array('id', 'name'));

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
					} catch (ClientErrorResponseException $e) {
						if ($e->getCode() == 403) {
							throw new UserException("You don't have access to resource. Check you access permissions.", $e);
						}

						throw $e;
					}

				}
			}

			$this->getDataManager()->uploadCsv($profilesCsv->getPathname(), $this->getOutputTable($account, 'profiles'));
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

		if (empty($resultSet)) {
			return;
		}

		$csv = $this->getOutputCsv($tableName, $profile);
		$this->getDataManager()->saveToCsv($resultSet, $profile, $csv);

		// Paging
		$params = $this->gaApi->getDataParameters();
		if ($params['totalResults'] > $params['itemsPerPage']) {

			$pages = ceil($params['totalResults'] / $params['itemsPerPage']);
			for ($i=1; $i<$pages; $i++) {
				$start = $i*$params['itemsPerPage']+1;
				$end = $start+$params['itemsPerPage']-1;

				$resultSet = $this->gaApi->getData($profile->getGoogleId(), $cfg['dimensions'], $cfg['metrics'],
					$filters, $dateFrom, $dateTo, 'ga:date', $start, $params['itemsPerPage']);

				$this->getDataManager()->saveToCsv($resultSet, $profile, $csv, true);
			}
		}

		$this->getDataManager()->uploadCsv($csv->getPathname(), $this->getOutputTable($account, $tableName), true);
	}

	public function setCurrAccountId($id)
	{
		$this->currAccountId = $id;
	}

	public function getCurrAccountId()
	{
		return $this->currAccountId;
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->configuration->getAccountBy('accountId', $this->currAccountId);
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
	}

	protected function getOutputCsv($tableName, Profile $profile)
	{
		$fileName = str_replace(' ', '-', $tableName)
			. '-' . str_replace('/', '', $profile->getGoogleId())
			. "-" . microtime()
			. "-" . uniqid("", true)
			. ".csv";

		$tmpFileInfo = $this->temp->createFile($fileName);

		return new CsvFile($tmpFileInfo->getPathname());
	}

	protected function getOutputTable(Account $account, $tableName)
	{
		$outputBucket = $this->configuration->getInBucketId($account->getAccountId());
		if ($account->getAttribute('outputBucket') != null) {
			$outputBucket = $account->getAttribute('outputBucket');
		}
		return $outputBucket . '.' . $tableName;
	}
}
