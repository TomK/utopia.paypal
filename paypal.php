<?php

define('PAYPAL_ENV_LIVE','');
define('PAYPAL_ENV_SANDBOX','sandbox');
define('PAYPAL_ENV_BETA_SANDBOX','beta-sandbox');

define('PAYPAL_TXN_TYPE_adjustment','adjustment');
define('PAYPAL_TXN_TYPE_cart','cart');
define('PAYPAL_TXN_TYPE_express_checkout','express_checkout');
define('PAYPAL_TXN_TYPE_masspay','masspay');
define('PAYPAL_TXN_TYPE_merch_pmt','merch_pmt');
define('PAYPAL_TXN_TYPE_new_case','new_case');
define('PAYPAL_TXN_TYPE_recurring_payment','recurring_payment');
define('PAYPAL_TXN_TYPE_recurring_payment_profile_created','recurring_payment_profile_created');
define('PAYPAL_TXN_TYPE_send_money','send_money');
define('PAYPAL_TXN_TYPE_subscr_cancel','subscr_cancel');
define('PAYPAL_TXN_TYPE_subscr_eot','subscr_eot');
define('PAYPAL_TXN_TYPE_subscr_failed','subscr_failed');
define('PAYPAL_TXN_TYPE_subscr_modify','subscr_modify');
define('PAYPAL_TXN_TYPE_subscr_payment','subscr_payment');
define('PAYPAL_TXN_TYPE_subscr_signup','subscr_signup');
define('PAYPAL_TXN_TYPE_virtual_terminal','virtual_terminal');
define('PAYPAL_TXN_TYPE_web_accept','web_accept');

class PayPal extends uBasicModule {
	public function GetUUID() { return 'PayPal_IPN'; }
	
	public function SetupParents() {
		modOpts::AddOption('paypal_api_username','API Username','PayPal');
		modOpts::AddOption('paypal_api_password','API Password','Paypal');
		modOpts::AddOption('paypal_api_signature','API Signature','PayPal');
		modOpts::AddOption('paypal_api_environment','Environment','PayPal',PAYPAL_ENV_SANDBOX,itCOMBO,array('Live'=>PAYPAL_ENV_LIVE,'Sandbox'=>PAYPAL_ENV_SANDBOX,'Beta Sandbox'=>PAYPAL_ENV_BETA_SANDBOX));
		$this->SetRewrite(true);
	}
	
	public function RunModule() {
		utopia::CancelTemplate();
		// IPN Received

		// Read the post from PayPal and add 'cmd' 
		$req = 'cmd=_notify-validate';
		foreach ($_POST as $key => $value) 
		// Handle escape characters, which depends on setting of magic quotes 
		{  
			$value = urlencode($value); 
			$req .= "&$key=$value"; 
		} 
		// Post back to PayPal to validate 
		$header = "POST /cgi-bin/webscr HTTP/1.0\r\n"; 
		$header .= "Content-Type: application/x-www-form-urlencoded\r\n"; 
		$header .= "Content-Length: " . strlen($req) . "\r\n\r\n";
		$env = modOpts::GetOption('paypal_api_environment');
		$envUrl = $env ? '.'.$env : '';
		$fp = fsockopen ('ssl://www'.$envUrl.'.paypal.com', 443, $errno, $errstr, 30); 


		// Process validation from PayPal 
		// TODO: This sample does not test the HTTP response code. All 
		// HTTP response codes must be handles or you should use an HTTP 
		// library, such as cUrl 
		$emailtext = '';
		if (!$fp) { // HTTP ERROR 
		} else { 
			// NO HTTP ERROR 
			fputs ($fp, $header . $req); 
			while (!feof($fp)) { 
				$res = fgets ($fp, 1024); 
				if (strcmp ($res, "VERIFIED") == 0) { 
					DebugMail('ipn',$_POST);
					// TODO:
					// Check the payment_status is Completed 
					// Check that txn_id has not been previously processed 
					// Check that receiver_email is your Primary PayPal email 
					// Check that payment_amount/payment_currency are correct 
					// Process payment 
					$txn = (array_key_exists('txn_type',$_POST)) ? $_POST['txn_type'] : NULL;
					if (array_key_exists($txn,self::$ipnHooks)) foreach (self::$ipnHooks[$txn] as $callback) {
						call_user_func_array($callback,array($_POST));
					}
					// If 'VERIFIED', send an email of IPN variables and values to the specified email address 
					foreach ($_POST as $key => $value){ 
						$emailtext .= $key . " = " .$value ."\n\n"; 
					}
					DebugMail("Live-VERIFIED IPN", $emailtext . "\n\n" . $req); 
				} else if (strcmp ($res, "INVALID") == 0) { 
					// If 'INVALID', send an email. TODO: Log for manual investigation. 
					foreach ($_POST as $key => $value){ 
						$emailtext .= $key . " = " .$value ."\n\n"; 
					}
					DebugMail("Live-INVALID IPN", $emailtext . "\n\n" . $req); 
				}	 
			}
			fclose ($fp); 		
		}
	}
	
