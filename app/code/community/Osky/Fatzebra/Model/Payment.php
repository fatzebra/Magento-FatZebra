<?php
class Osky_Fatzebra_Model_Payment extends Mage_Payment_Model_Method_Cc
{
  const VERSION = "2.0.5";
  protected $_code = 'fatzebra';

  protected $_isGateway               = true;
  protected $_canAuthorize            = false;
  protected $_canCapture              = true;
  protected $_canCapturePartial       = false;
  protected $_canRefund               = true;
  protected $_canVoid                 = false;
  protected $_canUseInternal          = true;
  protected $_canUseCheckout          = true;
  protected $_canUseForMultishipping  = true;
  protected $_canSaveCc               = false;

  protected $_formBlockType = 'fatzebra/form';


  /**
  * Assign data to info model instance
  *
  * @param   mixed $data
  * @return Osky_Fatzebra_Model_Payment
  */
  public function assignData($data)
  {
    parent::assignData($data);
  
    if (!($data instanceof Varien_Object)) {
      $data = new Varien_Object($data);
    }
    $info = $this->getInfoInstance();
    $info->setCcNumber($data->getCcNumber());
  
    return $this;
  }

  /**
  * Performs a capture (full purchase transaction)
  * @param $payment the payment object to process
  * @param $amount the amount to be charged, as a decimal
  *
  * @return Osky_Fatzebra_Model_Payment
  */
  public function capture (Varien_Object $payment, $amount)
  {
    $this->setAmount($amount)->setPayment($payment);

    $result = $this->process_payment($payment);

    if (isset($result->successful) && $result->successful) {
      if ($result->response->successful) {
        $payment->setStatus(self::STATUS_APPROVED);
        $payment->setLastTransId($result->response->id);
        $payment->setTransactionId($result->response->id);

        $order   = $payment->getOrder();
        $invoice = $order->getInvoiceCollection()->getFirstItem();

        if($invoice && !$invoice->getEmailSent()) {
          $invoice->pay(); // Mark the invoice as paid
          $invoice->addComment("Payment made by Credit Card. Reference " . $result->response->id . ", Masked number: " . $result->response->card_number, false, true);
          $invoice->sendEmail();
          $invoice->save();
        }
      }
      else {
        Mage::throwException(Mage::helper('fatzebra')->__("Unable to process payment: %s", $result->response->message));
      }
    }
    else {
      $message = Mage::helper('fatzebra')->__('There has been an error processing your payment. %s', implode(", ", $result->errors));
      Mage::throwException($message);
    } 
  
    return $this;
  }

  /**
  * Refunds a payment
  *
  * @param $payment the payment object
  * @param $amount the amount to be refunded, as a decimal
  *
  * @return Osky_Fatzebra_Model_Payment
  */ 
  public function refund (Varien_Object $payment, $amount)
  {
    $result = $this->process_refund($payment, $amount);
    
    if (isset($result->successful) && $result->successful) {
      if ($result->response->successful) {
        $payment->setStatus(self::STATUS_SUCCESS);
        return $this;
      } else {
        Mage::throwException(Mage::helper('fatzebra')->__("Error processing refund: %s", $result->response->message));
      }
    }
    Mage::throwException(Mage::helper('fatzebra')->__("Error processing refund: %s", implode(", ", $result->errors)));
  }

  /**
  * Builds the refund payload and submits
  *
  * @param $payment the object to reference
  * @param $amount the refund amount, as a decimal
  *
  * @return StdObject response
  */
  private function process_refund($payment, $amount)
  {
    $payload = array("transaction_id" => $payment->getLastTransId(),
                     "amount"         => (int)($amount * 100),
                     "reference"      => $payment->getRefundTransactionId());

    return $this->_post("refunds", $payload);
  }

