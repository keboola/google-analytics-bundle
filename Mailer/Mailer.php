<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 19/01/15
 * Time: 13:58
 */

namespace Keboola\Google\AnalyticsBundle\Mailer;

use Swift_Mime_Message;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

class Mailer
{
	/** @var \Swift_Mailer */
	protected $mailer;

	/** @var EngineInterface */
	protected $templating;

	public function __construct($mailer, EngineInterface $templating)
	{
		$this->mailer = $mailer;
		$this->templating = $templating;
	}

	public function sendAuthorizationLink($userName, $link, $emailTo, $sendersMessage = "")
	{
		/** @var Swift_Mime_Message $message */
		$message = $this->mailer->createMessage()
			->setSubject('Keboola Google Analytics Extractor account authorization')
			->setFrom('support@keboola.com')
			->setTo($emailTo)
			->setBody(
				$this->templating->render(
					'KeboolaGoogleAnalyticsBundle:Mail:authorizationLink.html.twig',
					[
						'userName'          => $userName,
						'link'              => $link,
						'emailTo'           => $emailTo,
						'sendersMessage'    => $sendersMessage
					]
				),
				'text/html'
			)
		;
		$this->mailer->send($message);
	}
}
