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
use Symfony\Component\HttpFoundation\Request;
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
	 * @return RestApi
	 */
	private function getGoogleApi()
	{
		return $this->container->get('google_rest_api');
	}

	public function externalAuthAction(Request $request)
	{
		// check token - if expired redirect to error page
		try {
			$sapi = new StorageApi([
				'token'     => $request->query->get('token'),
				'userAgent' => $this->componentName
			]);
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

	public function oauthAction(Request $request)
	{
		if (!$request->request->get('account')) {
			throw new ParameterMissingException("Parameter 'account' is missing");
		}

		$session = $this->get('session');
		$googleApi = $this->getGoogleApi();

		$token = $request->request->get('token');

		try {
			$client = new StorageApi([
				'token'     => $token,
				'url'       => null,
				'userAgent' => 'ex-google-analytics'
			]);

			$url = $googleApi->getAuthorizationUrl(
				$this->container->get('router')->generate('keboola_google_analytics_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL),
				'https://www.googleapis.com/auth/analytics.readonly profile email',
				'force'
			);

			$session->set('token', $client->getTokenString());
			$session->set('account', $request->request->get('account'));
			$session->set('referrer', $request->request->get('referrer'));

			return new RedirectResponse($url);
		} catch (\Exception $e) {
			throw new ApplicationException('OAuth UI request error', $e);
		}
	}

	public function oauthCallbackAction()
	{
		/** @var Session $session */
		$session = $this->get('session');

		$googleApi = $this->getGoogleApi();

		$token = $session->get('token');
		$accountId = $session->get('account');
		$referrer = $session->get('referrer');

		$session->clear();

		$code = $this->get('request')->query->get('code');

		if (empty($token) || empty($accountId)) {
			throw new UserException('Auth session expired');
		}

		if (empty($code)) {
			throw new UserException('Could not read from Google API');
		}

		try {
			$storageApi = new StorageApi([
				'token'     => $token,
				'userAgent' => 'ex-google-analytics'
			]);
			$tokenData = $storageApi->verifyToken();

			/** @var EncryptorInterface $encryptor */
			$encryptor = $this->get('syrup.encryptor');

			$configuration = new Configuration('ex-google-analytics', $encryptor);
			$configuration->setStorageApi($storageApi);

			$tokens = $googleApi->authorize($code, $this->container->get('router')->generate('keboola_google_analytics_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL));

			$googleApi->setCredentials($tokens['access_token'], $tokens['refresh_token']);
			$userData = $googleApi->call(RestApi::USER_INFO_URL)->json();

			/** @var Account $account */
			$account = $configuration->getAccountBy('accountId', $accountId);

			if (null == $account) {
				throw new ConfigurationException("Account doesn't exist");
			}

			$userName = isset($userData['name'])?$userData['name']:$userData['displayName'];
			$userEmail = isset($userData['email'])?$userData['email']:$userData['emails'][0]['value'];

			$account
				->setGoogleId($userData['id'])
				->setGoogleName($userName)
				->setEmail($userEmail)
				->setAccessToken($tokens['access_token'])
				->setRefreshToken($tokens['refresh_token'])
				->setOwner($tokenData['description'])
			;

			if ($account->isExternal()) {
				if ($userEmail == $tokenData['description'] || !isset($tokenData['creatorToken'])) {
					// user generated an external link for himself or is reauthorizing himself into his config which was external before
					$account->setExternal(false);
				} else {
					$account->setOwner($tokenData['creatorToken']['description']);
				}
			}

			$account->save();

			if ($referrer) {
				return new RedirectResponse($referrer);
			} else {
				return new JsonResponse(array('status' => 'ok'));
			}
		} catch (\Exception $e) {
			throw new ApplicationException('Could not save API tokens', $e);
		}
	}

}
