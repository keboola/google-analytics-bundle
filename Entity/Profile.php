<?php
/**
 * Profile.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle\Entity;


class Profile
{
	protected $profileId;
	protected $googleId;
	protected $name;
	protected $accountId;
	protected $accountName;
	protected $webPropertyId;
	protected $webPropertyName;

	/** @var Account */
	protected $account;

	public function __construct($data = array())
	{
		if (!empty($data)) {
			$this->fromArray($data);
		}
	}

	public function setAccount(Account $account)
	{
		$this->account = $account;
	}

	public function getAccount()
	{
		return $this->account;
	}

	public function setProfileId($id)
	{
		$this->profileId = $id;
		return $this;
	}

	public function getProfileId()
	{
		return $this->profileId;
	}

	public function setGoogleId($googleId)
	{
		$this->googleId = $googleId;
		return $this;
	}

	public function getGoogleId()
	{
		return $this->googleId;
	}

	public function setName($name)
	{
		$this->name = $name;
		return $this;
	}

	public function getName()
	{
		return $this->name;
	}

	public function setAccountId($id)
	{
		$this->accountId = $id;
		return $this;
	}

	public function getAccountId()
	{
		return $this->accountId;
	}

	public function setAccountName($name)
	{
		$this->accountName = $name;
		return $this;
	}

	public function getAccountName()
	{
		return $this->accountName;
	}

	public function setWebPropertyId($id)
	{
		$this->webPropertyId = $id;
		return $this;
	}

	public function getWebPropertyId()
	{
		return $this->webPropertyId;
	}

	public function setWebPropertyName($name)
	{
		$this->webPropertyName = $name;
		return $this;
	}

	public function getWebPropertyName()
	{
		return $this->webPropertyName;
	}

	public function fromArray(array $data)
	{
		foreach ($data as $k => $v) {
			if (property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
	}

	public function toArray()
	{
		return array(
			'profileId' => $this->profileId,
			'googleId'  => $this->googleId,
			'name'      => $this->name,
			'webPropertyId'     => $this->webPropertyId,
			'webPropertyName'   => $this->webPropertyName,
			'accountId'     => $this->accountId,
			'accountName'   => $this->accountName
		);
	}
}