	static $ipnHooks = array();
	public static function HookIPN($transaction_type,$callback) {
		self::$ipnHooks[$transaction_type][] = $callback;
	}

	private static $cvvAdded = false;
	public static function DrawCVVField($v) {
		if (!self::$cvvAdded) {
			self::$cvvAdded = true;
			echo '<div id="cvv2info" style="display:none;text-align:center;"><img src="'.utopia::GetRelativePath(dirname(__FILE__).'/cvv2.jpg').'" alt="" /></div>';
			uJavascript::AddText('function openCVV() { $(\'#cvv2info\').dialog({autoOpen:false,modal:true,width:550,buttons:{"Close":function() { $(this).dialog("close"); }},position:["center",100],resizable:false,draggable:false,title:"Where do i find my CVV2?"}); $(\'#cvv2info\').dialog("open"); }');
		}
		return '<a style="text-decoration:underline" href="javascript:openCVV()">CVV2?</a>';
	}
	public static function AddCVVField($module) {
		$obj = utopia::GetInstance($module);
		$obj->AddField('__cvv2__',array('PayPal','DrawCVVField'),'','');
	}

	/**
	 * Create an array used by other PayPal class functions.
	 * Requests Payment Cardholder and Card Information.
	 * @param string $firstName
	 * @param string $lastName
	 * @param string $address
	 * @param string $city
	 * @param string $state
	 * @param string $zip
	 * @param string $cardType
	 * @param string $cardNumber
	 * @param string $expDateMonth
	 * @param string $expDateYear
	 * @param string $cvv2Number
	 * @param string $startDateMonth [optional]
	 * @param string $startDateYear [optional]
	 * @param string $issueNumber [optional]
	 * @param string $country [optional]
	 * @return
	 */
	public static function CreatePayerArray($cardType,$cardNumber,$expDateMonth,$expDateYear,$cvv2Number,$startDateMonth=NULL,$startDateYear=NULL,$issueNumber=NULL,$firstName=NULL,$lastName=NULL,$address=NULL,$address2,$city=NULL,$state=NULL,$zip=NULL,$country='GB') {
		$arr = array(
				'CREDITCARDTYPE'=>urlencode($cardType),
				'ACCT'			=>urlencode($cardNumber),
				'EXPDATE'		=>urlencode($expDateMonth.$expDateYear),
				'CVV2'			=>urlencode($cvv2Number),
			);
		if ($startDateMonth && $startDateYear) $arr['STARTDATE'] = urlencode($startDateMonth.$startDateYear);
		if ($issueNumber) $arr['ISSUENUMBER'] = urlencode($issueNumber);

		if ($firstName) $arr['FIRSTNAME']	= urlencode($firstName);
		if ($lastName) $arr['LASTNAME']		= urlencode($lastName);
		if ($address) $arr['STREET']		= urlencode($address);
		if ($address2) $arr['STREET2']		= urlencode($address2);
		if ($city) $arr['CITY']				= urlencode($city);
		if ($state) $arr['STATE']			= urlencode($state);
		if ($zip) $arr['ZIP']				= urlencode($zip);
		if ($country) $arr['COUNTRYCODE']	= urlencode($country);

		return $arr;
	}

