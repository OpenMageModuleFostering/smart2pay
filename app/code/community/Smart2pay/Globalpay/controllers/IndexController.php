<?php
class Smart2pay_Globalpay_IndexController extends Mage_Core_Controller_Front_Action{

    const XML_PATH_EMAIL_PAYMENT_CONFIRMATION = 'payment/globalpay/payment_confirmation_template';

    /**
     * Loads s2p in iFrame
     * If !isset($_SESSION['s2p_handle_payment']) redirect to cart/checkout
     */
    public function indexAction()
    {
        // check if there is an submited order and a 
        if(isset($_SESSION['s2p_handle_payment'])){ 
            unset($_SESSION['s2p_handle_payment']);
            $this->loadLayout();
            $this->renderLayout();
        }
        else{
            $this->_redirectUrl(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK) . 'checkout/cart/');
        }        
    }    

    /** 
     * Process s2p response
     * Expected response content: {"NotificationType":"payment","MethodID":"27","PaymentID":"18899","MerchantTransactionID":"926927","StatusID":"2","Amount":"100","Currency":"EUR","Hash":"fb9810cb1ac334092aa1f3033be20127676244b343f1ef2ffb447b9a8ced04ba"}
     */
    public function handleResponseAction()
    {
        Mage::getModel('globalpay/logger')->write('>>> START HANDLE RESPONSE :::', 'info');

        // get assets
            /* @var Mage_Sales_Model_Order $order
             * @var Smart2Pay_Globalpay_Model_Pay $payMethod
             */
            $s2pHelper = Mage::helper('globalpay/helper');
            $payMethod = Mage::getModel('globalpay/pay');
            $order = Mage::getModel('sales/order');

        try {
            parse_str(file_get_contents("php://input"), $response);
            $recomposedHashString = "NotificationType" . $response['NotificationType'] . "MethodID".$response['MethodID']."PaymentID".$response['PaymentID']."MerchantTransactionID".$response['MerchantTransactionID']."StatusID".$response['StatusID']."Amount".$response['Amount']."Currency".$response['Currency'].$payMethod->method_config['signature'];

            Mage::getModel('globalpay/logger')->write('StatusID = ' . $response['StatusID'], 'info');
            Mage::getModel('globalpay/logger')->write('MerchantTransactionID = ' . $response['MerchantTransactionID'], 'info');

            // Message is intact
            if($s2pHelper->computeSHA256Hash($recomposedHashString) == $response['Hash']){

                Mage::getModel('globalpay/logger')->write('Hashes match', 'info');

                $order->loadByIncrementId($response['MerchantTransactionID']);

                /**
                 * Check status ID
                 */
                switch($response['StatusID']){
                    // Status = success
                    case "2":
                        $order->addStatusHistoryComment('Smart2Pay :: order has been paid.', $payMethod->method_config['order_status_on_2']);
                        if ($payMethod->method_config['auto_invoice']) {
                            // Create and pay Order Invoice
                            if($order->canInvoice()) {
                                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                                $invoice->register();
                                $transactionSave = Mage::getModel('core/resource_transaction')
                                    ->addObject($invoice)
                                    ->addObject($invoice->getOrder());
                                $transactionSave->save();
                                $order->addStatusHistoryComment('Smart2Pay :: order has been automatically invoiced.', $payMethod->method_config['order_status_on_2']);
                            } else {
                                Mage::getModel('globalpay/logger')->write('Order can not be invoiced', 'warning');
                            }
                        }
                        if ($payMethod->method_config['auto_ship']) {
                            if ($order->canShip()) {
                                $itemQty =  $order->getItemsCollection()->count();
                                $shipment = Mage::getModel('sales/service_order', $order)->prepareShipment($itemQty);
                                $shipment = new Mage_Sales_Model_Order_Shipment_Api();
                                $shipmentId = $shipment->create($order->getIncrementId());
                                $order->addStatusHistoryComment('Smart2Pay :: order has been automatically shipped.', $payMethod->method_config['order_status_on_2']);
                            } else {
                                Mage::getModel('globalpay/logger')->write('Order can not be shipped', 'warning');
                            }
                        }
                        if ($payMethod->method_config['notify_customer']) {
                            // Inform customer
                            $this->informCustomer($order, $response['Amount'], $response['Currency']);
                        }
                        break;
                    // Status = canceled
                    case 3:
                        $order->addStatusHistoryComment('Smart2Pay :: order payment has been canceled.', $payMethod->method_config['order_status_on_3']);
                        if ($order->canCancel()) {
                            $order->cancel();
                        } else {
                            Mage::getModel('globalpay/logger')->write('Can not cancel the order', 'warning');
                        }
                        break;
                    // Status = failed
                    case 4:
                        $order->addStatusHistoryComment('Smart2Pay :: order payment has failed.', $payMethod->method_config['order_status_on_4']);
                        break;
                    // Status = expired
                    case 5:
                        $order->addStatusHistoryComment('Smart2Pay :: order payment has expired.', $payMethod->method_config['order_status_on_5']);
                        break;

                    default:
                        $order->addStatusHistoryComment('Smart2Pay status "'.$response['StatusID'].'" occurred.', $payMethod->method_config['order_status']);
                        break;
                }

                $order->save();

                // NotificationType IS payment
                if(strtolower($response['NotificationType']) == 'payment'){
                    // prepare string for 'da hash
                    $responseHashString = "notificationTypePaymentPaymentId".$response['PaymentID'].$payMethod->method_config['signature'];
                    // prepare response data
                    $responseData = array(
                        'NotificationType' => 'Payment',
                        'PaymentID' => $response['PaymentID'],
                        'Hash' => $s2pHelper->computeSHA256Hash($responseHashString)
                    );
                    // output response
                    echo "NotificationType=payment&PaymentID=".$responseData['PaymentID']."&Hash=".$responseData['Hash'];
                }
            }
            else{
                Mage::getModel('globalpay/logger')->write('Hashes do not match (received:' . $response['Hash'] . ')(recomposed:' . $s2pHelper->computeSHA256Hash($recomposedHashString) . ')', 'warning');
            }
        } catch (Exception $e) {
            Mage::getModel('globalpay/logger')->write($e->getMessage(), 'exception');
        }
        Mage::getModel('globalpay/logger')->write('::: END HANDLE RESPONSE <<<', 'info');
    }
    
    public function informCustomer(Mage_Sales_Model_Order $order, $amount, $currency)
    {
        try{
            /** @var $order Mage_Sales_Model_Order */
            /**
             * get data for template
             */
            $siteUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
            $siteName = Mage::app()->getWebsite(1)->getName();

            $subject = $siteName." - Payment confirmation";

            $supportEmail = Mage::getStoreConfig('trans_email/ident_support/email');
            $supportName = Mage::getStoreConfig('trans_email/ident_support/name');

            $localeCode = Mage::getStoreConfig('general/locale/code', $order->getStoreId());

            $storeId = Mage::app()->getStore()->getStoreId();

            $templateId = Mage::getStoreConfig(self::XML_PATH_EMAIL_PAYMENT_CONFIRMATION);


            /** @var $mailTemplate Mage_Core_Model_Email_Template */
            $mailTemplate = Mage::getModel('core/email_template');
            if (is_numeric($templateId)) { // loads from database @table core_email_template
                $mailTemplate->load($templateId);
            } else {
                $mailTemplate->loadDefault($templateId, $localeCode);
            }

            $mailTemplate->setSenderName($supportName);
            $mailTemplate->setSenderEmail($supportEmail);
            $mailTemplate->setTemplateSubject('Payment Confirmation');
            $mailTemplate->setTemplateSubject($subject);

            $mailTemplate->send($order->getCustomerEmail(), $order->getCustomerName(), array(
                    'site_url' => $siteUrl,
                    'order_increment_id' => $order->getRealOrderId(),
                    'site_name' => $siteName,
                    'customer_name' => $order->getCustomerName(),
                    'order_date' => $order->getCreatedAtDate(),
                    'total_paid' => number_format(($amount / 100), 2),
                    'currency' => $currency,
                    'support_email' => $supportEmail
                )
            );
        } catch (Exception $e) {
            Mage::getModel('globalpay/logger')->write($e->getMessage(), 'exception');
        }
    }

    public function infoAction()
    {
        $query = $this->getRequest()->getParams();
        if (!isset($query['data'])) {
            $this->_redirectUrl(Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK));
        }
        $this->loadLayout();
        $this->renderLayout();
    }
}