  /**
  * Builds the refund payload and submits
  *
  * @param $payment the object to reference
  *
  * @return StdObject response
  */
  private function process_payment($payment)
  {
    $info          = $this->getInfoInstance();
    $order         = $payment->getOrder();
    $billing_addr  = $order->getBillingAddress();
    $shipping_addr = $order->getShippingAddress();
    $reference     = $order->getIncrementId();

    $payload = array("amount"           => (int)($this->getAmount() * 100),
                     "currency"         => Mage::app()->getStore()->getBaseCurrencyCode(),
                     "reference"        => $order->getIncrementId(),
                     "card_holder"      => str_replace('&', '&amp;', $info->getCcOwner()), 
                     "card_number"      => $info->getCcNumber(), 
                     "card_expiry"      => $info->getCcExpMonth() ."/". $info->getCcExpYear(), 
                     "cvv"              => $info->getCcCid(),
                     "customer_ip"      => $_SERVER['REMOTE_ADDR'],
                     // Fraud tracking information
                     "forwarded_for"    => $_SERVER['HTTP_X_FORWARDED_FOR'],
                     "phone"            => $billing_addr->getTelephone(),
                     "email"            => $order->getCustomerEmail(),
                     "billing_address"  => array("street"   => $billing_addr->getStreetFull(),
                                                 "city"     => $billing_addr->getCity(),
                                                 "state"    => $billing_addr->getRegion(),
                                                 "postcode" => $billing_addr->getPostcode(),
                                                 "country"  => $billing_addr->getCountry()
                                                  ),
                     "shipping_address" => array("street"   => $shipping_addr->getStreetFull(),
                                                 "city"     => $shipping_addr->getCity(),
                                                 "state"    => $shipping_addr->getRegion(),
                                                 "postcode" => $shipping_addr->getPostcode(),
                                                 "country"  => $shipping_addr->getCountry()
                                                 )
                    );

    try {
      $this->fzlog("{$reference}: Submitting payment for {$payload["reference"]}.");
      $response = $this->_post("purchases", $payload);
    } catch(Exception $e) {
      $exMessage = $e->getMessage();
      $this->fzlog("{$reference}: Payment request failed ({$exMessage}) - querying payment from Fat Zebra", Zend_Log::WARN);
      try {
        $response = $this->_fetch("purchases", $reference);
      } catch(Exception $e) {
        $exMessage = $e->getMessage();
        $this->fzlog("{$reference}: Payment request failed after query ({$exMessage}).", Zend_Log::ERR);   
      }
    }

    if ($response->successful) {
      $success = (bool)$response->successful && (bool)$response->result->successful;
      $txn_result = $response->response->message;
      $fz_id = $response->response->id;
      $this->fzlog("{$reference}: Payment outcome: Successful, Result - {$txn_result}, Fat Zebra ID - {$fz_id}.");
    }
  
    if (!empty($response->errors)) {
      foreach($response->errors as $err) {
        $this->fzlog("{$reference}: Error - {$err}", Zend_Log::ERR);
      }
    }
    return $response;
  }

  /**
  * Fetch the URL from the Fat Zebra Gateway
  * @param $path the URI to fetch the data from (e.g. purchases, refunds etc)
  * @param $payload string ID for the transaction
  *
  * @return StdObject response
  */
  private function _fetch($path, $id) 
  {
    return $this->_request($path, Zend_Http_Client::GET);
  }

  /**
  * Posts the request to the Fat Zebra gateway
  * @param $path the URI to post the data to (e.g. purchases, refunds etc)
  * @param $payload assoc. array for the payload
  *
  * @return StdObject response
  */
  private function _post($path, $payload) 
  {
    return $this->_request($path, Zend_Http_Client::POST, $payload);
  }

  private function _request($path, $method = Zend_Http_Client::GET, $payload = null)
  {
    $username = Mage::getStoreConfig('payment/fatzebra/username');
    $token    = Mage::getStoreConfig('payment/fatzebra/token');
    $sandbox  = (boolean) Mage::getStoreConfig('payment/fatzebra/sandbox');
    $testmode = (boolean) Mage::getStoreConfig('payment/fatzebra/testmode');
    
    $url = $sandbox ? "https://gateway.sandbox.fatzebra.com.au" : "https://gateway.fatzebra.com.au";

    if ($testmode) $payload["test"] = true;
    $uri = $url . "/v1.0/" . $path . "/" . urlencode($id);

    $client = new Varien_Http_Client();
    $client->setUri($uri);
    $client->setAuth($username, $token);
    $client->setMethod($method);
    if ($method == Zend_Http_Client::POST) {
      $client->setRawData(json_encode($payload));
    }
    $client->setConfig(array('maxredirects' => 0,
                             'timeout'      => 30,
                             'useragent'    => 'User-Agent: Fat Zebra Magento Library ' . self::VERSION
                            ));
    
    try {
      $this->fzlog("{$id}: Fetching purchase.");
      $response = $client->request();
    } catch (Exception $e) {
      $exMessage = $e->getMessage();
      $this->fzlog("{$id}: Fetching purchase failed: {$exMessage}", Zend_Log::ERR);
      Mage::logException($e);
      Mage::throwException(Mage::helper('fatzebra')->__("Gateway Error: %s", $e->getMessage()));
    }

    $responseBody = $response->getRawBody();
    $response = json_decode($responseBody);

    if (is_null($response)) {
      $response = array("successful" => false,
                        "result" => null);
      $err = json_last_error();
      if ($err == JSON_ERROR_SYNTAX) {
        $result["errors"] = array("JSON Syntax error. JSON attempted to parse: " . $data);
      } elseif ($err == JSON_ERROR_UTF8) {
        $result["errors"] = array("JSON Data invalid - Malformed UTF-8 characters. Data: " . $data);
      } else {
        $result["errors"] = array("JSON parse failed. Unknown error. Data:" . $data);
      }
    }
    return $response;
  }

  /**
  * Log a message to the gateway log
  * @param $message the message to be logged
  * @param $level the log level, from Zend_Log::* (http://framework.zend.com/manual/1.12/en/zend.log.overview.html#zend.log.overview.builtin-priorities)
  */
  function fzlog($message, $level = Zend_Log::INFO)
  {
    Mage::log($message, $level, "FatZebra_gateway.log");
  }
}