	/**
	 * Create a profile with PayPal to charge a client on a regular cycle.
	 * @param array $payerArray Array created with CreatePayerArray
	 * @param string $startDate UTC/GMT string
	 * @param string $ref Merchants Reference or Invoice number
	 * @param string $description Description of Recurring Payment
	 * @param string $period Day|Week|SemiMonth|Month|Year
	 * @param int $frequency Number of $period to make up one billing cycle
	 * @param decimal $subAmount Amount of recurring payment excluding tax
	 * @param decimal $taxAmount Tax amount of recurring payment
	 * @param decimal $initAmount Additional amount charged at start (eg. setup charge)
	 * @return array Response from PayPal
	 * @param string $currency [optional] Currency Code
	 */
	public static function CreateRecurringPayment($payerArray,$startDate,$ref,$description,$period,$frequency,$amount,$shipAmount,$taxAmount,$initAmount,$currency='GBP') {
		/* TODO: speak to paypal RE the following
		// amt not including tax
		// Tax amount (VAT)?  valid for recurring amount only? not for initamount?
		// recurring amount billed immediately + each cycle?
		// what happens when a card expires?
		*/
		$paymentArray = array(
				//'TOKEN'				=>$payerArray['ACCT'],
				'PROFILESTARTDATE'	=>urlencode($startDate),
				'PROFILEREFERENCE'	=>urlencode($ref),
				'DESC'				=>urlencode($description),
				'AUTOBILLAMT'		=>urlencode('NoAutoBill'),
				'BILLINGPERIOD'		=>urlencode($period),
				'BILLINGFREQUENCY'	=>urlencode($frequency),
				'AMT'				=>urlencode(round($amount,2)),
				'CURRENCYCODE'		=>urlencode($currency),
			);
		if ($initAmt > 0) $paymentArray['INITAMT'] = urlencode(round($initAmount,2));
		if ($shipAmount > 0) $paymentArray['SHIPPINGAMT'] = urlencode(round($shipAmount,2));
		if ($taxAmount > 0) $paymentArray['TAXAMT'] = urlencode(round($taxAmount,2));


		$nvparr = array_merge($payerArray,$paymentArray);
		return self::PPHttpPost('CreateRecurringPaymentsProfile', $nvparr);
	}

	public static function UpdateRecurringPayment($payerArray,$profileID,$startDate,$note,$description,$period,$frequency,$amount,$shipAmount,$taxAmount,$currency='GBP') {
		$paymentArray = array(
				'PROFILEID'			=>urlencode($profileID),
				'PROFILESTARTDATE'	=>urlencode($startDate),
				'DESC'				=>urlencode($description),
				'NOTE'				=>urlencode($note),
				'BILLINGPERIOD'		=>urlencode($period),
				'BILLINGFREQUENCY'	=>urlencode($frequency),
				'AMT'				=>urlencode(round($amount,2)),
				'CURRENCYCODE'		=>urlencode($currency),
			);
		if ($shipAmount > 0) $paymentArray['SHIPPINGAMT'] = urlencode(round($shipAmount,2));
		if ($taxAmount > 0) $paymentArray['TAXAMT'] = urlencode(round($taxAmount,2));

		$nvparr = array_merge($payerArray,$paymentArray);
		return self::PPHttpPost('CreateRecurringPaymentsProfile', $nvparr);
	}

	public static function GetRecurringPaymentProfile($profileId) {
		return self::PPHttpPost('GetRecurringPaymentsProfileDetails', array('PROFILEID'=>$profileId));
	}

	/**
	 * One off charge.
	 * @param array $payerArray Array created with CreatePayerArray
	 * @param decimal $amount Full amount to charge, including tax and shipping
	 * @param decimal $taxamt Full amount of tax for all items
	 * @param string $currency [optional] Currency Code
	 * @return
	 */
	public static function DirectPayment($payerArray,$amount,$taxamt,$currency='GBP') {
		// TODO: Allow $amount as array to specifiy multiple items
		$paymentArray = array(
				'IPADDRESS'		=>urlencode($_SERVER['REMOTE_ADDR']),
				'PAYMENTACTION'	=>urlencode('Sale'),
				'AMT'			=>urlencode($amount),
	//			'ITEMAMT'		=>urlencode(round($amount - ($amount / 1.15),2)),
	//			'TAXAMT'		=>urlencode($taxamt),
	//			'L_QTY0'		=>urlencode(1),
	//			'L_AMT0'		=>urlencode(round($amount - ($amount / 1.15),2)),
	//			'L_TAXAMT0'		=>urlencode($taxamt),
				'CURRENCYCODE'	=>urlencode($currency),
			);

		$nvparr = array_merge($payerArray,$paymentArray);
		return self::PPHttpPost('doDirectPayment',$nvparr);
	}

	public static function SetExpressCheckout($amount,$returnURL,$cancelURL,$paymentAction='Sale',$currency='GBP',$additional=array()) {
		$arr = array(
				'AMT'			=>urlencode($amount),
				'CURRENCYCODE'	=>urlencode($currency),
				'RETURNURL'		=>urlencode($returnURL),
				'CANCELURL'		=>urlencode($cancelURL),
				'PAYMENTACTION'	=>urlencode($paymentAction),
			);

		$arr = array_merge($arr,$additional);
		return self::PPHttpPost('SetExpressCheckout',$arr);
	}

