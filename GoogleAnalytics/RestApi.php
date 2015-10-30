<?php
/**
 * RestApi.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 26.7.13
 */

namespace Keboola\Google\AnalyticsBundle\GoogleAnalytics;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi as GoogleApi;

class RestApi
{
	/** @var GoogleApi */
	protected $api;

	protected $dataParameters;
	protected $results;

	const ACCOUNTS_URL = 'https://www.googleapis.com/analytics/v3/management/accounts';
	const DATA_URL = 'https://www.googleapis.com/analytics/v3/data/ga';

	public function __construct(GoogleApi $api)
	{
		$this->api = $api;
	}

	/**
	 * @return GoogleApi
	 */
	public function getApi()
	{
		return $this->api;
	}

	public function getDataParameters()
	{
		return $this->dataParameters;
	}

	public function getData($profileId, $dimensions, $metrics, $filter = null, $segment = null,
        $startDate = null, $endDate = null, $sort = 'ga:date', $startIndex = 1, $maxResults = 5000)
	{
		$parameters = array('ids'=>'ga:' . $profileId);

		if (null == $dimensions) {
			$parameters['dimensions'] = '';
		} else if (is_array($dimensions)) {
			$dimensionsString = '';

			foreach($dimensions as $dimension) {
				$dimensionsString .= ',ga:' . $dimension;
			}
			$parameters['dimensions'] = substr($dimensionsString,1);
		} else {
			$parameters['dimensions'] = 'ga:'.$dimensions;
		}

		if (is_array($metrics)) {
			$metricsString = '';

			foreach($metrics as $metric) {
				$metricsString .= ',ga:' . $metric;
			}
			$parameters['metrics'] = substr($metricsString,1);
		} else {
			$parameters['metrics'] = 'ga:'.$metrics;
		}

		if ($filter != null) {
			$filter = $this->processFilter($filter);

			if ($filter !== false) {
				$parameters['filters'] = $filter;
			}
		}

        if ($segment != null) {
            $parameters['segment'] = $segment;
        }

		if ($startDate == null) {
			$startDate = date('Y-m-d',strtotime('1 month ago'));
		}

		$parameters['start-date'] = $startDate;

		if($endDate == null) {
			$endDate = date('Y-m-d');
		}

		$parameters['end-date'] = $endDate;
		$parameters['start-index'] = $startIndex;
		$parameters['max-results'] = $maxResults;
		$parameters['prettyprint'] = true;
		$parameters['samplingLevel'] = 'HIGHER_PRECISION';
        $parameters['output'] = 'json';
//        $parameters['quotaUser'] = $profileId;

		$response = $this->api->request(self::DATA_URL, 'GET', array('Accept' => 'application/json'), $parameters);

		$result = $this->_mapDataResult(json_decode($response, true));

		if ($result != false) {
			return $result;
		}
		return array();
	}

	/**
	 * Request account data from Google Analytics,
	 * add web property and profiles for accounts
	 *
	 * @param Int $startIndex OPTIONAL: Start index of results
	 * @param Int $maxResults OPTIONAL: Max results returned
	 * @return array
	 */
	public function getAccounts($startIndex=1, $maxResults=1000)
	{
		$params = array(
			'start-index' => $startIndex,
			'max-results' => $maxResults
		);

		$response = $this->api->request(self::ACCOUNTS_URL, 'GET', $params);
		$result = json_decode($response, true);

		if (isset($result['items'])) {
			return $result['items'];
		}

		return array();
	}

	/**
	 * @param $accountId
	 * @param int $startIndex
	 * @param int $maxResults
	 * @return array
	 */
	public function getWebProperties($accountId, $startIndex=1, $maxResults=1000)
	{
		$params = array(
			'start-index' => $startIndex,
			'max-results' => $maxResults,
            'quotaUser' => $accountId
		);
		$url = self::ACCOUNTS_URL . '/' . $accountId . '/webproperties';
		$response = $this->api->request($url, 'GET', $params);

		$result = json_decode($response, true);

		if (isset($result['items'])) {
			return $result['items'];
		}

		return array();
	}

