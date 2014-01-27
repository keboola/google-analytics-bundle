<?php
/**
 * ConfigurationException.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Exception;


use Syrup\ComponentBundle\Exception\SyrupComponentException;

class ConfigurationException extends SyrupComponentException
{
	public function __construct($message)
	{
		parent::__construct(400, "Wrong configuration: " . $message);
	}
}
