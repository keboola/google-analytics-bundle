<?php
/**
 * Account.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Entity;

use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\StorageApi\Table;

class Account extends Table
{
	protected $header = array('profileId', 'googleId', 'name', 'webPropertyId', 'accountId');

	/** @var Configuration */
	protected $configuration;

	protected $profiles = array();

	protected $accountId;

	public function __construct(Configuration $configuration, $accountId)
	{
		$this->configuration = $configuration;
		$storageApi = $this->configuration->getStorageApi();
		$sysBucket = $this->configuration->getSysBucketId();
		$this->accountId = $accountId;

		parent::__construct($storageApi, $sysBucket . '.' . $accountId);
	}

	public function getAttribute($key)
	{
		if (isset($this->attributes[$key])) {
			return $this->attributes[$key];
		}
		return null;
	}

	public function setId($id)
	{
		$this->setAccountId($id);
	}

	public function setAccountId($id)
	{
		$this->setAttribute('id', $id);
		$this->accountId = $id;

		return $this;
	}

	public function getAccountId()
	{
		return $this->accountId;
	}

	public function setGoogleId($googleId)
	{
		$this->setAttribute('googleId', $googleId);
		return $this;
	}

	public function getGoogleId()
	{
		return $this->getAttribute('googleId');
	}

	public function setEmail($email)
	{
		$this->setAttribute('email', $email);
		return $this;
	}

	public function getEmail()
	{
		return $this->getAttribute('email');
	}

	public function setAccountName($name)
	{
		$this->setAttribute('accountName', $name);
		return $this;
	}

	public function getAccountName()
	{
		return $this->getAttribute('accountName');
	}

	public function setDescription($desc)
	{
		$this->setAttribute('description', $desc);
		return $this;
	}

	public function getDescription()
	{
		return $this->getAttribute('description');
	}

	public function setGoogleName($name)
	{
		$this->setAttribute('googleName', $name);
		return $this;
	}

	public function getGoogleName()
	{
		return $this->getAttribute('googleName');
	}

	public function setAccessToken($accessToken)
	{
		try {
			$this->setAttribute('accessToken', $this->configuration->getEncryptor()->encrypt($accessToken));
		} catch (\Exception $e) {
		}
		return $this;
	}

	public function getAccessToken()
	{
		try {
			return $this->configuration->getEncryptor()->decrypt($this->getAttribute('accessToken'));
		} catch (\Exception $e) {
			return null;
		}

	}

	public function setRefreshToken($refreshToken)
	{
		try {
			$this->setAttribute('refreshToken', $this->configuration->getEncryptor()->encrypt($refreshToken));
		} catch (\Exception $e) {
		}
		return $this;
	}

	public function getRefreshToken()
	{
		try {
			return $this->configuration->getEncryptor()->decrypt($this->getAttribute('refreshToken'));
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * @return array
	 */
	public function getConfiguration()
	{
		$attributes = $this->getAttributes();
		// set default configuration if not exists
		if (!isset($attributes['configuration'])) {
			$this->setAttribute('configuration', $this->getDefaultConfiguration());
		}
		return json_decode($this->getAttribute('configuration'), true);
	}

	public function setConfiguration($config)
	{
		$this->setAttribute('configuration', json_encode($config));
	}

	public function addProfile(Profile $profile)
	{
		$profile->setAccount($this);
		$profileIds = array();
		/** @var Profile $savedProfile */
		foreach($this->getData() as $savedProfile) {
			$gid = $savedProfile['googleId'];
			if (!isset($profileIds[$gid])) {
				$profileIds[$gid] = $savedProfile['profileId'];
			}
		}

		$nextId = 0;
		if (!empty($profileIds)) {
			if (isset($profileIds[$profile->getGoogleId()])) {
				$nextId = $profileIds[$profile->getGoogleId()];
			} else {
				$nextId = max($profileIds) + 1;
			}
		}

		$profile->setProfileId($nextId);

		$this->profiles[] = $profile;
	}

	public function getProfiles()
	{
		return $this->profiles;
	}

	public function setProfilesFromArray($profiles)
	{
		foreach ($profiles as $profile) {
			$this->profiles[] = new Profile($profile);
		}
	}

	public function fromArray($array)
	{
		if (isset($array['items'])) {
			// set profiles as array to Table
			$this->setFromArray($array['items']);
			// set profiles as Objects
			$this->setProfilesFromArray($array['items']);
		}
		unset($array['items']);

		foreach($array as $k => $v) {
			$this->setAttribute($k, $v);
		}
	}

	public function toArray()
	{
		$attributes = $this->getAttributes();
		$attributes['configuration'] = $this->getConfiguration();
		$array = array_merge(
			$attributes,
			array(
				'accountId' => $this->accountId,
				'items' => $this->getData()
			)
		);
		return $array;
	}

	public function save($isAsync = false)
	{
		$profilesArray = array();
		foreach ($this->profiles as $profile) {
			/** @var Profile $profile */
			$profilesArray[] = $profile->toArray();
		}

		$this->setFromArray($profilesArray);

		parent::save(true);
	}

	public function getInBucketId()
	{
		return $this->configuration->getInBucketId($this->accountId);
	}

	public function removeProfile($profileId)
	{
		/** @var Profile $profile */
		foreach ($this->profiles as $k => $profile) {
			if ($profileId == $profile->getProfileId()) {
				unset($this->profiles[$k]);
			}
		}
	}

	public function getDefaultConfiguration()
	{
		return '{
					"visitors": {
						"metrics": ["visits","pageviews","timeOnSite"],
						"dimensions": ["visitorType","country","date"]
					},
					"trafficSources": {
						"metrics": ["visits","pageviews","timeOnSite","exits"],
						"dimensions": ["source","medium","keyword","date"]
					},
					"content": {
						"metrics": ["pageviews","uniquePageviews","timeOnPage","bounces","entrances","exits"],
						"dimensions": ["pagePath","landingPagePath","exitPagePath","date"]
					}
				}';
	}

}
