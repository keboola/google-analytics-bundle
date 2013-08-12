<?php
/**
 * Configuration.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use Keboola\Google\AnalyticsBundle\Entity\AccountFactory;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\Config\Reader;
use Keboola\StorageApi\Table;

class Configuration
{
	/** @var StorageApi */
	protected $storageApi;

	protected $componentName;

	protected $sys_prefix = 'sys.c-';

	const IN_PREFIX = 'in.c-';

	protected $accounts;

	/** @var AccountFactory */
	protected $accountFactory;

	public function __construct(StorageApi $storageApi, $componentName)
	{
		$this->storageApi = $storageApi;
		$this->componentName = $componentName;
		$this->accountFactory = new AccountFactory($this);
		$this->accounts = $this->getAccounts();
	}

	public function getStorageApi()
	{
		return $this->storageApi;
	}

	public function create()
	{
		$this->storageApi->createBucket($this->componentName, 'sys', 'GoogleAnalytics Extractor');
	}

	public function exists()
	{
		return $this->storageApi->bucketExists($this->getSysBucketId());
	}

	public function initDataBucket($accountId)
	{
		if (!$this->storageApi->bucketExists(self::IN_PREFIX . $this->componentName . '-' . $accountId)) {
			$this->storageApi->createBucket($this->componentName . '-' . $accountId, 'in', 'Google Drive Account bucket');
		}
	}

	/**
	 * Add new account
	 *
	 * @param $data
	 */
	public function addAccount($data)
	{
		$accountId = $this->getAccountId($data['googleId']);
		$account = $this->accountFactory->get($accountId);
		$account->fromArray($data);
		$account->save();
		$this->accounts[$accountId] = $account;
	}

	/**
	 * Remove account
	 *
	 * @param $accountId
	 */
	public function removeAccount($accountId)
	{
		$tableId = $this->getSysBucketId() . '.account-' . $accountId;
		if ($this->storageApi->tableExists($tableId)) {
			$this->storageApi->dropTable($tableId);
		}

		unset($this->accounts[$accountId]);
	}

	public function addSheet($params)
	{
		$accountId = $params['accountId'];
		unset($params['accountId']);

		$accounts = $this->getAccounts();
		/** @var Account $account */
		$account = $accounts[$accountId];

		$exists = false;
		foreach ($account->getSheets() as $sheet) {
			/** @var Sheet $sheet */
			if ($sheet->getGoogleId() == $params['googleId'] && $sheet->getSheetId() == $params['sheetId']) {
				$exists = true;
				break;
			}
		}

		if (!$exists) {
			$account->addSheet(new Sheet($params));
			$account->save();
		}
	}

	public function getSheets($accountId)
	{
		$config = $this->getConfig();
		$account = $config['account-' . $accountId];
		$savedFiles = $account['items'];

		$result = array();

		foreach($savedFiles as $savedFile) {
			$result[$savedFile['googleId']][$savedFile['sheetId']] = $savedFile;
			$result[$savedFile['googleId']]['fileId'] = $savedFile['fileId'];
		}

		return $result;
	}

	public function getConfig()
	{
		Reader::$client = $this->storageApi;
		try {
			$config = Reader::read($this->getSysBucketId());

			if (isset($config['items'])) {
				return $config['items'];
			}
		} catch (\Exception $e) {

		}

		return array();
	}

	public function getSysBucketId()
	{
		if ($this->storageApi->bucketExists('sys.c-' . $this->componentName)) {
			return 'sys.c-' . $this->componentName;
		} else if ($this->storageApi->bucketExists('sys.' . $this->componentName)) {
			return 'sys.' . $this->componentName;
		}
		throw new \Exception("SYS bucket don't exists");
	}

	public function getInBucketId($accountId)
	{
		return self::IN_PREFIX . $this->componentName . '-' . $accountId;
	}

	/**
	 * @param bool $asArray - convert Account objects to array
	 * @return array - array of Account objects or 2D array
	 */
	public function getAccounts($asArray = false)
	{
		$accounts = array();
		foreach ($this->getConfig() as $k => $v) {
			$accountId = str_replace('account-', '', $k);
			$account = $this->accountFactory->get($accountId);
			$account->fromArray($v);
			if ($asArray) {
				$account = $account->toArray();
			}
			$accounts[$accountId] = $account;
		}

		return $accounts;
	}

	public function getAccountBy($key, $value, $asArray = false)
	{
		$accounts = $this->getAccounts();

		$method = 'get' . ucfirst($key);
		/** @var Account $account */
		foreach ($accounts as $account) {
			if ($account->$method() == $value) {
				if ($asArray) {
					return $account->toArray();
				}
				return $account;
			}
		}

		return null;
	}

	private function getAccountId($googleId)
	{
		$accountId = 0;
		/** @var Account $v */
		foreach($this->getAccounts() as $k => $v) {
			if ($v->getGoogleId() == $googleId) {
				$accountId = $k;
				break;
			}
			if ($k >= $accountId) {
				$accountId = $k+1;
			}
		}

		return $accountId;
	}

	public function addProfile($params, $accountId)
	{
		$accounts = $this->getAccounts();
		/** @var Account $account */
		$account = $accounts[$accountId];

		$exists = false;
		foreach ($account->getProfiles() as $profile) {
			/** @var Profile $profile */
			if ($profile->getProfileId() == $params['googleId']) {
				$exists = true;
				break;
			}
		}

		if (!$exists) {
			$account->addProfile(new Profile($params));
			$account->save();
		}
	}

	public function removeProfile($accountId, $profileId)
	{
		/** @var Account $account */
		$account = $this->getAccountBy('accountId', $accountId);
		$account->removeProfile($profileId);
		$account->save();
	}

}
