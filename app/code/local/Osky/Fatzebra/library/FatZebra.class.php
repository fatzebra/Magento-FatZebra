<?php
	/**
	* Fat Zebra PHP Gateway Library
	* 
	* Created February 2012 - Matthew Savage (matthew.savage@fatzebra.com.au)
	* Updated 20 February 2012 - Matthew Savage (matthew.savage@fatzebra.com.au)
	* Updated 19 April 2012 - Matthew Savage (matthew.savage@fatzebra.com.au)
	*  - Added refund support
	*  - Added tokenization support
	*
	* The original source for this library, including its tests can be found at
	* https://github.com/fatzebra/PHP-Library
	*
	* Please visit http://docs.fatzebra.com.au for details on the Fat Zebra API
	* or https://www.fatzebra.com.au/help for support.
	*
	* Patches, pull requests, issues, comments and suggestions always welcome.
	*
	* @package FatZebra
	*/
	namespace FatZebra;
	
	/**
	* The Fat Zebra Gateway class for interfacing with Fat Zebra
	*/
	class Gateway {
		/**
		* The URL of the Fat Zebra gateway
		*/
		public $url = "https://gateway.fatzebra.com.au";
		
		/**
		* The API version for the requests
		*/
		public $api_version = "1.0";
		
		/**
		* The gateway username
		*/
		public $username;
		
		/**
		* The gateway token
		*/
		public $token;
		
		/**
		* Indicates if test mode should be used or not
		*/
		public $test_mode = true; // This needs to be set to false for production use.

		/**
		* The connection timeout
		*/
		public $timeout = 5;

		/**
		* Creates a new instance of the Fat Zebra gateway object
		* @param string $username the username for the gateway
		* @param string $token the token for the gateway
		* @param boolean $test_mode indicates if the test mode should be used or not
		* @param string $gateway_url the URL for the Fat Zebra gateway
		* @return Gateway
		*/
		public function __construct($username, $token, $test_mode = true, $gateway_url = null) {
			if (is_null($username) || strlen($username) === 0) throw new \InvalidArgumentException("Username is required");
			$this->username = $username;
			
			if (is_null($token) || strlen($token) === 0) throw new \InvalidArgumentException("Token is required");
			$this->token = $token;

			$this->test_mode = $test_mode;
			if (!is_null($gateway_url)) {
				$this->url = $gateway_url;
			}
		}

		/**
		* Performs a purchase against the FatZebra gateway
		* @param PurchaseRequest $request the purchase request with the purchase details
		* @return \StdObject
		*/
		public function purchase($request) {
			if (isset($_SERVER['REMOTE_ADDR'])) {
				$customer_ip = $_SERVER['REMOTE_ADDR'];
			} else {
				$customer_ip = "127.0.0.1";
			}

			$payload = array_merge($request->	to_array(), array("customer_ip" => $customer_ip));
			return $this->do_request("POST", "/purchases", $payload);
		}

		/**
		* Performs a purchase against the FatZebra gateway with a tokenized credit card
		* @param string $token the card token
		* @param float $amount the purchase amount
		* @param string $reference the purchase reference
		* @param string $cvv the card verification value - optional but recommended
		* @return \StdObject
		*/
		public function token_purchase($token, $amount, $reference, $cvv = null) {
			if (isset($_SERVER['REMOTE_ADDR'])) {
				$customer_ip = $_SERVER['REMOTE_ADDR'];
			} else {
				$customer_ip = "127.0.0.1";
			}

			$payload = array(
				"customer_ip" => $customer_ip,
				"card_token" => $token,
				"cvv" => $cvv,
				"amount" => intval(round($amount * 100)),
				"reference" => $reference
				);
			return $this->do_request("POST", "/purchases", $payload);
		}

		/**
		* Performs a refund against the FatZebra gateway
		* @param string $transaction_id the original transaction ID to be refunded
		* @param float $amount the amount to be refunded
		* @param string $reference the refund reference
		* @return \StdObject
		*/
		public function refund($transaction_id, $amount, $reference) {
			if(is_null($transaction_id) || strlen($transaction_id) === 0) throw new \InvalidArgumentException("Transaction ID is required");
			if(is_null($amount) || strlen($amount) === 0) throw new \InvalidArgumentException("Amount is required");
			if(intval($amount) < 1) throw new \InvalidArgumentException("Amount is invalid - must be a positive value");
			if(is_null($reference) || strlen($reference) === 0) throw new \InvalidArgumentException("Reference is required");

			$payload = array(
				"transaction_id" => $transaction_id,
				"amount" => intval(round($amount * 100)),
				"reference" => $reference
				);

			return $this->do_request("POST", "/refunds", $payload);

		}

		/**
		* Retrieves a purchase from the FatZebra gateway
		* @param string $reference the purchase ID
		* @return \StdObject
		*/
		public function get_purchase($reference) {
			if (is_null($reference) || strlen($reference) === 0) throw new \InvalidArgumentException("Reference is required");
			return $this->do_request("GET", "/purchases/" . $reference);
		}

		/**
		* Retrieves a refund from the FatZebra gateway
		* @param string $reference the refund ID
		* @return \StdObject
		*/
		public function get_refund($reference) {
			if (is_null($reference) || strlen($reference) === 0) throw new \InvalidArgumentException("Reference is required");
			return $this->do_request("GET", "/refunds/" . $reference);
		}

		/**
		* Created a new tokenized credit card
		* @param string $card_holder the card holders name
		* @param string $card_number the card number
		* @param string $expiry_date the card expiry date (mm/yyyy format)
		* @param string $cvv the card verification value
		* @return \StdObject
		*/
		public function tokenize($card_holder, $card_number, $expiry_date, $cvv) {
			if(is_null($card_holder) || (strlen($card_holder) === 0)) throw new \InvalidArgumentException("Card Holder is a required field.");			
			if(is_null($card_number) || (strlen($card_number) === 0)) throw new \InvalidArgumentException("Card Number is a required field.");
			if(is_null($expiry_date)) throw new \InvalidArgumentException("Expiry is a required field.");	
			if(is_null($cvv)) throw new \InvalidArgumentException("CVV is a required field.");


			if (isset($_SERVER['REMOTE_ADDR'])) {
				$customer_ip = $_SERVER['REMOTE_ADDR'];
			} else {
				$customer_ip = "127.0.0.1";
			}

			$payload = array(
				"customer_ip" => $customer_ip,
				"card_holder" => $card_holder,
				"card_number" => $card_number,
				"card_expiry" => $expiry_date,
				"cvv" => $cvv
				);
			return $this->do_request("POST", "/credit_cards", $payload);
		}

		// TODO: captures, recurring


		/************** Private functions ***************/

		/**
		* Performs the request against the Fat Zebra gateway
		* @param string $method the request method ("POST" or "GET")
		* @param string $uri the request URI (e.g. /purchases, /credit_cards etc)
		* @param Array $payload the request payload (if a POST request)
		* @return \StdObject
		*/
		private function do_request($method, $uri, $payload = null) {
			$curl = curl_init();
			if(is_null($this->api_version)) {
				curl_setopt($curl, CURLOPT_URL, $this->url . $uri);
			} else {
				curl_setopt($curl, CURLOPT_URL, $this->url . "/v" . $this->api_version . $uri);	
			}

			$payload["test"] = $this->test_mode;
			
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($curl, CURLOPT_USERPWD, $this->username .":". $this->token);
			
			if ($method == "POST") {
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
			}
			
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($curl, CURLOPT_CAINFO, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cacert.pem');
			curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);

			$data = curl_exec($curl); 
			
			if (curl_errno($curl) !== 0) {
				throw new \Exception("cURL error: " . curl_error($curl));
			}
			curl_close($curl);

			$response =  json_decode($data);
			if (is_null($response)) {
				$err = json_last_error();
				if ($err == JSON_ERROR_SYNTAX) {
					throw new \Exception("JSON Syntax error. JSON attempted to parse: " . $data);
				} elseif ($err == JSON_ERROR_UTF8) {
					throw new \Exception("JSON Data invalid - Malformed UTF-8 characters. Data: " . $data);
				} else {
					throw new \Exception("JSON parse failed. Unknown error. Data:" . $data);
				}
			}

			return $response;
		}
	}


	/**
	* The Fat Zebra Purchase Request
	*/
	class PurchaseRequest {
		/**
		* The purchase amount
		*/
		private $amount = 0.00;
		
		/**
		* The purchase reference
		*/
		private $reference = "";
		
		/**
		* The card holders name
		*/
		private $card_holder = "";
		
		/**
		* The card number
		*/
		private $card_number = "";
		
		/**
		* The card expiry date
		*/
		private $expiry = "";
		
		/**
		* The Card Verification Value
		*/
		private $cvv = "";

		/**
		* Creates a new instance of the PurchaseRequest
		* @param float $amount the purchase amount
		* @param string $reference the reference for the purchase
		* @param string $card_holder the card holders name
		* @param string $card_number the card number
		* @param string $expiry the card expiry (mm/yyyy format)
		* @param string $cvv the card verification value
		* @return PurchaseRequest
		*/
		public function __construct($amount, $reference, $card_holder, $card_number, $expiry, $cvv) {
			if(is_null($amount)) throw new \InvalidArgumentException("Amount is a required field.");
			if((float)$amount < 0) throw new \InvalidArgumentException("Amount is invalid.");
			$this->amount = $amount;

			if(is_null($reference)) throw new \InvalidArgumentException("Reference is a required field.");
			if(strlen($reference) === 0) throw new \InvalidArgumentException("Reference is a required field.");
			$this->reference = $reference;

			if(is_null($card_holder) || (strlen($card_holder) === 0)) throw new \InvalidArgumentException("Card Holder is a required field.");
			$this->card_holder = $card_holder;
			
			if(is_null($card_number) || (strlen($card_number) === 0)) throw new \InvalidArgumentException("Card Number is a required field.");
			$this->card_number = $card_number;
			
			if(is_null($expiry)) throw new \InvalidArgumentException("Expiry is a required field.");
			$this->expiry = $expiry;
			
			if(is_null($cvv)) throw new \InvalidArgumentException("CVV is a required field.");
			$this->cvv = $cvv;
		}		

		/**
		* Returns the request as a hash/assoc. array
		* @return \Array
		*/
		public function to_array() {
			$amount_as_int = (int)($this->amount * 100);
			return array("card_holder" => $this->card_holder,
						 "card_number" => $this->card_number,
						 "card_expiry" => $this->expiry,
						 "cvv" => $this->cvv,
						 "reference" => $this->reference,
						 "amount" => $amount_as_int);
		}
	}

?>