	public static function GetExpressCheckoutDetails($token) {
		$arr = array(
				'TOKEN'			=>urlencode($token),
			);
		return self::PPHttpPost('GetExpressCheckoutDetails',$arr);
	}

	public static function GetExpressCheckoutUrl($checkoutReply) {
		if (self::HasError($checkoutReply)) return FALSE;
		$env = modOpts::GetOption('paypal_api_environment');
		$envUrl = $env ? '.'.$env : '';
		return 'https://www'.$envUrl.'.paypal.com/webscr'.
			'?cmd=_express-checkout&token='.$checkoutReply['TOKEN'].
			'&AMT=amount'.
			'&CURRENCYCODE=currencyID'.
			'&RETURNURL=return_url'.
			'&CANCELURL=cancel_url';
	}

	public static function DoExpressCheckoutPayment($token,$payerID,$amount,$paymentAction='Sale',$currency='GBP') {
		$arr = array(
				'TOKEN'			=>urlencode($token),
				'PAYERID'		=>urlencode($payerID),
				'AMT'			=>urlencode($amount),
				'CURRENCYCODE'	=>urlencode($currency),
				'PAYMENTACTION'	=>urlencode($paymentAction),
			);
		return self::PPHttpPost('DoExpressCheckoutPayment',$arr);
	}

	private static function PPHttpPost($methodName_, $nvpArray_) {
		// Set up your API credentials, PayPal end point, and API version.
		$env = modOpts::GetOption('paypal_api_environment');
		$envUrl = $env ? '.'.$env : '';
		$API_Endpoint = 'https://api-3t'.$envUrl.'.paypal.com/nvp';
		$API_UserName = urlencode('sdk-three_api1.sdk.com');
		$API_Password = urlencode('QFZCWN5HZM8VBG7Q');
		$API_Signature = urlencode('A.d9eRKfd1yVkRrtmMfCFLTqa6M9AyodL0SJkhYztxUi8W9pCXF6.4NI');

		if ($env == PAYPAL_ENV_LIVE) {
			$API_UserName = modOpts::GetOption('paypal_api_username');
			$API_Password = modOpts::GetOption('paypal_api_password');
			$API_Signature = modOpts::GetOption('paypal_api_signature');
		}

		$version = urlencode('51.0');

		$nvpStr_ = '';
		foreach ($nvpArray_ as $name=>$val) {
			$nvpStr_ .= "&$name=$val";
		}

		// Set the curl parameters.
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);

		// Turn off the server and peer verification (TrustManager Concept).
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);

		// Set the API operation, version, and API signature in the request.
		$nvpreq = "METHOD=$methodName_&VERSION=$version&PWD=$API_Password&USER=$API_UserName&SIGNATURE=$API_Signature$nvpStr_";
//echo $nvpreq;
		// Set the request as a POST FIELD for curl.
		curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

		// Get response from the server.
		$httpResponse = curl_exec($ch);

		if(!$httpResponse) {
			exit("$methodName_ failed: ".curl_error($ch).'('.curl_errno($ch).')');
		}

		// Extract the response details.
		$httpResponseAr = explode("&", $httpResponse);

		$httpParsedResponseAr = array();
		foreach ($httpResponseAr as $i => $value) {
			$tmpAr = explode("=", $value);
			if(sizeof($tmpAr) > 1) {
				$httpParsedResponseAr[$tmpAr[0]] = urldecode($tmpAr[1]);
			}
		}

		if((0 == sizeof($httpParsedResponseAr)) || !array_key_exists('ACK', $httpParsedResponseAr)) {
			exit("Invalid HTTP Response for POST request($nvpreq) to $API_Endpoint.");
		}

		return $httpParsedResponseAr;
	}

	public static function HasError($result) {
		if (!array_key_exists('ACK',$result) || in_array($result['ACK'],array('Failure','FailureWithWarning'))) return true;
		return false;
	}

	public static function GetErrors($result) {
		if (!self::HasError($result)) return array();

		$i = 0;
		$arr = array();
		while (array_key_exists('L_ERRORCODE'.$i,$result)) {
			$arr[] = $result['L_LONGMESSAGE'.$i];
			$i++;
		}
		return $arr;
	}
}

?>
