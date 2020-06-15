<?php

	namespace phpish\shopify;
	use phpish\http;


	function install_url($shop, $api_key)
	{
		return "http://$shop/admin/api/auth?api_key=$api_key";
	}

	function is_valid_request_hmac($query_params, $shared_secret) {

		if (!isset($query_params['timestamp'])) {
			return false;
		}

		$seconds_in_a_day = 24 * 60 * 60;

		$older_than_a_day = $query_params['timestamp'] < (time() - $seconds_in_a_day);
		
		if ($older_than_a_day) return false;

		$hmac = $query_params['hmac'];
		unset($query_params['signature'], $query_params['hmac']);

		foreach ($query_params as $key=>$val) $params[] = "$key=$val";
		sort($params);

		return (hash_hmac('sha256', implode('&', $params), $shared_secret) === $hmac);

	}

	function is_valid_request($query_params, $shared_secret)
	{
		if (!isset($query_params['timestamp'])) return false;

		$seconds_in_a_day = 24 * 60 * 60;
		$older_than_a_day = $query_params['timestamp'] < (time() - $seconds_in_a_day);
		if ($older_than_a_day) return false;

		$signature = $query_params['signature'];
		unset($query_params['signature']);

		foreach ($query_params as $key=>$val) $params[] = "$key=$val";
		sort($params);

		return (md5($shared_secret.implode('', $params)) === $signature);
	}


	function authorization_url($shop, $api_key, $scopes=array(), $redirect_uri='')
	{
		$scopes = empty($scopes) ? '' : '&scope='.implode(',', $scopes);
		$redirect_uri = empty($redirect_uri) ? '' : '&redirect_uri='.urlencode($redirect_uri);
		return "https://$shop/admin/oauth/authorize?client_id=$api_key$scopes$redirect_uri";
	}


	function access_token($shop, $api_key, $shared_secret, $code)
	{
		try
		{
			$response = http\request("POST https://$shop/admin/oauth/access_token", array(), array('client_id'=>$api_key, 'client_secret'=>$shared_secret, 'code'=>$code));
		}
		catch (http\CurlException $e) { throw new CurlException($e->getMessage(), $e->getCode(), $e->getRequest()); }
		catch (http\ResponseException $e) { throw new ApiException($e->getMessage(), $e->getCode(), $e->getRequest(), $e->getResponse()); }

		return $response['access_token'];
	}

	class client {

		public $links;

		private $shop;

		private $api_key;

		private $oauth_token;

		private $private_app;

		public function __construct($shop, $api_key, $oauth_token, $private_app) {

			$this->base_uri = $this->private_app ? _private_app_base_url($shop, $api_key, $oauth_token) : "https://$shop/";
			$this->oauth_token = $oauth_token;
			$this->private_app = $private_app;
		}

		public function request( $method_uri, $query='', $payload='', &$response_headers=array(), $request_headers=array(), $curl_opts=array() )
		{

			/* Add the oAuth Token to the request if not a private application */
			if (!$this->private_app) {
				$request_headers['X-Shopify-Access-Token'] = $this->oauth_token;
			}

			$request_headers['content-type'] = 'application/json; charset=utf-8';

			/* Configure the Http client */

			$http_client = http\client($this->base_uri, $request_headers);

			/* Make the API request */

			try
			{

				$response = $http_client($method_uri, $query, $payload, $response_headers, $request_headers, $curl_opts);

			}
			catch (http\CurlException $e) {

				throw new CurlException($e->getMessage(), $e->getCode(), $e->getRequest());

			}
			catch (http\ResponseException $e) {

				throw new ApiException($e->getMessage(), $e->getCode(), $e->getRequest(), $e->getResponse());

			}

			/* Handle errors */

			if (isset($response['errors'])) {
				list($method, $uri) = explode(' ', $method_uri, 2);
				$uri      = rtrim($this->base_uri).'/'.ltrim($uri, '/');
				$headers  = $request_headers;
				$request  = compact('method', 'uri', 'query', 'headers', 'payload');
				$response = array('headers'=>$response_headers, 'body'=>$response);
				throw new ApiException($response_headers['http_status_message'].": $uri", $response_headers['http_status_code'], $request, $response);
			}

			/* Include page cursors used for pagination */

			$this->links = $this->getLinks($response_headers);

			/* Return the data we're requesting */

			return (is_array($response) and !empty($response)) ? array_shift($response) : $response;

		}


		/**
		* Get the page cursors used for pagination
		*/

		public function getLinks($responseHeaders){

			return [
				'nextLink' => $this->getLink($responseHeaders,'next'),
				'prevLink' => $this->getLink($responseHeaders,'previous')
			];

	  }

		/**
		* Get the page cursor from the response headers
		*/
	  public function getLink($responseHeaders, $type='next'){

	    if(array_key_exists('x-shopify-api-version', $responseHeaders)
	        && $responseHeaders['x-shopify-api-version'] < '2019-07'){
	        return null;
	    }

	    if(!empty($responseHeaders['link'])) {
	        if (stristr($responseHeaders['link'], '; rel="'.$type.'"') > -1) {
	            $headerLinks = explode(',', $responseHeaders['link']);
	            foreach ($headerLinks as $headerLink) {
	                if (stristr($headerLink, '; rel="'.$type.'"') === -1) {
	                    continue;
	                }

	                $pattern = '#<(.*?)>; rel="'.$type.'"#m';
	                preg_match($pattern, $headerLink, $linkResponseHeaders);
	                if ($linkResponseHeaders && isset($linkResponseHeaders[1])) {
										$pageCursor = explode('page_info=', $linkResponseHeaders[1])[1];
	                  return $pageCursor;
	                }
	            }
	        }
	    }

	    return null;

		}




	}


	function _private_app_base_url($shop, $api_key, $password)
	{
		return "https://$api_key:$password@$shop/";
	}


	function calls_made($response_headers)
	{
		return _shop_api_call_limit_param(0, $response_headers);
	}


	function call_limit($response_headers)
	{
		return _shop_api_call_limit_param(1, $response_headers);
	}


	function calls_left($response_headers)
	{
		return call_limit($response_headers) - calls_made($response_headers);
	}


	function _shop_api_call_limit_param($index, $response_headers)
	{
		$params = explode('/', $response_headers['http_x_shopify_shop_api_call_limit']);
		return (int) $params[$index];
	}


	class Exception extends http\Exception { }
	class CurlException extends Exception { }
	class ApiException extends Exception
	{
		function __construct($message, $code, $request, $response=array(), Exception $previous=null)
		{
			$response_body_json = isset($response['body']) ? $response['body'] : '';
			$response = json_decode($response_body_json, true);
			$response_error = isset($response['errors']) ? ' '.var_export($response['errors'], true) : '';
			$this->message = $message.$response_error;
			parent::__construct($this->message, $code, $request, $response, $previous);
		}
	}

?>
