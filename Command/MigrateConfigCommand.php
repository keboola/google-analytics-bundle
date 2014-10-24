<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 23/01/14
 * Time: 13:19
 */

namespace Keboola\Google\AnalyticsBundle\Command;


use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Table;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Syrup\ComponentBundle\Service\Encryption\Encryptor;

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
			->setDescription('Setup new SYS buckets from old Google Analytics Extractor')
			->addArgument('encryptionKey', InputArgument::REQUIRED)
			->addArgument('sapiToken', InputArgument::REQUIRED)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$sapiToken = $input->getArgument('sapiToken');
		$storageApi = new Client($sapiToken, null, $this->componentName);

		$encryptionKey = $input->getArgument('encryptionKey');
		$encryptor = new Encryptor($encryptionKey);

		// Init new SYS bucket
		$configuration = new Configuration($storageApi, $this->componentName, $encryptor);

		try {
			$configuration->create();
		} catch (\Exception $e) {
			// do nothing, bucket probably exists
		}

		// Get configuration from old SYS bucket
		$sysBucketId = 'sys.c-ex-googleAnalytics';

		if (!$storageApi->bucketExists($sysBucketId)) {
			$sysBucketId = 'sys.ex-googleAnalytics';

			if (!$storageApi->bucketExists($sysBucketId)) {
				throw new \Exception("No old SYS bucket found");
			}
		}

		$oldAccounts = $storageApi->listTables($sysBucketId);

		foreach ($oldAccounts as $oldAccount) {

			$attributes = $this->parseAttributes($oldAccount['attributes']);

			$account = $configuration->addAccount(array(
				'id'    => $oldAccount['name'],
				'name'  => $oldAccount['name'],
				'accountName'   => $oldAccount['name'],
				'googleId'      => $attributes['googleId'],
				'googleName'    => $attributes['name'],
				'email'         => $attributes['email'],
				'accessToken'   => $encryptor->encrypt($attributes['accessToken']),
				'refreshToken'  => $encryptor->encrypt($attributes['refreshToken']),
				'configuration' => $attributes['configuration'],
				'outputBucket'  => 'in.c-ex-googleAnalytics-' . str_replace('account-', '', $oldAccount['name'])
			));

			$table = new Table($storageApi, $account->getId());
			$table->setFromString($storageApi->exportTable($oldAccount['id']), ',', '"', true);

			$table->save();
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

} 