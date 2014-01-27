<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 14:01
 */

namespace Keboola\Google\AnalyticsBundle\Controller;

use Keboola\Google\AnalyticsBundle\GoogleAnalyticsExtractor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Syrup\ComponentBundle\Controller\ApiController;

class GoogleAnalyticsController extends ApiController
{
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
		return $this->createJsonResponse($this->getComponent()->getAccount($id));
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