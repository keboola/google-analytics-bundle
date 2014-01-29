<?php
/**
 * GoogleAnalyticsExtractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle;

use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Exception\ConfigurationException;
use Keboola\Google\AnalyticsBundle\Exception\ParameterMissingException;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\RestApi;
use Keboola\Google\AnalyticsBundle\Extractor\Extractor;
use Syrup\ComponentBundle\Component\Component;
use Syrup\ComponentBundle\Exception\UserException;

class GoogleAnalyticsExtractor extends Component
{
	protected $_name = 'google-analytics';
	protected $_prefix = 'ex';

	/** @var Configuration */
	protected $configuration;

	/** @var Extractor */
	protected $extractor;

	/**
	 * @return Configuration
	 */
	protected function getConfiguration()
	{
		if ($this->configuration == null) {
			$this->configuration = new Configuration(
				$this->_storageApi,
				$this->getFullName(),
				$this->_container->get('syrup.encryptor')
			);
		}
		return $this->configuration;
	}

	/**
	 * @param $required
	 * @param $params
	 * @throws Exception\ParameterMissingException
	 */
	protected function checkParams($required, $params)
	{
		foreach ($required as $r) {
			if (!isset($params[$r])) {
				throw new ParameterMissingException(sprintf("Parameter %s is missing.", $r));
			}
		}
	}

	/**
	 * @param Entity\Account $account
	 * @internal param $accessToken
	 * @internal param $refreshToken
	 * @return RestApi
	 */
	protected function getApi(Account $account)
	{
		/** @var RestApi $gaApi */
		$gaApi = $this->_container->get('google_analytics_rest_api');
		$gaApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());

		$this->extractor = new Extractor($gaApi, $this->getConfiguration(), $this->getTemp(), $this->_log);
		$this->extractor->setCurrAccountId($account->getAccountId());

		$gaApi->getApi()->setRefreshTokenCallback(array($this->extractor, 'refreshTokenCallback'));

		return $gaApi;
	}

	/**
	 * @param null $params
	 * @return array|mixed
	 */
	public function postRun($params)
	{
		/** @var RestApi $googleAnalyticsApi */
		$googleAnalyticsApi = $this->_container->get('google_analytics_rest_api');
		$this->extractor = new Extractor($googleAnalyticsApi, $this->getConfiguration(), $this->getTemp(), $this->_log);
		$status = $this->extractor->run($params);

		return array(
			'import'    => $status
		);
	}

	/**
	 * @return array
	 */
	public function getConfigs()
	{
		$accounts = $this->getConfiguration()->getAccounts(true);

		$res = array();
		foreach ($accounts as $account) {
			$res[] = array(
				'id'    => $account['id'],
				'name'  => $account['accountName'],
				'description'   => isset($account['description'])?$account['description']:''
			);
		}

		return $res;
	}

	/**
	 * @param $params
	 * @return Account
	 * @throws Exception\ConfigurationException
	 */
	public function postConfigs($params)
	{
		$this->checkParams(
			array(
				'name'
			),
			$params
		);

		try {
			$this->getConfiguration()->exists();
		} catch (ConfigurationException $e) {
			$this->configuration->create();
		}

		$params['accountName'] = $params['name'];
		unset($params['name']);

		if (null != $this->getConfiguration()->getAccountBy('accountName', $params['accountName'])) {
			throw new ConfigurationException('Account already exists');
		}

		return $this->getConfiguration()->addAccount($params);
	}

	/**
	 * @param $id
	 */
	public function deleteConfig($id)
	{
		$this->getConfiguration()->removeAccount($id);
	}

	/**
	 * @param $id
	 * @return array|Account|null
	 */
	public function getAccount($id)
	{
		return $this->getConfiguration()->getAccountBy('accountId', $id, true);
	}

	/**
	 * @return array
	 */
	public function getAccounts()
	{
		return $this->getConfiguration()->getAccounts(true);
	}

	/**
	 * @param $params
	 * @return array|Account|null
	 * @throws \Syrup\ComponentBundle\Exception\UserException
	 */
	public function postAccount($params)
	{
		$account = $this->getConfiguration()->getAccountBy('accountId', $params['id']);
		if (null == $account) {
			throw new UserException("Account '" . $params['id'] . "' not found");
		}

		if (isset($params['googleId'])) {
			$account->setGoogleId($params['googleId']);
		}
		if (isset($params['googleName'])) {
		}
		if (isset($params['googleName'])) {
			$account->setGoogleName($params['googleName']);
		}
		if (isset($params['email'])) {
			$account->setEmail($params['email']);
		}
		if (isset($params['accessToken'])) {
			$account->setAccessToken($params['accessToken']);
		}
		if (isset($params['refreshToken'])) {
			$account->setRefreshToken($params['refreshToken']);
		}
		if (isset($params['configuration'])) {
			$account->setConfiguration($params['configuration']);
		}

		$account->save();

		return $account;
	}

	/**
	 * @param $accountId
	 * @return array
	 * @throws \Syrup\ComponentBundle\Exception\UserException
	 */
	public function getProfiles($accountId)
	{
		/** @var Account $account */
		$account = $this->getConfiguration()->getAccountBy('accountId', $accountId);

		if (null == $account) {
			throw new UserException("Account '".$accountId."' not found");
		}

		return $this->getApi($account)->getAllProfiles();
	}

	/**
	 * @param $accountId
	 * @param $profiles
	 * @throws \Syrup\ComponentBundle\Exception\UserException
	 * @return array
	 */
	public function postProfiles($accountId, $profiles)
	{
		$account = $this->getConfiguration()->getAccountBy('accountId', $accountId);

		if (null == $account) {
			throw new UserException("Account '".$accountId."' not found");
		}

		foreach ($profiles as $profile) {
			$this->checkParams(array(
				'name',
				'googleId',
				'webPropertyId',
				'accountId' // Accounts Google ID
			), $profile);

			$this->getConfiguration()->addProfile($accountId, $profile);
		}

		return array("status" => "ok");
	}

	/**
	 * @param $params
	 */
	public function deleteProfiles($params)
	{
		$this->checkParams(array(
			'accountId',
			'profileId'
		), $params);

		$this->getConfiguration()->removeProfile($params['accountId'], $params['profileId']);
	}


}
