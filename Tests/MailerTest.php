<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 19/01/15
 * Time: 14:18
 */

namespace Keboola\Google\AnalyticsBundle\Tests;

use Keboola\Google\AnalyticsBundle\Mailer\Mailer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\SwiftmailerBundle\DataCollector\MessageDataCollector;

class MailerTest extends WebTestCase
{
	/** @var Client */
	protected static $client;

	/** @var Mailer */
	protected $mailer;

	protected function setUp()
	{
		static::$client = static::createClient();

		$container = static::$client->getContainer();

		$sapiToken = $container->getParameter('storage_api.test.token');
		$sapiUrl = $container->getParameter('storage_api.test.url');

		static::$client->setServerParameters(array(
			'HTTP_X-StorageApi-Token' => $sapiToken
		));
	}

	public function testSendAuthLink()
	{
		// Enable the profiler for the next request (it does nothing if the profiler is not available)
		static::$client->enableProfiler();

		static::$client->request('POST', '/ex-google-analytics/send-external-link', [], [], [], json_encode([
			'url'       => 'https://syrup.keboola.com/ex-google-analytics/external-auth?token=123-456789-abcdefgh',
			'user'      => 'Test',
			'email'     => 'test@keboola.com',
			'message'   => 'test message'
		]));

		$response = json_decode(static::$client->getResponse()->getContent(), true);

		$this->assertArrayHasKey('status', $response);
		$this->assertEquals('ok', $response['status']);

		/** @var MessageDataCollector $mailCollector */
		$mailCollector = self::$client->getProfile()->getCollector('swiftmailer');

		// Check that an e-mail was sent
		$this->assertEquals(1, $mailCollector->getMessageCount());

		$collectedMessages = $mailCollector->getMessages();
		$message = $collectedMessages[0];

		// Asserting e-mail data
		$this->assertInstanceOf('Swift_Message', $message);
		$this->assertEquals('Keboola Google Analytics Extractor account authorization', $message->getSubject());
		$this->assertEquals('support@keboola.com', key($message->getFrom()));
		$this->assertEquals('test@keboola.com', key($message->getTo()));
	}
}
