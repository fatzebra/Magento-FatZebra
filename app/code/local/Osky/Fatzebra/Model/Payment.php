<?php
include_once(dirname(dirname(__FILE__)) . "/library/FatZebra.class.php");
  
class Osky_Fatzebra_Model_Payment extends Mage_Payment_Model_Method_Cc
{
    protected $_code = 'fatzebra';
	
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc 				= false;

    protected $_formBlockType = 'fatzebra/form';
 
    /**
     * this method is called if we are just authorising
     * a transaction
     */
    public function authorize (Varien_Object $payment, $amount)
    {
    
    }
	
	/**
	* Assign data to info model instance
	*
	* @param   mixed $data
	* @return  Mage_Payment_Model_Method_Checkmo
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
     * this method is called if we are authorising AND
     * capturing a transaction
     */
    public function capture (Varien_Object $payment, $amount)
    {
        $this->setAmount($amount)
            ->setPayment($payment);

        $result = $this->process_payment($payment);

        if (isset($result->successful) && $result->successful) {
            if ($result->response->successful) {
                $payment->setStatus(self::STATUS_APPROVED)->setLastTransId($result->response->id);
            }
            else {
                Mage::throwException(print_r($result->response->message,true));
            }
		}
		else {
			$message = Mage::helper('fatzebra')->__('There has been an error processing your payment. '."\n$result");
            Mage::throwException($message);
        } 
		
        return $this;
    }

    /**
     * called if refunding
     */
    public function refund (Varien_Object $payment, $amount)
    {
       
        $username = Mage::getStoreConfig('payment/fatzebra/username');
        $token = Mage::getStoreConfig('payment/fatzebra/token');
        $sandbox = (boolean) Mage::getStoreConfig('payment/fatzebra/sandbox');
        $testmode = (boolean) Mage::getStoreConfig('payment/fatzebra/testmode');
        $url = $sandbox ? "https://gateway.sandbox.fatzebra.com.au" : "https://gateway.fatzebra.com.au";
        
        $gateway = new FatZebra\Gateway($username, $token, $testmode, $url);
        $result = $gateway->refund($payment->getLastTransId(), $amount, $payment->getRefundTransactionId());
        
        if (isset($result->successful) && $result->successful) {
            if ($result->response->successful) {
                $payment->setStatus(self::STATUS_SUCCESS);
                return $this;
            } else {
                Mage::throwException($result->response->message . ".");
            }
        }
        Mage::throwException(implode(", ", $result->errors) . ".");
    }

    /**
     * called if voiding a payment
     */
    public function void (Varien_Object $payment)
    {
    
    }
	
	/* 
	* Process payment through gateway
	*
	* @return void
	*/
	private function process_payment($payment) {
		$username = Mage::getStoreConfig('payment/fatzebra/username');
		$token = Mage::getStoreConfig('payment/fatzebra/token');
		$sandbox = (boolean) Mage::getStoreConfig('payment/fatzebra/sandbox');
		$testmode = (boolean) Mage::getStoreConfig('payment/fatzebra/testmode');
		$url = $sandbox ? "https://gateway.sandbox.fatzebra.com.au" : "https://gateway.fatzebra.com.au";
		
		$info = $this->getInfoInstance();
		
		try {
			$gateway = new FatZebra\Gateway($username, $token, $testmode, $url);
			$purchase_request = new FatZebra\PurchaseRequest(
				$this->getAmount(), 
				$info->getCcOwner()."-".time(), 
				str_replace('&', '&amp;', $info->getCcOwner()), 
				$info->getCcNumber(), 
				$info->getCcExpMonth() ."/". $info->getCcExpYear(), 
				$info->getCcCid()
			);

			$response = $gateway->purchase($purchase_request);
			
			return $response;
		} 
		catch(Exception $ex) {
			return $ex->getMessage();
		}
	}
}
?>