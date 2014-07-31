<?php

class MS_View_Shortcode_Membership_Signup extends MS_View {
	
	protected $data;
	
	protected $fields;
	
	public function to_html() {
		ob_start();
		?>
			<div class="ms-membership-form-wrapper">
				<legend><?php _e( 'Membership Levels', MS_TEXT_DOMAIN ) ?></legend>
				<p class="ms-alert-box <?php echo count( $this->data['ms_relationships'] > 0 ) ? 'ms-alert-success' : ''; ?>">
					<?php
						if( count( $this->data['ms_relationships'] ) > 0 ) {
// 							MS_Helper_Debug::log($this->data['ms_relationships']);
	 						_e( 'Your current subscriptions are listed here. You can renew, cancel or upgrade your subscriptions by using the forms below.', MS_TEXT_DOMAIN );
	 						foreach( $this->data['ms_relationships'] as $membership_id => $membership_relationship ){
	 							switch( $membership_relationship->status ) {
	 								case MS_Model_Membership_Relationship::STATUS_CANCELED:
	 									$msg = __( 'Membership canceled, valid until it expires on: ', MS_TEXT_DOMAIN ) . $membership_relationship->expire_date;
	 									$this->membership_box_html( MS_Model_Membership::load( $membership_id ), MS_Helper_Membership::MEMBERSHIP_ACTION_RENEW, $msg );
	 									break;
	 								case MS_Model_Membership_Relationship::STATUS_EXPIRED:
	 									$msg = __( 'Membership expired on: ', MS_TEXT_DOMAIN ) . $membership_relationship->expire_date;
	 									$this->membership_box_html( MS_Model_Membership::load( $membership_id ), MS_Helper_Membership::MEMBERSHIP_ACTION_RENEW, $msg );
	 									break;
	 								case MS_Model_Membership_Relationship::STATUS_TRIAL:
	 								case MS_Model_Membership_Relationship::STATUS_ACTIVE:
	 									$msg = __( 'Membership expires on: ', MS_TEXT_DOMAIN ) . $membership_relationship->expire_date;
	 									$this->membership_box_html( MS_Model_Membership::load( $membership_id ), MS_Helper_Membership::MEMBERSHIP_ACTION_CANCEL, $msg );
	 									break;
	 								case MS_Model_Membership_Relationship::STATUS_PENDING:
	 									$msg = __( 'Pending payment', MS_TEXT_DOMAIN );
	 									$this->membership_box_html( MS_Model_Membership::load( $membership_id ), MS_Helper_Membership::MEMBERSHIP_ACTION_SIGNUP, $msg );
	 									break;
	 								default:
	 									$this->membership_box_html( MS_Model_Membership::load( $membership_id ), MS_Helper_Membership::MEMBERSHIP_ACTION_CANCEL );
	 									break; 
	 							}
	 						}
	 					}
	 					else {
	 						_e( 'We have the following subscriptions available for our site. To join, simply click on the <strong>Sign Up</strong> button and then complete the registration details.', MS_TEXT_DOMAIN );
	 					}
					?>
				</p>
				<?php
					if( $this->data['member']->is_member() && ! empty( $this->data['memberships'] ) ) {
						?>
		 					<legend class="ms-move-from"> 
		 						<?php 
		 							if( empty( $this->data['move_from_id'] ) ) {
										echo __( 'Add Membership Level', MS_TEXT_DOMAIN ); 										
									} 
									else {
										echo __( 'Change Membership Level', MS_TEXT_DOMAIN ); 										
									}
								?>
		 					</legend>
		 				<?php 
	 				}
				?>	
				<div class="ms-form-price-boxes">
					<?php do_action( 'ms_view_shortcode_membership_signup_form_before_memberships' ); ?>
					<?php
						if( ! empty( $this->data['move_from_id'] ) ) {
							$action = MS_Helper_Membership::MEMBERSHIP_ACTION_MOVE;
						}
						else {
							$action = MS_Helper_Membership::MEMBERSHIP_ACTION_SIGNUP;	
						}

						foreach( $this->data['memberships'] as $membership ) {
							$this->membership_box_html( $membership, $action );
						}
					?>
					<?php do_action( 'ms_view_shortcode_membership_signup_form_after_memberships' ) ?>
				</div>
			</div>
			<div style='clear:both;'></div>
		<?php
		$html = ob_get_clean();
		return $html;
	}
	
	private function membership_box_html( $membership, $action, $msg = null ) {
		$this->prepare_fields( $membership->id, $action );
		?>
		<form class="ms-membership-form" method="post">
			<?php wp_nonce_field( $this->fields['action']['value'] ); ?>
			<?php 
				foreach( $this->fields as $field ) {
					MS_Helper_Html::html_input( $field );
				}
			?>
			<div id="ms-membership-wrapper-<?php echo $membership->id; ?>" class="ms-membership-details-wrapper">
				<div class="ms-top-bar">
					<span class="ms-title"><?php echo $membership->name; ?></span>
				</div>
				<div class="ms-price-description">
					<?php echo $membership->description; ?>
				</div>
				<div class="ms-bottom-bar">
					<span class="ms-link">
					<?php if( $msg ): ?>
						<span class="ms-bottom-msg"><?php echo $msg; ?></span>
					<?php endif;?>
					<?php
						$class = apply_filters( 'ms_view_shortcode_membership_signup_form_button_class', 'ms-signup-button' );
						
						$submit = array(
							'id' => 'submit',
							'type' => MS_Helper_Html::INPUT_TYPE_SUBMIT,
							'value' => esc_html( $this->data[ "{$action}_text" ] ),
							'class' => $class,
						);
						MS_Helper_Html::html_input( $submit );
					?>
					</span>
				</div>
			</div>
		</form>
		<?php 
	}
	
	private function prepare_fields( $membership_id, $action ) {
		
		$this->fields = array(
			'membership_id' => array(
					'id' => 'membership_id',
					'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
					'value' => $membership_id,
			),
			'action' => array(
					'id' => 'action',
					'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
					'value' => $this->data['action'],
			),
			'step' => array(
					'id' => 'step',
					'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
					'value' => $this->data['step'],
			),
		);

		if( ! empty( $this->data['move_from_id'] ) ) {
			$this->fields['move_from_id'] = array(
				'id' => 'move_from_id',
				'type' => MS_Helper_Html::INPUT_TYPE_HIDDEN,
				'value' => $this->data['move_from_id'],
			);
		}
		
		if( MS_Helper_Membership::MEMBERSHIP_ACTION_CANCEL == $action ) {
			$this->fields['action']['value'] = $action;
			unset( $this->fields['step'] );
		}
	}
}