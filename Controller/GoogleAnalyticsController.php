<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 14:01
 */

namespace Keboola\Google\AnalyticsBundle\Controller;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Exception\ConfigurationException;
use Keboola\Google\AnalyticsBundle\Exception\ParameterMissingException;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\Extractor\Extractor;
use Keboola\Google\AnalyticsBundle\GoogleAnalytics\RestApi;
use Symfony\Component\HttpFoundation\Request;
use Syrup\ComponentBundle\Controller\ApiController;
use Syrup\ComponentBundle\Exception\UserException;

class GoogleAnalyticsController extends ApiController
{
	/** @var Configuration */
	protected $configuration;

	/**
	 * @return Configuration
	 */
	public function getConfiguration()
	{
		if ($this->configuration == null) {
			$this->configuration = $this->container->get('ex_google_analytics.configuration');
			$this->configuration->setStorageApi($this->storageApi);
		}
		return $this->configuration;
	}

	/**
	 * @param $required
	 * @param $params
	 * @throws ParameterMissingException
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
	 * @param Account $account
	 * @internal param $accessToken
	 * @internal param $refreshToken
	 * @return RestApi
	 */
	protected function getApi(Account $account)
	{
		/** @var RestApi $gaApi */
		$gaApi = $this->container->get('ex_google_analytics.rest_api');
		$gaApi->getApi()->setCredentials($account->getAccessToken(), $account->getRefreshToken());

		$extractor = new Extractor($gaApi, $this->logger, $this->temp);
		$extractor->setConfiguration($this->getConfiguration());
		$extractor->setCurrAccountId($account->getAccountId());

		$gaApi->getApi()->setRefreshTokenCallback([$extractor, 'refreshTokenCallback']);
		$gaApi->getApi()->setBackoffCallback403($extractor->getBackoffCallback403());

		return $gaApi;
	}


	/** Tokens
	 * @param Request $request
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function postExternalAuthLinkAction(Request $request)
	{
		$post = $this->getPostJson($request);

		if (!isset($post['account'])) {
			throw new ParameterMissingException("Parameter 'account' is required");
		}

		if (!isset($post['referrer'])) {
			throw new ParameterMissingException("Parameter 'referrer' is required");
		}

		$token = $this->getConfiguration()->createToken();

		$referrer = $post['referrer'] . '?token=' . $token['token'] .'&account=' . $post['account'];

		/** @var Account $account */
		$account = $this->getConfiguration()->getAccountBy('accountId', $post['account']);
		$account->setExternal(true);
		$account->save();

		$url = $this->generateUrl('keboola_google_analytics_external_auth', array(
			'token'     => $token['token'],
			'account'   => $post['account'],
			'referrer'  => $referrer
		), true);

		return $this->createJsonResponse(array(
			'link'  => $url
		));
	}

	/** Configs */

	public function getConfigsAction()
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

		return $this->createJsonResponse($res);
	}

	public function postConfigsAction(Request $request)
	{
		$params = $this->getPostJson($request);

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

		if (null != $this->getConfiguration()->getAccountBy('accountId', $this->configuration->getIdFromName($params['accountName']))) {
			throw new ConfigurationException('Account already exists');
		}

		$account = $this->getConfiguration()->addAccount($params);

		return $this->createJsonResponse(array(
			'id'    => $account->getAccountId(),
			'name'  => $account->getAccountName(),
			'description'   => $account->getDescription()
		));
	}

	public function deleteConfigAction($id)
	{
		$this->getConfiguration()->removeAccount($id);

		return $this->createJsonResponse(array(), 204);
	}


	/** Accounts */

	/**
	 * @param $id
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function getAccountAction($id)
	{
		$account = $this->getConfiguration()->getAccountBy('accountId', $id);

		if ($account == null) {
			throw new UserException("Account '" . $id . "' not found");
		}

		// this will get addtitional info from Google GA API if needed
		if (!isset($account->toArray()['items'][0]['accountName'])
			|| empty($account->toArray()['items'][0]['accountName']))
		{
			$profiles = $this->getApi($account)->getAllProfiles();

			foreach ($account->getData() as $row) {

				foreach ($profiles as $accName => $webProperties) {

					foreach ($webProperties as $webPropertyName => $prfs) {

						foreach ($prfs as $pr) {

							if ($pr['id'] == $row['googleId']) {
								$account->addProfile(new Profile([
									'googleId'          => $pr['id'],
									'name'              => $pr['name'],
									'webPropertyId'     => $pr['webPropertyId'],
									'webPropertyName'   => $webPropertyName,
									'accountId'         => $pr['accountId'],
									'accountName'       => $accName
								]));
							}

						}

					}

				}

			}

			$account->save();
		}

		return $this->createJsonResponse($account->toArray());
	}

	public function getAccountsAction()
	{
		return $this->createJsonResponse($this->getConfiguration()->getAccounts(true));
	}

	public function postAccountAction($id, Request $request)
	{
		$params = $this->getPostJson($request);

		$account = $this->getConfiguration()->getAccountBy('accountId', $id);
		if (null == $account) {
			throw new UserException("Account '" . $params['id'] . "' not found");
		}

		if (isset($params['googleId'])) {
			$account->setGoogleId($params['googleId']);
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

		return $this->createJsonResponse($account->toArray());
	}


	/** Profiles */

	/**
	 * @param $accountId
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function getProfilesAction($accountId)
	{
		/** @var Account $account */
		$account = $this->getConfiguration()->getAccountBy('accountId', $accountId);

		if (null == $account) {
			throw new UserException("Account '".$accountId."' not found");
		}

		try {
			$profiles = $this->getApi($account)->getAllProfiles();
		} catch (RequestException $e) {
			throw new UserException("You don't have access to resource: " . $e->getResponse()->getEffectiveUrl(), $e);
		}

		return $this->createJsonResponse($profiles);
	}

	public function postProfilesAction($accountId, Request $request)
	{
		$account = $this->getConfiguration()->getAccountBy('accountId', $accountId);

		if (null == $account) {
			throw new UserException("Account '".$accountId."' not found");
		}

		foreach ($this->getPostJson($request) as $profile) {
			$this->checkParams(array(
				'name',
				'googleId',
				'webPropertyId',
				'webPropertyName',
				'accountId',
				'accountName'
			), $profile);

			$this->getConfiguration()->addProfile($account, $profile);
		}

		return $this->createJsonResponse(['status' => 'ok']);
	}

}