	/**
	 * @param $accountId
	 * @param $webpropertyId
	 * @param int $startIndex
	 * @param int $maxResults
	 * @return array
	 */
	public function getProfiles($accountId, $webpropertyId, $startIndex=1, $maxResults=5000)
	{
		$params = array(
			'start-index' => $startIndex,
			'max-results' => $maxResults,
            'quotaUser' => $accountId
		);

		$url = self::ACCOUNTS_URL . '/'	. $accountId . '/webproperties/' . $webpropertyId . '/profiles';
		$response = $this->api->request($url, 'GET', $params);

		$result = json_decode($response, true);

		if (isset($result['items'])) {
			return $result['items'];
		}

		return array();
	}

	/**
	 * Fetch all user's profiles through all his accounts
	 *
	 * @return array account -> profiles[]
	 */
	public function getAllProfiles($accountId = null)
	{
		$profiles = array();
		$webProperties = array();

		foreach($this->getAccounts() as $account) {
			if ($accountId != null && $account['id'] != $accountId) {
				continue;
			}

			$webProperties[$account['name']] = $this->getWebProperties($account['id']);
		}

		foreach($webProperties as $accountName => $wps) {
			foreach($wps as $wp) {
				try {
					$ps = $this->getProfiles($wp['accountId'], $wp['id']);
					if (!empty($ps)) {
						$profiles[$accountName][$wp['name']] = $ps;
					}
				} catch (RequestException $e) {
					if ($e->getCode() == 403) {
						// permissions changed - do nothing
					} else {
						throw $e;
					}
				}
			}
		}

		return $profiles;
	}

	/**
	 *
	 * @param array $result json decoded response
	 * @return array
	 */
	protected function _mapDataResult($result)
	{
		$paramKeys = array(
			'startIndex', 'itemsPerPage', 'totalResults'
		);
		foreach($paramKeys as $k) {
			if (isset($result[$k])) {
				$this->dataParameters[$k] = $result[$k];
			}
		}

		if (!isset($result['columnHeaders'])) {
			return array();
		}

		$metrics = array();
		$dimensions = array();

		$metricNames = array();
		$dimensionNames = array();

		$dataSet = array();

		foreach($result['columnHeaders'] as $k=>$h) {
			$name = str_replace('ga:', '', $h['name']);
			if ($h['columnType'] == 'DIMENSION') {
				$dimensionNames[$k] = $name;
			} else {
				$metricNames[$k] = $name;
			}
		}

		if (isset($result['rows'])) {
			foreach($result['rows'] as $row) {
				foreach($row as $k => $v) {
					if (isset($dimensionNames[$k])) {
						$dimensions[$dimensionNames[$k]] = $v;
					} else {
						$metrics[$metricNames[$k]] = $v;
					}
				}

				$dataSet[] = new Result($metrics, $dimensions);
			}
		}

		return $dataSet;
	}

	/**
	 * Process filter string, clean parameters and convert to Google Analytics
	 * compatible format
	 *
	 * @param String $filter
	 * @return String Compatible filter string
	 */
	protected function processFilter($filter)
	{
		$validOperators = '(!~|=~|==|!=|>|<|>=|<=|=@|!@)';

		$filter = preg_replace('/\s\s+/',' ',trim($filter)); //Clean duplicate whitespace
//		$filter = str_replace(array(',',';'),array('\,','\;'),$filter); //Escape Google Analytics reserved characters
		$filter = preg_replace('/(&&\s*|\|\|\s*|^)([a-z]+)(\s*' . $validOperators . ')/i','$1ga:$2$3',$filter); //Prefix ga: to metrics and dimensions
		$filter = preg_replace('/[\'\"]/i','',$filter); //Clear invalid quote characters
		$filter = preg_replace(array('/\s*&&\s*/','/\s*\|\|\s*/','/\s*' . $validOperators . '\s*/'),array(';',',','$1'),$filter); //Clean up operators

		if (strlen($filter)>0) {
			return $filter;
		} else {
			return false;
		}
	}
}
