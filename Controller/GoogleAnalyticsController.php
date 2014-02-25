<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 14:01
 */

namespace Keboola\Google\AnalyticsBundle\Controller;

use Keboola\Google\AnalyticsBundle\Exception\ParameterMissingException;
use Keboola\Google\AnalyticsBundle\GoogleAnalyticsExtractor;
use Syrup\ComponentBundle\Controller\ApiController;
use Syrup\ComponentBundle\Exception\UserException;

class GoogleAnalyticsController extends ApiController
{

	/** Tokens */

	public function postExternalAuthLinkAction()
	{
		$post = $this->getPostJson($this->getRequest());

		if (!isset($post['account'])) {
			throw new ParameterMissingException("Parameter 'account' is required");
		}

		if (!isset($post['referrer'])) {
			throw new ParameterMissingException("Parameter 'referrer' is required");
		}

		$token = $this->getComponent()->getToken();

		$referrer = $post['referrer'] . '?token=' . $token['token'] .'&account=' . $post['account'];

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
		return $this->createJsonResponse($this->getComponent()->getConfigs());
	}

	public function postConfigsAction()
	{
		$account = $this->getComponent()->postConfigs($this->getPostJson($this->getRequest()));

		return $this->createJsonResponse(array(
			'id'    => $account->getAccountId(),
			'name'  => $account->getAccountName(),
			'description'   => $account->getDescription()
		));
	}

	public function deleteConfigAction($id)
	{
		$this->getComponent()->deleteConfig($id);

		return $this->createJsonResponse(array(), 204);
	}


	/** Accounts */

	public function getAccountAction($id)
	{
		$account = $this->getComponent()->getAccount($id);

		if ($account == null) {
			throw new UserException("Account '" . $id . "' not found");
		}

		return $this->createJsonResponse($account);
	}

	public function getAccountsAction()
	{
		return $this->createJsonResponse($this->getComponent()->getAccounts());
	}

	public function postAccountAction($id)
	{
		$params = $this->getPostJson($this->getRequest());
		$params['id'] = $id;

		$account = $this->getComponent()->postAccount($params);

		return $this->createJsonResponse($account->toArray());
	}


	/** Profiles */

	public function getProfilesAction($accountId)
	{
		return $this->createJsonResponse($this->getComponent()->getProfiles($accountId));
	}

	public function postProfilesAction($accountId)
	{
		return $this->createJsonResponse(
			$this->getComponent()->postProfiles($accountId, $this->getPostJson($this->getRequest()))
		);
	}

	/**
	 * @return GoogleAnalyticsExtractor
	 */
	protected function getComponent()
	{
		return $this->component;
	}

}
