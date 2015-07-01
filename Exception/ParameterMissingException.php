<?php
/**
 * ParameterException.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Exception;

use Keboola\Syrup\Exception\UserException;

class ParameterMissingException extends UserException
{
	public function __construct($message)
	{
		parent::__construct($message);
	}

}
