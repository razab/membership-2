<?php
/**
 * @copyright Incsub (http://incsub.com/)
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 (GPL-2.0)
 * 
 * This program is free software; you can redistribute it and/or modify 
 * it under the terms of the GNU General Public License, version 2, as  
 * published by the Free Software Foundation.                           
 *
 * This program is distributed in the hope that it will be useful,      
 * but WITHOUT ANY WARRANTY; without even the implied warranty of       
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        
 * GNU General Public License for more details.                         
 *
 * You should have received a copy of the GNU General Public License    
 * along with this program; if not, write to the Free Software          
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               
 * MA 02110-1301 USA                                                    
 *
*/

class MS_Model_Gateway_Authorize extends MS_Model_Gateway {
	
	const AUTHORIZE_CIM_ID_USER_META = 'ms_authorize_cim_id';
	
	const AUTHORIZE_CIM_PAYMENT_PROFILE_ID_USER_META = 'ms_authorize_cim_payment_profile_id';
	
	protected static $CLASS_NAME = __CLASS__;
	
	public static $instance;
	
	protected static $cim;
	
	protected $id = self::GATEWAY_AUTHORIZE;
	
	protected $name = 'Authorize.net Gateway';
	
	protected $description = '';
	
	protected $manual_payment = false;
	
	protected $pro_rate = true;
	
	protected $active = false;
	
	protected $api_login_id;
	
	protected $api_transaction_key;
	
	protected $log_file;
	
	protected $mode;
	
	protected $payment_result;
	
	/**
	 * Processes purchase action.
	 *
	 * @since 4.0
	 *
	 * @access public
	 */
	public function process_purchase( $ms_relationship ) {
		if ( ! is_ssl() ) {
			throw new Exception( __( 'You must use HTTPS in order to do this', 'membership' ) );
		}
	
		$invoice = MS_Model_Invoice::get_current_invoice( $ms_relationship );
		if( MS_Model_Invoice::STATUS_PAID != $invoice->status ) {
		
			$member = MS_Factory::load( 'MS_Model_Member', $ms_relationship->user_id );
		
			/** manage authorize customer profile */
			$cim_profile_id = $this->get_cim_profile_id( $member );
			if( empty( $cim_profile_id ) ) {
				$this->create_cim_profile( $member );
			}
			/** Fetch for user selected cim profile */
			elseif( $cim_payment_profile_id = trim( filter_input( INPUT_POST, 'profile' ) ) ) {
				$response = $this->get_cim()->getCustomerPaymentProfile( $cim_profile_id, $cim_payment_profile_id );
				if ( $response->isError() ) {
					throw new Exception( __( 'The selected payment profile is invalid, enter a new credit card', MS_TEXT_DOMAIN ) );
				}
			}
			else {
				$this->update_cim_profile( $member );
			}
			$this->online_purchase( $invoice, $member );
		}
		
		return $invoice;
	}
	
	/**
	 * Request automatic payment to the gateway.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 */
	public function request_payment( $ms_relationship ) {
	
		$member = $ms_relationship->get_member();
		$invoice = MS_Model_Invoice::get_current_invoice( $ms_relationship );
	
		if( MS_Model_Invoice::STATUS_PAID != $invoice->status ) {
		
			try {
				$this->online_purchase( $invoice, $member );
			}
			catch( Exception $e ) {
				MS_Model_Event::save_event( MS_Model_Event::TYPE_PAYMENT_FAILED, $ms_relationship );
				MS_Helper_Debug::log( $e->getMessage() );
			}
		}
	}
	
	/**
	 * Processes online payments.
	 *
	 * Send to Authorize.net to process the payment immediatly.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param MS_Model_Invoice $invoice The invoice to pay.
	 * @return MS_Model_Invoice transaction information on success, otherwise throws an exception.
	 */
	protected function online_purchase( $invoice, $member ) {
		if ( 0 == $invoice->total ) {
			$invoice->status = MS_Model_Invoice::STATUS_PAID;
			$invoice->add_notes( __( 'Total is zero. Payment aproved. Not sent to gateway.', MS_TEXT_DOMAIN ) );
			$invoice->save();
			$this->process_transaction( $invoice );
			return $invoice;
		}
		$amount = number_format( $invoice->total, 2, '.', '' );
	
		if( $this->mode == self::MODE_SANDBOX ) {
			$invoice->add_notes( __( 'Sandbox', MS_TEXT_DOMAIN ) );
		}
	
		$cim_transaction = $this->get_cim_transaction( $member );
		$cim_transaction->amount = $amount;
		$cim_transaction->order->invoiceNumber = $invoice->id;
			
		$invoice->timestamp = time();
		$invoice->save();

		$response = $this->get_cim()->createCustomerProfileTransaction( 'AuthCapture', $cim_transaction );
		if ( $response->isOk() ) {
			$transaction_response = $response->getTransactionResponse();
			if( $transaction_response->approved ) {
				$invoice->external_id = $response->getTransactionResponse()->transaction_id;
				$invoice->status = MS_Model_Invoice::STATUS_PAID;
				$invoice->save();
					
				$this->process_transaction( $invoice );
			}
			else {
				throw new Exception( sprintf( __( 'Payment Failed: code %s, subcode %s, reason code %, reason %s', MS_TEXT_DOMAIN ),
						$transaction_response->response_code,
						$transaction_response->response_subcode,
						$transaction_response->response_reason_code,
						$transaction_response->response_reason
				) );
			}
		}
		else {
			throw new Exception( __( 'Payment Failed: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
		}
	
		return $invoice;
	}
	
	/**
	 * Save card info.
	 *
	 * @since 4.0.0
	 *
	 * @param MS_Model_Member $member The member.
	 */
	public function save_card_info( $member ) {
		$cim_profile_id = $this->get_cim_profile_id( $member );
		$cim_payment_profile_id = $this->get_cim_payment_profile_id( $member );
		$profile = $this->get_cim_profile( $member );

		if( ! empty( $profile['customerPaymentProfileId'] ) && $cim_payment_profile_id == $profile['customerPaymentProfileId'] ) {
			$exp_year =  filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT );
			$exp_month = substr( filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ), -2 );
			$member->set_gateway_profile( $this->id, 'card_exp', gmdate( "Y-m-t", strtotime( "{$exp_year}-{$exp_month}-01") ) );
			$member->set_gateway_profile( $this->id, 'card_num', str_replace( 'XXXX', '', $profile['payment']['creditCard']['cardNumber'] ) );
		}
	}
	
