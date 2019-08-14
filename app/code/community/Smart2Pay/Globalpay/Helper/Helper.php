<?php
class Smart2Pay_Globalpay_Helper_Helper{
    public function computeSHA256Hash($message){
         //echo strtolower($message);
            //die();
           return hash("sha256", strtolower($message));
    }
}
?>
