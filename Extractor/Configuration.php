<?php
/**
 * Configuration.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Extractor;

use Keboola\Encryption\EncryptorInterface;
use Keboola\Google\AnalyticsBundle\Entity\AccountFactory;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Exception\ConfigurationException;
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

	/** @var EncryptorInterface */
	protected $encryptor;

	public function __construct(StorageApi $storageApi, $componentName, EncryptorInterface $encryptor)
	{
		$this->storageApi = $storageApi;
		$this->componentName = $componentName;
		$this->encryptor = $encryptor;

		$this->accountFactory = new AccountFactory($this);
		$this->accounts = $this->getAccounts();
	}

	public function getEncryptor()
	{
		return $this->encryptor;
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
	 * @param $data
	 * @return \Keboola\Google\AnalyticsBundle\Entity\Account
	 */
	public function addAccount($data)
	{
		$data['id'] = $this->getIdFromName($data['accountName']);
		$account = $this->accountFactory->get($data['id']);
		$account->fromArray($data);
		$account->save(true);
		$this->accounts[$data['id']] = $account;

		return $account;
	}

	/**
	 * Remove account
	 *
	 * @param $accountId
	 */
	public function removeAccount($accountId)
	{
		$tableId = $this->getSysBucketId() . '.' . $accountId;
		if ($this->storageApi->tableExists($tableId)) {
			$this->storageApi->dropTable($tableId);
		}

		unset($this->accounts[$accountId]);
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
		throw new ConfigurationException("SYS bucket don't exists");
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
		foreach ($this->getConfig() as $accountId => $v) {
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

//	private function getAccountId($googleId)
//	{
//		$accountId = 0;
//		/** @var Account $v */
//		foreach($this->getAccounts() as $k => $v) {
//			if ($v->getGoogleId() == $googleId) {
//				$accountId = $k;
//				break;
//			}
//			if ($k >= $accountId) {
//				$accountId = $k+1;
//			}
//		}
//
//		return $accountId;
//	}

	private function getIdFromName($name)
	{
		return strtolower(Table::removeSpecialChars($name));
	}

	public function addProfile(Account $account, array $data)
	{
		$exists = false;
		foreach ($account->getProfiles() as $profile) {
			/** @var Profile $profile */
			if ($profile->getGoogleId() == $data['googleId']) {
				$exists = true;
				break;
			}
		}

		if (!$exists) {
			$account->addProfile(new Profile($data));
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