	/**
	 * Loads Authorize.net lib.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 */
	protected function load_authorize_lib(){
		require_once MS_Plugin::instance()->dir . '/lib/authorize.net/autoload.php';
	} 
	
	/**
	 * Returns the instance of AuthorizeNetCIM class.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @staticvar AuthorizeNetCIM $cim The instance of AuthorizeNetCIM class.
	 * @return AuthorizeNetCIM The instance of AuthorizeNetCIM class.
	 */
	protected function get_cim() {
	
		if ( ! empty( self::$cim ) ) {
			return self::$cim;
		}
	
		$this->load_authorize_lib();
	
		$cim = new AuthorizeNetCIM( $this->api_login_id, $this->api_transaction_key );
		$cim->setSandbox( $this->mode != self::MODE_LIVE );
		if ( $this->log_file ) {
			$cim->setLogFile( $this->log_file );
		}
		self::$cim = $cim;
		
		return self::$cim;
	}
	
	/**
	 * Get customer information manager profile id from user meta.
	 * 
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param int $user_id The user Id.
	 */
	public function get_cim_profile_id( $member ) {
		$cim_profile_id = $member->get_gateway_profile( $this->id, 'cim_profile_id' );

		return apply_filters( 'ms_model_gateway_authorize_get_cim_profile_id', $cim_profile_id, $member->id );
	}
	
	/**
	 * Get customer information manager payment profile id from user meta.
	 *
	 * @since 4.0.0
	 *
	 * @param int $user_id The user Id.
	 */
	public function get_cim_payment_profile_id( $member ) {
		$cim_payment_profile_id = $member->get_gateway_profile( $this->id, 'cim_payment_profile_id' );
		return apply_filters( 'ms_model_gateway_authorize_get_cim_payment_profile_id', $cim_payment_profile_id, $member->id );
	}
	
	/**
	 * Save cim profile to user meta.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param MS_Model_Member $member The member.
	 */
	protected function save_cim_profile( $member, $cim_profile_id, $cim_payment_profile_id ) {
		$member->set_gateway_profile( $this->id, 'cim_profile_id', $cim_profile_id );
		$member->set_gateway_profile( $this->id, 'cim_payment_profile_id', $cim_payment_profile_id );
		$this->save_card_info( $member );
		$member->save();
	}
		
	/**
	 * Get customer information manager profile from Authorize.net.
	 * 
	 * @since 4.0.0
	 *
	 * @access protected
	 * @param MS_Model_Member $member The member.
	 * @return array The A.net payment profiles array structure.
	 */
	public function get_cim_profile( $member ) {

		$cim_profiles = array();
		$cim_profile_id = $this->get_cim_profile_id( $member );
		
		if( $cim_profile_id ) {
			
			$response = $this->get_cim()->getCustomerProfile( $cim_profile_id );
			if ( $response->isOk() ) {
				$cim_profiles = json_decode( json_encode( $response->xml->profile ), true );
				if( is_array( $cim_profiles ) && !empty( $cim_profiles['paymentProfiles'] ) && is_array( $cim_profiles['paymentProfiles'] ) ) {
					$cim_profiles = $cim_profiles['paymentProfiles'];
				}
			}
		}
		
		return $cim_profiles;
	}
	
