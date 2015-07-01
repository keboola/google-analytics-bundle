<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 13/06/14
 * Time: 12:20
 */

namespace Keboola\Google\AnalyticsBundle\Job;

use Keboola\Google\AnalyticsBundle\Extractor\Configuration;
use Keboola\Google\AnalyticsBundle\Extractor\Extractor;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;

class Executor extends BaseExecutor
{
	/** @var Configuration  */
	protected $configuration;

	/** @var Extractor */
	protected $extractor;

	public function __construct(Configuration $configuration, Extractor $extractor)
	{
		$this->extractor = $extractor;
		$this->configuration = $configuration;
	}

	protected function initConfiguration()
	{
		$this->configuration->setStorageApi($this->storageApi);
		return $this->configuration;
	}

	public function execute(Job $job)
	{
		$this->extractor->setConfiguration($this->initConfiguration());

		return $this->extractor->run($job->getParams());
	}
}
