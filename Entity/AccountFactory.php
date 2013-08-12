<?php
/**
 * AccountFactory.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 2976.13
 */

namespace Keboola\Google\AnalyticsBundle\Entity;


use Keboola\Google\AnalyticsBundle\Entity\Account;
use Keboola\Google\AnalyticsBundle\Extractor\Configuration;

class AccountFactory
{
	protected $configuration;

	public function __construct(Configuration $configuration)
	{
		$this->configuration = $configuration;
	}

	public function get($accountId)
	{
		return new Account($this->configuration, $accountId);
	}

}