	/**
	 * Creates Authorize.net CIM profile for current user.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return int Customer profile ID on success, otherwise FALSE.
	 */
	protected function create_cim_profile( $member ) {
		$this->load_authorize_lib();
		$customer = new AuthorizeNetCustomer();
		$customer->merchantCustomerId = $member->id;
		$customer->email = $member->email;
		$customer->paymentProfiles[] = $this->create_cim_payment_profile();
		$response = $this->get_cim()->createCustomerProfile( $customer );
		if ( $response->isError() ) {
			MS_Helper_Debug::log($response);
			throw new Exception( __( 'Payment failed due to CIM profile not created: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
		}
	
		$cim_profile_id = $response->getCustomerProfileId();
		$cim_payment_profile_id = $response->getCustomerPaymentProfileIds();
		
		$this->save_cim_profile( $member, $cim_profile_id, $cim_payment_profile_id );
	}
	
	/**
	 * Updates CIM profile by adding a new credit card.
	 *
	 * @since 4.0.0
	 *
	 * @return boolean TRUE on success, otherwise FALSE.
	 */
	public function update_cim_profile( $member ) {
		$cim_profile_id = $this->get_cim_profile_id( $member );
		$cim_payment_profile_id = $this->get_cim_payment_profile_id( $member );
		
		if( empty( $cim_payment_profile_id ) ) {
			$response = $this->get_cim()->createCustomerPaymentProfile( $cim_profile_id, $this->create_cim_payment_profile() );
			$cim_payment_profile_id = $response->getCustomerPaymentProfileIds();
		}
		else {
			$response = $this->get_cim()->updateCustomerPaymentProfile( $cim_profile_id, $cim_payment_profile_id, self::create_cim_payment_profile() );
		}
		
		/** If the error is not due to a duplicate customer payment profile.*/
		if ( $response->isError() && 'E00039' != $response->xml->messages->message->code ) {
			throw new Exception( __( 'Payment failed due to CIM profile not updated: ', MS_TEXT_DOMAIN ) . $response->getMessageText() );
		}
			
		$this->save_cim_profile( $member, $cim_profile_id, $cim_payment_profile_id );
	}
	
	/**
	 * Creates CIM payment profile and fills it with posted credit card data.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNetPaymentProfile The instance of AuthorizeNetPaymentProfile class.
	 */
	protected function create_cim_payment_profile() {

		$this->load_authorize_lib();
	
		$payment = new AuthorizeNetPaymentProfile();
	
		// billing information
		$payment->billTo->firstName = substr( trim( filter_input( INPUT_POST, 'first_name' ) ), 0, 50 );
		$payment->billTo->lastName = substr( trim( filter_input( INPUT_POST, 'last_name' ) ), 0, 50 );
		$payment->billTo->company = substr( trim( filter_input( INPUT_POST, 'company' ) ), 0, 50 );
		$payment->billTo->address = substr( trim( filter_input( INPUT_POST, 'address' ) ), 0, 60 );
		$payment->billTo->city = substr( trim( filter_input( INPUT_POST, 'city' ) ), 0, 40 );
		$payment->billTo->state = substr( trim( filter_input( INPUT_POST, 'state' ) ), 0, 40 );
		$payment->billTo->zip = substr( trim( filter_input( INPUT_POST, 'zip' ) ), 0, 20 );
		$payment->billTo->country = substr( trim( filter_input( INPUT_POST, 'country' ) ), 0, 60 );
		$payment->billTo->phoneNumber = substr( trim( filter_input( INPUT_POST, 'phone' ) ), 0, 25 );
	
		// card information
		$payment->payment->creditCard->cardNumber = preg_replace( '/\D/', '', filter_input( INPUT_POST, 'card_num' ) );
		$payment->payment->creditCard->cardCode = trim( filter_input( INPUT_POST, 'card_code' ) );
		$payment->payment->creditCard->expirationDate = sprintf( '%04d-%02d', filter_input( INPUT_POST, 'exp_year', FILTER_VALIDATE_INT ), substr( filter_input( INPUT_POST, 'exp_month', FILTER_VALIDATE_INT ), -2 ) );
	
		return $payment;
	}
	
	/**
	 * Initializes and returns Authorize.net CIM transaction object.
	 *
	 * @since 4.0.0
	 *
	 * @access protected
	 * @return AuthorizeNetTransaction The instance of AuthorizeNetTransaction class.
	 */
	protected function get_cim_transaction( $member ) {
		$this->load_authorize_lib();
	
		$cim_profile_id = $this->get_cim_profile_id( $member );
		$cim_payment_profile_id = $this->get_cim_payment_profile_id( $member );
		if( empty( $cim_profile_id ) || empty( $cim_payment_profile_id ) ) {
			throw new Exception( __( 'CIM Payment profile not found', MS_TEXT_DOMAIN ) );
		}
		$transaction = new AuthorizeNetTransaction();
		$transaction->customerProfileId = $cim_profile_id;
		$transaction->customerPaymentProfileId = $cim_payment_profile_id;
	
		return $transaction;
	}
	
	/**
	 * Verify required fields.
	 *
	 * @since 1.0
	 *
	 * @return boolean
	 */
	public function is_configured() {
		$is_configured = true;
		$required = array( 'api_login_id', 'api_transaction_key' );
		foreach( $required as $field ) {
			if( empty( $this->$field ) ) {
				$is_configured = false;
				break;
			}
		}
	
		return apply_filters( 'ms_model_gateway_authorize_is_configured', $is_configured );
	}
}
