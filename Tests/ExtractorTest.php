<?php

namespace Keboola\Google\AnalyticsBundle\Tests;

use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\StorageApi\Client as SapiClient;
use Keboola\StorageApi\Config\Reader;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Client;
use Syrup\ComponentBundle\Service\Encryption\Encryptor;
use Syrup\ComponentBundle\Service\Encryption\EncryptorFactory;

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

		$this->storageApi = new SapiClient($sapiToken, $sapiUrl, 'ex-google-analytics');

		/** @var EncryptorFactory $encryptorFactory */
		$encryptorFactory = $container->get('syrup.encryptor_factory');
		$encryptor = $encryptorFactory->get($this->componentName);

		$this->configuration = new Configuration($this->storageApi, $this->componentName, $encryptor);

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
		$account->setConfiguration($account->getDefaultConfiguration());

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
		$this->assertNotEmpty($account['configuration']);
	}

	protected function createProfile()
	{
		$this->createTestAccount();


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
		$response = json_decode($responseJson, true);

		var_dump($response); die;

		$this->assertEquals("ok", $response['status']);
		$this->assertArrayHasKey('account', $response);

		$account = $response['account'];
		$this->assertAccount($account);
	}

//	public function testGetAccounts()
//	{
//		$this->createTestAccount();
//
//		self::$client->request(
//			'GET', '/ex-google-analytics/accounts'
//		);
//
//		/* @var Response $responseJson */
//		$responseJson = self::$client->getResponse()->getContent();
//		$response = json_decode($responseJson, true);
//
//		$this->assertEquals("ok", $response['status']);
//		$this->assertArrayHasKey('accounts', $response);
//		$this->assertNotEmpty($response['accounts']);
//	}

	public function testDeleteAccount()
	{

	}

	/**
	 * Profiles
	 */

	public function testPostProfiles()
	{
		$this->createTestAccount();

		self::$client->request(
			'POST', '/ex-google-analytics/profiles',
			array(),
			array(),
			array(),
			json_encode(array(
				'accountId' => '0',
				'profiles'  => array(
					array(
						'profileId'     => '0',
						'googleId'      => '987654321',
						'name'          => 'testProfile',
						'webPropertyId' => 'web-property-id'
					)
				)
			))
		);

		/* @var Response $responseJson */
		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals("ok", $response['status']);

		$account = $this->configuration->getAccountBy('id', 0);
		$profiles = $account->getProfiles();

		$this->assertNotEmpty($profiles);
	}

}
