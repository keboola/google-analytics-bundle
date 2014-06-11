<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 15/01/14
 * Time: 17:20
 */

namespace Keboola\Google\AnalyticsBundle\Controller;


use Keboola\Encryption\EncryptorInterface;
use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Exception\ConfigurationException;
use Keboola\Google\AnalyticsBundle\Exception\ParameterMissingException;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\StorageApi\ClientException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Syrup\ComponentBundle\Controller\BaseController;
use Syrup\ComponentBundle\Exception\ApplicationException;
use Syrup\ComponentBundle\Exception\UserException;

class OauthController extends BaseController
{
	/**
	 * @var AttributeBag
	 */
	protected $sessionBag;

	/**
	 * Init OAuth session bag
	 *
	 * @return AttributeBag
	 */
	private function initSessionBag()
	{
		if (!$this->sessionBag) {
			/** @var Session $session */
			$session = $this->container->get('session');
			$bag = new AttributeBag('_ex_google_analytics');
			$bag->setName('googleanalytics');
			$session->registerBag($bag);

			$this->sessionBag = $session->getBag('googleanalytics');
		}

		return $this->sessionBag;
	}

	/**
	 * @return RestApi
	 */
	private function getGoogleApi()
	{
		return $this->container->get('google_rest_api');
	}

	public function externalAuthAction()
	{
		$request = $this->getRequest();

		// check token - if expired redirect to error page
		try {
			$sapi = new StorageApi($request->query->get('token'), null, $this->componentName);
		} catch (ClientException $e) {

			if ($e->getCode() == 401) {
				return $this->render('KeboolaGoogleAnalyticsBundle:Oauth:expired.html.twig');
			} else {
				throw $e;
			}
		}

		$request->request->set('token', $request->query->get('token'));
		$request->request->set('account', $request->query->get('account'));
		$request->request->set('referrer', $request->query->get('referrer'));

		return $this->forward('KeboolaGoogleAnalyticsBundle:Oauth:oauth');
	}

	public function externalAuthFinishAction()
	{
		return $this->render('KeboolaGoogleAnalyticsBundle:Oauth:finish.html.twig');
	}

	public function oauthAction()
	{
		if (!$this->getRequest()->request->get('account')) {
			throw new ParameterMissingException("Parameter 'account' is missing");
		}

		$bag = $this->initSessionBag();
		$googleApi = $this->getGoogleApi();

		try {
			$client = new StorageApi($this->getRequest()->request->get('token'), null, 'ex-google-analytics');

			$url = $googleApi->getAuthorizationUrl(
				$this->container->get('router')->generate('keboola_google_analytics_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL),
				'https://www.googleapis.com/auth/analytics.readonly profile email',
				'force'
			);

			$bag->set('token', $client->getTokenString());
			$bag->set('account', $this->getRequest()->request->get('account'));
			$bag->set('referrer', $this->getRequest()->request->get('referrer'));

			return new RedirectResponse($url);
		} catch (\Exception $e) {
			throw new ApplicationException(500, 'OAuth UI request error', $e);
		}
	}

	public function oauthCallbackAction()
	{
		$bag = $this->initSessionBag();

		$googleApi = $this->getGoogleApi();

		$token = $bag->get('token');
		$accountId = $bag->get('account');
		$referrer = $bag->get('referrer');

		$code = $this->get('request')->query->get('code');

		$bag->clear();

		if (empty($token) || empty($accountId)) {
			throw new UserException('Auth session expired');
		}

		if (empty($code)) {
			throw new UserException('Could not read from Google API');
		}

		try {
			$storageApi = new StorageApi($token, null, 'ex-google-analytics');

			/** @var EncryptorInterface $encryptor */
			$encryptor = $this->get('syrup.encryptor');

			$configuration = new Configuration($storageApi, 'ex-google-analytics', $encryptor);

			$tokens = $googleApi->authorize($code, $this->container->get('router')->generate('keboola_google_analytics_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL));

			$googleApi->setCredentials($tokens['access_token'], $tokens['refresh_token']);
			$userData = $googleApi->call(RestApi::USER_INFO_URL)->json();

			/** @var Account $account */
			$account = $configuration->getAccountBy('accountId', $accountId);

			if (null == $account) {
				throw new ConfigurationException("Account doesn't exist");
			}

			$account
				->setGoogleId($userData['id'])
				->setGoogleName($userData['displayName'])
				->setEmail($userData['emails'][0]['value'])
				->setAccessToken($tokens['access_token'])
				->setRefreshToken($tokens['refresh_token'])
			;
			$account->save();

			if ($referrer) {
				return new RedirectResponse($referrer);
			} else {
				return new JsonResponse(array('status' => 'ok'));
			}
		} catch (\Exception $e) {
			throw new ApplicationException('Could not save API tokens');
		}
	}

}
