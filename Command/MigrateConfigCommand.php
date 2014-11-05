<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 23/01/14
 * Time: 13:19
 */

namespace Keboola\Google\AnalyticsBundle\Command;


use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Table;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Syrup\ComponentBundle\Encryption\Encryptor;

class MigrateConfigCommand extends ContainerAwareCommand
{
	protected $componentName = 'ex-google-analytics';

	protected function getConfig()
	{
		$componentsConfig = $this->getContainer()->getParameter('components');
		return $componentsConfig[$this->componentName];
	}

	protected function configure()
	{
		$this
			->setName('ex-google-analytics:config:migrate')
			->setDescription('Rename old metrics and dimensions to new')
			->addArgument('encryptionKey', InputArgument::REQUIRED)
			->addArgument('sapiToken', InputArgument::REQUIRED)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$sapiToken = $input->getArgument('sapiToken');
		$storageApi = new Client([
			'token' => $sapiToken,
			'userAgent' => $this->componentName
		]);

		$encryptionKey = $input->getArgument('encryptionKey');
		$encryptor = new Encryptor($encryptionKey);

		// Init new SYS bucket
		$configuration = new Configuration($this->componentName, $encryptor);
		$configuration->setStorageApi($storageApi);

		try {
			$configuration->create();
		} catch (\Exception $e) {
			// do nothing, bucket probably exists
		}

		// Get configuration
		$sysBucketId = 'sys.c-ex-google-analytics';

		if (!$storageApi->bucketExists($sysBucketId)) {
			throw new \Exception("No SYS bucket found");
		}

		$accounts = $configuration->getAccounts();

		/** @var Account $account */
		foreach ($accounts as $account) {

			$cfg = $this->renameItems($account->getConfiguration());

			$account->setConfiguration($cfg);
			$account->save();
		}

	}

	private function parseAttributes($attributes)
	{
		$res = array();
		foreach ($attributes as $attr) {
			$res[$attr['name']] = $attr['value'];
		}
		return $res;
	}

	private function renameItems($config)
	{
		$map = array(
			'visitors'  => 'users',
			'percentNewVisits'  => 'percentNewSessions',
			'newVisits' => 'newUsers',
			'visitsToTransaction'   => 'sessionsToTransaction',
			'visitorType'    => 'userType',
			'visitCount' => 'sessionCount',
			'daysSinceLastVisit' => 'daysSinceLastSession',
			'socialInteractionsPerVisit' => 'socialInteractionsPerSession',
			'socialInteractionNetworkActionVisit'    => 'socialInteractionNetworkActionSession',
			'visits' => 'sessions',
			'visitBounceRate'    => 'bounceRate',
			'timeOnSite' => 'sessionDuration',
			'avgTimeOnSite'  => 'avgSessionDuration',
			'visitLength'    => 'sessionDurationBucket',
			'pageviewsPerVisit'  => 'pageviewsPerSession',
			'searchVisits'   => 'searchSessions',
			'percentVisitsWithSearch'    => 'percentSessionsWithSearch',
			'goalValuePerVisit'  => 'goalValuePerSession',
			'visitsWithEvent'    => 'sessionsWithEvent',
			'eventsPerVisitWithEvent'    => 'eventsPerSessionWithEvent',
			'transactionsPerVisit'   => 'transactionsPerSession',
			'transactionRevenuePerVisit' => 'transactionRevenuePerSession',
			'visitorGender'  => 'userGender',
			'visitorAgeBracket'  => 'userAgeBracket'
		);

		foreach ($config as $tableName => $cfg) {
			if (strstr($tableName, '-new') !== false) {
				continue;
			}

			$tableRename = false;

			foreach ($cfg['metrics'] as $k => $v) {
				if (array_key_exists($v, $map)) {
					$tableRename = true;
					$config[$tableName]['metrics'][$k] = $map[$v];
				}
			}
			foreach ($cfg['dimensions'] as $k => $v) {
				if (array_key_exists($v, $map)) {
					$tableRename = true;
					$config[$tableName]['dimensions'][$k] = $map[$v];
				}
			}

			if ($tableRename) {
				$newTableName = $tableName . '-new';
				$config[$newTableName] = $config[$tableName];
				unset($config[$tableName]);
			}
		}

		return $config;
	}

}
