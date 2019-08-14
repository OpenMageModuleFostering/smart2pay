<?php

class Smart2Pay_Globalpay_Helper_Data extends Mage_Payment_Helper_Data
{
	
//	public function log($logString){
//		$myFile = Mage::getStoreConfig('payment/hpp/logfile');
//    	$fh = fopen($myFile, 'a');
//		$time = date('d/m/Y G:i:s', time());
//    	fwrite($fh, $time.":".$logString . "\n");
//    	fclose($fh);
//	
//	}
//	
//	
//	public function parseResponse($response) {
//		$vars = array();
//		if(!empty($response)){
//			$pairs    = explode("&", $response);
//			foreach ($pairs as $pair) {
//				$nv                = explode("=", $pair);
//				$name            = $nv[0];
//				$vars[$name]    = $nv[1];
//			}
//		}
//   
//		return $vars;
//	}
	
	public function computeSHA256Hash($message){
		return hash("sha256", strtolower($message));
	}
  
//	public function getSignature(){
//		return Mage::getModel('Smart2Pay_HPP_Model_PaymentMethod')->getConfigData('secretwordt');
//	}
}
