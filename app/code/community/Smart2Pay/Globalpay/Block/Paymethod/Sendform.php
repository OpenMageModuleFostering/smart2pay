<?php
class Smart2Pay_Globalpay_Block_Paymethod_Sendform extends Mage_Core_Block_Template{
        public $form_data;
        public $message_to_hash;
        public $hash;
        
        public function __construct() {
            parent::__construct();

            /** @var Mage_Sales_Model_Order $order */
            $paymentModel = Mage::getModel('globalpay/pay');
            $order_id = Mage::getSingleton('checkout/session')->getLastOrderId(); 
            $order = Mage::getModel('sales/order');
            $order->load($order_id);
            $order_id = $order->getRealOrderId();
                        
            // FORM DATA
                $this->form_data = $paymentModel->method_config;
                $this->form_data['method_id'] = $_SESSION['globalpay_method'];
                $this->form_data['order_id'] = $order_id;
                $this->form_data['currency'] = $order->getOrderCurrency()->getCurrencyCode();
                $this->form_data['amount'] = number_format($order->getGrandTotal(), 2)*100;
                $this->form_data['customer_name'] = $order->getCustomerName();
                $this->form_data['customer_email'] = $order->getCustomerEmail();
                $this->form_data['country'] = $order->getBillingAddress()->getCountry();
                
                $messageToHash = 'MerchantID'.$this->form_data['mid']
                                    .'MerchantTransactionID'.$this->form_data['order_id']
                                    .'Amount'.$this->form_data['amount']
                                    .'Currency'.$this->form_data['currency']
                                    .'ReturnURL'.$this->form_data['return_url']
                                    .'IncludeMethodIDs'.$this->form_data['methods'];
                
                if($this->form_data['send_customer_name'])
                    $messageToHash .= "CustomerName".$this->form_data['customer_name'];
                if($this->form_data['send_customer_email'])
                    $messageToHash .= "CustomerEmail".$this->form_data['customer_email'];
                if($this->form_data['send_country'])
                    $messageToHash .= "Country".$this->form_data['country'];
                if($this->form_data['send_payment_method']){
                    $messageToHash .= "MethodID".$this->form_data['method_id'];
                }
                    
                if($this->form_data['send_product_description']){
                    if($this->form_data['product_description_ref']){
                        $messageToHash .= "Description"."Ref. no.: ".$this->form_data['order_id'];
                    }
                    else{
                        $messageToHash .= "Description".$this->form_data['product_description_custom'];
                    }
                }
                if($this->form_data['skip_payment_page']){
                    if(!in_array($this->form_data['method_id'], array(1, 20))){
                        $messageToHash .= "SkipHpp1";
                    }
                }
                if($this->form_data['redirect_in_iframe']){
                    $messageToHash .= "RedirectInIframe1";
                }
                if($this->form_data['skin_id']){
                    $messageToHash .= "SkinID".$this->form_data['skin_id'];
                }
                                
                $messageToHash .= $this->form_data['signature'];

                $this->form_data['hash'] = Mage::helper('globalpay/helper')->computeSHA256Hash($messageToHash);
                
                //
                    $this->message_to_hash = $messageToHash;
                    $this->hash = $this->form_data['hash'];
                //
        }        
}
?>