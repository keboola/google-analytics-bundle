<?php
/**
 * Extractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;
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

		$this->gaApi->getApi()->setBackoffsCount(7);
		$this->gaApi->getApi()->setBackoffCallback403($this->getBackoffCallback403());
	}

	public function getBackoffCallback403()
	{
		return function ($response) {
            /** @var Response $response */
			$reason = $response->getReasonPhrase();

            if ($reason == 'insufficientPermissions'
                || $reason == 'dailyLimitExceeded'
                || $reason == 'usageLimits.userRateLimitExceededUnreg')
            {
                return false;
            }

			return true;
		};
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
			if (!isset($accounts[$options['account']])) {
				throw new UserException(sprintf("Account '%s' does not exist.", $options['account']));
			}

			$accounts = array(
				$options['account'] => $accounts[$options['account']]
			);
		}

		if (isset($options['config'])) {
			if (!isset($accounts[$options['config']])) {
				throw new UserException(sprintf("Config '%s' does not exist.", $options['config']));
			}

			$accounts = array(
				$options['config'] => $accounts[$options['config']]
			);
		}

		if ($dataset != null && !isset($options['config']) && !isset($options['account'])) {
			throw new UserException("Missing parameter 'config'");
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
					$outBucketArr = explode('.', $account->getAttribute('outputBucket'));
					$this->configuration->getStorageApi()->createBucket(
						str_replace('c-', '', $outBucketArr[1]),
						$outBucketArr[0],
						'Google Analytics data bucket'
					);
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

				try {
					$configuration = $account->getConfiguration();

					// Download just the dataset specified in request
					if ($dataset != null) {
						if (!isset($configuration[$dataset])) {
							throw new UserException(sprintf("Dataset '%s' doesn't exist", $dataset));
						}
						$configuration = [$dataset => $configuration[$dataset]];
					}

					foreach ($configuration as $tableName => $cfg) {

						// Download dataset only for specific profile
						if (isset($cfg['profile']) && $cfg['profile'] != $profile->getGoogleId()) {
							continue;
						}

						$antisampling = isset($cfg['antisampling'])?$cfg['antisampling']:false;

						$this->getData($account, $profile, $tableName, $dateFrom, $dateTo, $antisampling);

						$status[$accountId][$profile->getName()][$tableName] = 'ok';
					}

				} catch (RequestException $e) {

					if ($e->getCode() == 401) {
						throw new UserException("Expired or wrong credentials, please reauthorize.", $e);
					}

					if ($e->getCode() == 403) {
						$url = $e->getResponse()->getEffectiveUrl();

						if (strtolower($e->getResponse()->getReasonPhrase()) == 'forbidden') {
							$this->logger->warn("You don't have access to Google Analytics resource '".$url."'. Probably you don't have access to profile, or profile doesn't exists anymore.");
							continue;
						} else {
							throw new UserException("Reason: " . $e->getResponse()->getReasonPhrase(), $e);
						}
					}

					if ($e->getCode() == 400) {
						throw new UserException($e->getMessage());
					}

                    if ($e->getCode() == 503) {
                        throw new UserException("Google API error: " . $e->getMessage(), $e);
                    }

					throw new ApplicationException($e->getResponse()->getBody(), $e);
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

		$this->logger->info("Extracting ...", [
			'dimensions' => $cfg['dimensions'],
			'metrics' => $cfg['metrics'],
			'dateFrom' => $dateFrom,
			'dateTo' => $dateTo,
			'results' => count($resultSet)
		]);

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

