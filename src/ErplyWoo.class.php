<?php

require_once dirname(__FILE__).'/../wp-config.php';
require_once dirname(__FILE__).'/EAPI.class.php';

/*
 *        
 *        ALTER TABLE `wp_users` ADD `ERPLY_Customer_id` BIGINT( 20 ) NOT NULL ; 
 * 		  ALTER TABLE `wp_woocommerce_order_items` ADD `ERPLY_invoice_id` BIGINT( 20 ) NOT NULL                
 * 
 */
 
class ErplyWoo {
		
 
	public $api;
	/**
	*connection variable initiation
	*/
	function __construct() {
		$api = new EAPI();				
		$api->clientCode =  "";
		$api->username =   "";
		$api->password =   "";
		$api->url = "https://".$api->clientCode.".erply.com/api/";
		$this->Eapi = $api;		
	}
	/**
	*creating invoice in erply  
	*
	*
	*
	*/
	
	function createErplyInvoices() {
	
			global $wpdb ;
			$orderID = 0;
			$counter=0;
			
			//Getting new ordered items where ERPLY_invoice_id = '0';
			
			$order_items = $wpdb->get_results( "SELECT * FROM wp_woocommerce_order_items WHERE ERPLY_invoice_id = '0' ",  ARRAY_A ); 
						
		if($order_items!=null){
		
			foreach ($order_items as $order_item)
			{
				$order_item_id = $order_item["order_item_id"];
				$order_item_name=$order_item["order_item_name"];
				$Cr_order[$counter]=$order_id;
				$Cr_order_item_id[$order_id][]=$order_item_id;
				$Cr_order_item_name[$order_id][]=$order_item_name;
				$counter++;
			}
			
			$Orders=array_unique($Cr_order);
			sort($Orders,SORT_REGULAR);
			
			for($zz=0;$zz<count($Orders);$zz++){
			
				//Getting the customer id that have the same order id 
				
				$order_id=$Orders[$zz];
				
				$customerID = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s ", $order_id, "_customer_user") ) ;
				$customer_id= $wpdb->get_var( $wpdb->prepare( "SELECT ERPLY_Customer_id FROM $wpdb->users WHERE id = %d ", $customerID) ) ;
				$shipping= $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM $wpdb->postmeta WHERE post_id = '$order_id'", ARRAY_A));
				
				//Getting shipping information and format it
				//Also update customer if there is any change in his account info 
					
				for ($i=0;$i<count($shipping);$i++)
				{
					$inf=$shipping[$i];
					$kmeta=$inf->meta_key;
					$vmeta=$inf->meta_value;
					$info[$kmeta]=$vmeta;
				}
				
				extract($info);
				
				//Generating invoice information
				
					$itemnames="woocommerce invoice, order # $order_id , Items SKU: ";
					$orderID = $order_id; 
				
				$lin2=array('ownerID'=>$customer_id,'typeID'=>1, 'street'=>$_shipping_address_1,'address2'=>$_shipping_address_2,
				'city'=>$_shipping_city,'postalCode'=>$_shipping_postcode,'state'=>$_shipping_state,'country'=>$_shipping_country ,
				'attributeName1'=>'firstName','attributeType1'=>'text','attributeValue1'=>$_shipping_first_name,'attributeName2'=>'lastName',
				'attributeType2'=>'text',	'attributeValue2'=>$_shipping_last_name	);
				
				//Saving the new billing address 
				
				$res = $this->Eapi->sendRequest("saveAddress",$lin2);
				$res = json_decode($res, true);
				$addressID=$res['records'][0]['addressID'];
				
				
				$lines = array("type" => "INVWAYBILL", "invoiceState" => "READY", "date"=>date("l jS of F g:i A.", time()),
				"addressID"=>$addressID,"paymentType"=>$_payment_method_title,"internalNotes" => "woocommerce invoice", "paymentStatus" => "PAID",
				"customerID" => $customer_id, "warehouseID" => 2,"currencyCode"=>$_order_currency ); 
				
				$i=0;
				
				foreach ($Cr_order_item_id[$order_id] as $order_item_id)
				{
					$order_item_name=$Cr_order_item_name[$order_id][$i];		
					$order_itemmetas = $wpdb->get_results( "SELECT * FROM wp_woocommerce_order_itemmeta WHERE order_item_id = '$order_item_id' ",  ARRAY_A );	
					
					foreach ($order_itemmetas as $order_itemmeta)
					{ 
						if($order_itemmeta['meta_key'] == "_qty") $lines["amount$i"] = $order_itemmeta['meta_value'];
						if($order_itemmeta['meta_key'] == "_line_total") $lines["price$i"] = $order_itemmeta['meta_value'];	
						if($order_itemmeta['meta_key'] == "pa_size"){$lines["attributeName$i"]="size";$lines["attributeType$i"]="text";$lines["attributeValue$i"]=$order_itemmeta['meta_value'];}
						$s=$i+1;
						if($order_itemmeta['meta_key'] == "pa_colors"){$lines["attributeName$s"]="color";$lines["attributeType$s"]="text";$lines["attributeValue$s"]=$order_itemmeta['meta_value'];}
						if($order_itemmeta['meta_key'] == "_line_tax" ) $lines["vatrateID$i"] = $order_itemmeta['meta_value'];
						if($order_itemmeta['meta_key'] == "_product_id" ) $_product_id = $order_itemmeta['meta_value'];
						//Get the sku for each product 
						$SkuWo = $wpdb->get_results( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id= '$_product_id' AND meta_key='_sku'",  ARRAY_A);
						
						//Get the id of the product from Erply
						if($SkuWo[0]["meta_value"]!=null){$ress = $this->Eapi->sendRequest("getProducts", array("code2"=>$SkuWo[0]["meta_value"], "getStockInfo" => 1));
						$ress = json_decode($ress, true); $lines["productID$i"]=$ress['records'][0]['productID'];}
			
					}
						
					$itemnames .=$SkuWo[0]["meta_value"].", ";
					$i++;	
				}
				$lines["notes"]=$itemnames;
				$this->saveErplyInvoice($lines, $order_id);
		}
	
	
	}
	
}
	/**
	*saving the invoice functionality
	*
	*/
	
	function saveErplyInvoice($lines, $orderID){ 
		
		global $wpdb ;
		
		//Saving the order in Erply and get an invoice number 
		
		$result = $this->Eapi->sendRequest("saveSalesDocument", $lines); 
		$result = json_decode($result, true); 
		
		$invoiceNo = $result['records'][0]['invoiceNo'];
		
		//update the woocommerce with the invoice id
		
		$wpdb->update("{$wpdb->prefix}woocommerce_order_items",	array('ERPLY_invoice_id'   => $invoiceNo),array( 'order_id' => $orderID ));
		
	}
	
	/**
	*
	*This function just sync quantities 
	*/
	function syncErplyInventory(){ 
	
		global $wpdb ;
		
		$products = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'product' ",  ARRAY_A );
		
		foreach ($products as $product) {	 
		
			$product_id = $product['ID'];
			
			$SkuWoo = $wpdb->get_results( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id= '$product_id' AND meta_key='_sku'",  ARRAY_A);
			$SkuWoo=$SkuWoo[0]['meta_value'];
			
			if($SkuWoo != NULL){
							
				$result = $this->Eapi->sendRequest("getProducts", array("code2"=> $SkuWoo, "getStockInfo" => 1));
				$result = json_decode($result, true);
				
				if(count($result['records']) > 0 ){
				
					$Erply_product_id = $result['records'][0]['productID'];
					$ErplyStock = 0;
					
					foreach ($result['records'][0]['warehouses'] as $warehouse) {
					
						$ErplyStock+=  $warehouse['totalInStock'];
												
					}					
					
					$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = '$ErplyStock' WHERE post_id = '$product_id' AND meta_key = '_stock'");	// SET STOCK
					
				}					
					
			}	
		}
			
	}	
	/**
	*
	*
	*/
	function syncErplyCustomers(){
	
		global $wpdb ;
		//$users = $wpdb->get_results( "SELECT * FROM $wpdb->users WHERE ERPLY_Customer_id = 0 ",  ARRAY_A );	it suppose to be by default that the user is a customer so no need to WHERE
		$users = $wpdb->get_results( "SELECT * FROM $wpdb->users ",  ARRAY_A );	
		
		foreach ($users as $user){
		
			$user_email = $user['user_email'];
			$user_id    = $user['ID'];
			
			//Getting user information from Erply using e-mail
			$result = $this->Eapi->sendRequest("getCustomers", array("searchName"=> $user_email, "responseMode"=> "detail"));
			$result = json_decode($result, true); 
		
			//If the user do not exist in Erply create him and form his information 
			if (!count($result['records'])){
			
				$user_info = $wpdb->get_results( "SELECT meta_key,meta_value FROM $wpdb->usermeta  where user_id='$user_id'",  ARRAY_A );	
			
				for($i=0;$i<count($user_info);$i++){
					$info[$user_info[$i]['meta_key']]=$user_info[$i]['meta_value'];
				}
				
				//Forming customer information and saving them in Erply assuming that the new customer have 0 reward points 
				$lin=array('firstName'=>$info['first_name'],
				'lastName'=>$info['last_name'],
				'fullName'=>$user['user_nicename'],
				'email'=>$user_email,
				'phone'=>$info['billing_phone'],
				'companyName'=>$info['billing_company'],
				'username'=>$user_email);
				
				$res = $this->Eapi->sendRequest("saveCustomer",$lin);
				$res = json_decode($res, true); 
				
				$customerID = $result['records'][0]['customerID'];
				
				$wpdb->query( "	UPDATE {$wpdb->users} SET ERPLY_Customer_id = $customerID WHERE user_email = '$user_email' ");
		}
		
		if (count($result['records']) > 0){
		
			$customerID = $result['records'][0]['customerID'];			
			
			//Getting reward points and loyalty information from Erply			
			$Rewardresult = $this->Eapi->sendRequest("getCustomerRewardPoints", array("customerID"=>$customerID));
            $Rewardoutput = json_decode($Rewardresult, true);
			$reward_points = $Rewardoutput['records'][0]['points'];
			
			//Updating customer ID in woo-commerce ERPLY_Customer_id
			
			$wpdb->query( "	UPDATE {$wpdb->users} SET ERPLY_Customer_id = $customerID WHERE user_email = '$user_email' ");
			
			//Updating reward points and loyalty level
			$this->insertOnDuplicateUpdateUserMeta($user_id, 'reward_points', $reward_points); 
			$this->insertOnDuplicateUpdateUserMeta($user_id, 'loyalty_level', $loyalty_level); 					
			
			}
		}	
			
	}
	/**
	*
	*
	*/
	function insertOnDuplicateUpdateUserMeta($user_id, $meta_key, $meta_value){
		
		//Updating loyalty level and points into woo-commerce if not exist create them 
		global $wpdb ;
				
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->usermeta WHERE user_id= '$user_id' AND meta_key = '$meta_key' " ) ) ;
		
		if($exists||$exists="0"){
		
			$wpdb->query( "	UPDATE $wpdb->usermeta SET meta_value ='$meta_value' WHERE user_id = '$user_id' AND meta_key = '$meta_key'");
		
		}
		else {
		
			$wpdb->query( "	INSERT INTO $wpdb->usermeta (user_id, meta_key, meta_value) VALUES ('$user_id', '$meta_key', '$meta_value') ");
		}
		
	}
		
}
?>