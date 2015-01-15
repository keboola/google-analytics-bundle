<?php

namespace Keboola\Google\AnalyticsBundle\Tests;

use Keboola\Google\AnalyticsBundle\Entity\Profile;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\StorageApi\Client as SapiClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Client;
use Syrup\ComponentBundle\Encryption\Encryptor;

class ExtractorTest extends WebTestCase
{
	/** @var SapiClient */
	protected $storageApi;

	/** @var Client */
	protected static $client;

	/** @var Configuration */
	protected $configuration;

	protected $componentName = 'ex-google-analytics';

	protected function setUp()
	{
		self::$client = static::createClient();
		$container = self::$client->getContainer();

		$sapiToken = $container->getParameter('storage_api.test.token');
		$sapiUrl = $container->getParameter('storage_api.test.url');

		self::$client->setServerParameters(array(
			'HTTP_X-StorageApi-Token' => $sapiToken
		));

		$this->storageApi = new SapiClient([
			'token' => $sapiToken,
			'url'   => $sapiUrl,
			'userAgent' => 'ex-google-analytics'
		]);

		/** @var Encryptor $encryptor */
		$encryptor = $container->get('syrup.encryptor');

		$this->configuration = new Configuration($this->componentName, $encryptor);
		$this->configuration->setStorageApi($this->storageApi);

		try {
			$this->configuration->create();
		} catch (\Exception $e) {
			// bucket exists
		}

		// Cleanup
		$sysBucketId = $this->configuration->getSysBucketId();
		$accTables = $this->storageApi->listTables($sysBucketId);
		foreach ($accTables as $table) {
			$this->storageApi->dropTable($table['id']);
		}
	}

	protected function createConfig()
	{
		$this->configuration->addAccount(array(
			'id'            => 'test',
			'accountName'   => 'Test',
			'description'   => 'Test Account created by PhpUnit test suite'
		));
	}

	protected function createAccount()
	{
		$account = $this->configuration->getAccountBy('accountId', 'test');
		$account->setGoogleId('123456');
		$account->setGoogleName('googleTestAccount');
		$account->setEmail('test@keboola.com');
		$account->setAccessToken('accessToken');
		$account->setRefreshToken('refreshToken');
		$account->setConfiguration(json_decode($account->getDefaultConfiguration(), true));

		$account->addProfile(new Profile([
			'googleId'          => '12345',
			'name'              => 'testProfile',
			'webPropertyId'     => 'test-12345-test',
			'webPropertyName'   => 'testWebProperty',
			'accountId'         => '123456',
			'accountName'       => 'googleTestAccount'
		]));

		$account->save();
	}

	protected function assertAccount($account)
	{
		$this->assertArrayHasKey('accountId', $account);
		$this->assertArrayHasKey('accountName', $account);
		$this->assertArrayHasKey('googleId', $account);
		$this->assertArrayHasKey('googleName', $account);
		$this->assertArrayHasKey('email', $account);
		$this->assertArrayHasKey('accessToken', $account);
		$this->assertArrayHasKey('refreshToken', $account);
		$this->assertArrayHasKey('configuration', $account);

		$this->assertNotEmpty($account['accountId']);
		$this->assertNotEmpty($account['accountName']);
		$this->assertNotEmpty($account['googleId']);
		$this->assertNotEmpty($account['googleName']);
		$this->assertNotEmpty($account['email']);
		$this->assertNotEmpty($account['accessToken']);
		$this->assertNotEmpty($account['refreshToken']);
	}

	/**
	 * Config
	 */

	public function testPostConfig()
	{
		self::$client->request(
			'POST', '/ex-google-analytics/configs',
			array(),
			array(),
			array(),
			json_encode(array(
				'id'            => 'test',
				'name'          => 'Test',
				'description'   => 'Test Account created by PhpUnit test suite'
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);
		$this->assertEquals('Test', $response['name']);
	}

	public function testGetConfig()
	{
		$this->createConfig();

		self::$client->request('GET', '/ex-google-analytics/configs');

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response[0]['id']);
		$this->assertEquals('Test', $response[0]['name']);
	}

	public function testDeleteConfig()
	{
		$this->createConfig();

		self::$client->request('DELETE', '/ex-google-analytics/configs/test');

		/* @var Response $response */
		$response = self::$client->getResponse();

		$accounts = $this->configuration->getAccounts(true);

		$this->assertEquals(204, $response->getStatusCode());
		$this->assertEmpty($accounts);
	}

	/**
	 * Accounts
	 */

	public function testPostAccount()
	{
		$this->createConfig();

		self::$client->request(
			'POST', '/ex-google-analytics/account/test',
			array(),
			array(),
			array(),
			json_encode(array(
				'googleId'  => '123456',
				'googleName'      => 'googleTestAccount',
				'email'     => 'test@keboola.com',
				'accessToken'   => 'accessToken',
				'refreshToken'  => 'refreshToken'
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);

		$accounts = $this->configuration->getAccounts(true);
		$account = $accounts['test'];

		$this->assertAccount($account);
	}

	public function testGetAccount()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request('GET', '/ex-google-analytics/account/test');

		/* @var Response $responseJson */
		$responseJson = self::$client->getResponse()->getContent();
		$account = json_decode($responseJson, true);

		$this->assertAccount($account);
	}

	public function testGetAccounts()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request(
			'GET', '/ex-google-analytics/accounts'
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertNotEmpty($response);
	}

	/**
	 * Profiles
	 */

	public function testPostProfiles()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request(
			'POST', '/ex-google-analytics/profiles/test',
			array(),
			array(),
			array(),
			json_encode(array(
				array(
					'googleId'      => '987654321',
					'accountId'     => '567890',
					'accountName'   => 'accountTest',
					'name'          => 'testProfile',
					'webPropertyId' => 'web-property-id',
					'webPropertyName' => 'web-property-name'
				)
			))
		);

		/* @var Response $responseJson */
		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals("ok", $response['status']);

		$account = $this->configuration->getAccountBy('accountId', 'test');
		$profiles = $account->getProfiles();

		$this->assertNotEmpty($profiles);
		$this->assertCount(1, $profiles);

		/** @var Profile $profile */
		$profile = $profiles[0];
		$this->assertEquals('987654321', $profile->getGoogleId());
		$this->assertEquals('testProfile', $profile->getName());
	}

	public function testGetProfiles()
	{
		// @todo
	}

	/**
	 * External
	 */

	public function testExternalLink()
	{
		$this->createConfig();
		$this->createAccount();

		$referrerUrl = self::$client
			->getContainer()
			->get('router')
			->generate('keboola_google_analytics_external_auth_finish', array(), true);

		self::$client->followRedirects();
		self::$client->request(
			'POST', '/ex-google-analytics/external-link',
			array(),
			array(),
			array(),
			json_encode(array(
				'account'   => 'test',
				'referrer'  => $referrerUrl
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertArrayHasKey('link', $response);
		$this->assertNotEmpty($response['link']);
	}

	/**
	 * Run
	 */

	public function testRun()
	{
		//@TODO
	}
}
