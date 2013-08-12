<?php
/**
 * GoogleAnalyticsExtractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle;

use Guzzle\Http\Message\Response;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Exception\ParameterMissingException;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\RestApi;
use Keboola\Google\AnalyticsBundle\Extractor\Extractor;
use Syrup\ComponentBundle\Component\Component;
use Keboola\StorageApi\Client;

class GoogleAnalyticsExtractor extends Component
{
	protected $_name = 'googleAnalytics';
	protected $_prefix = 'ex';

	/** @var Configuration */
	protected $configuration;

	/** @var Extractor */
	protected $extractor;

	public function __construct(Client $storageApi, $log)
	{
		$this->configuration = new Configuration($storageApi, $this->getFullName());
		parent::__construct($storageApi, $log);
	}

	protected function checkParams($required, $params)
	{
		foreach ($required as $r) {
			if (!isset($params[$r])) {
				throw new ParameterMissingException(sprintf("Parameter %s is missing.", $r));
			}
		}
	}

	public function postRun($params)
	{
		/** @var RestApi $googleAnalyticsApi */
		$googleAnalyticsApi = $this->_container->get('google_analytics_rest_api');
		$this->extractor = new Extractor($googleAnalyticsApi, $this->configuration);
		$status = $this->extractor->run();

		return array(
			'import'    => $status
		);
	}

	public function getAccount($params)
	{
		$this->checkParams(array('accountId'), $params);

		$account = $this->configuration->getAccountBy('accountId', $params['accountId'], true);

		return array(
			'account' => $account
		);
	}

	public function getAccounts($params)
	{
		$accounts = $this->configuration->getAccounts(true);

		return array(
			'accounts'  => $accounts
		);
	}

	public function postAccount($params)
	{
		$this->checkParams(
			array(
				'googleId',
				'name',
				'email',
				'accessToken',
				'refreshToken'
			),
			$params
		);

		if (!$this->configuration->exists()) {
			$this->configuration->create();
		}

		if (null != $this->configuration->getAccountBy('googleId', $params['googleId'])) {
			throw new \Exception('Account already exists');
		}

		$this->configuration->addAccount($params);
	}

	public function deleteAccount($params)
	{
		$this->checkParams(
			array(
				'accountId'
			),
			$params
		);

		$this->configuration->removeAccount($params['accountId']);
	}

	public function getProfiles($params)
	{
		$this->checkParams(array(
			'accountId'
		), $params);

		/** @var RestApi $googleAnalyticsApi */
		$googleAnalyticsApi = $this->_container->get('google_analytics_rest_api');

		/** @var Account $account */
		$account = $this->configuration->getAccountBy('accountId', $params['accountId']);

		$googleAnalyticsApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());

		$profiles = $googleAnalyticsApi->getAllProfiles();

		return array(
			'profiles' => $profiles
		);
	}

	/**
	 * @param $params
	 * @throws Exception\ParameterMissingException
	 */
	public function postProfiles($params)
	{
		$this->checkParams(array(
			'accountId',
			'profiles'
		), $params);

		if (!is_array($params['profiles'])) {
			throw new ParameterMissingException("Parameter profiles must be an array");
		}

		foreach ($params['profiles'] as $profile) {
			//@todo check params of profile
			$this->configuration->addProfile($profile, $params['accountId']);
		}
	}

	public function deleteProfiles($params)
	{
		$this->checkParams(array(
			'accountId',
			'profileId'
		), $params);

		$this->configuration->removeProfile($params['accountId'], $params['profileId']);
	}
}
