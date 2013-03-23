<?php

// register a new user
function rcp_process_registration() {

  	if ( isset( $_POST["rcp_register_nonce"] ) && wp_verify_nonce( $_POST['rcp_register_nonce'], 'rcp-register-nonce' ) ) {

		global $rcp_options, $user_ID;

		$subscription_id = isset( $_POST['rcp_level'] ) ? absint( $_POST['rcp_level'] ) : false;
		$discount        = isset( $_POST['rcp_discount'] ) ? sanitize_text_field( $_POST['rcp_discount'] ) : '';
		$price           = number_format( (float) rcp_get_subscription_price( $subscription_id ), 2 );
		$expiration      = rcp_get_subscription_length( $subscription_id );

		/***********************
		* validate the form
		***********************/

		do_action( 'rcp_before_form_errors', $_POST );

		$user_data = rcp_validate_user_data();

		if( ! $subscription_id ) {
			// no subscription level was chosen
			rcp_errors()->add( 'no_level', __( 'Please choose a subscription level', 'rcp' ), 'register' );
		}
		if( $subscription_id ) {
			if( $price == 0 && $expiration->duration > 0 && rcp_has_used_trial( $user_id ) ) {
				// this ensures that users only sign up for a free trial once
				rcp_errors()->add( 'free_trial_used', __( 'You may only sign up for a free trial once', 'rcp' ), 'register' );
			}
		}
		if( ! empty( $discount ) ) {
			if( ! rcp_validate_discount( $discount ) ) {
				// the entered discount code is incorrect
				rcp_errors()->add( 'invalid_discount', __( 'The discount you entered is invalid', 'rcp' ), 'register' );
			}
			if( ! $user_data['need_new'] && rcp_user_has_used_discount( $user_data['id'] , $discount ) ) {
				rcp_errors()->add( 'discount_already_used', __( 'You can only use the discount code once', 'rcp' ), 'register' );
			}
		}
		if( $price == 0 && isset( $_POST['rcp_auto_renew'] ) ) {
			// since free subscriptions do not go through PayPal, they cannot be auto renewed
			rcp_errors()->add( 'invalid_auto_renew', __( 'Free subscriptions cannot be automatically renewed', 'rcp' ), 'register' );
		}

		do_action( 'rcp_form_errors', $_POST );

		// retrieve all error messages, if any
		$errors = rcp_errors()->get_error_messages();

		// only create the user if there are no errors
		if( empty( $errors ) ) {

			// deterime the expiration date of the user's subscription
			if( $expiration->duration > 0 ) {

				$member_expires = rcp_calc_member_expiration( $expiration );

			} else {
				$member_expires = 'none';
			}

			if( $user_data['need_new'] ) {
				$user_id = wp_insert_user( array(
						'user_login'		=> $user_data['login'],
						'user_pass'	 		=> $user_data['password'],
						'user_email'		=> $user_data['email'],
						'first_name'		=> $user_data['first_name'],
						'last_name'			=> $user_data['last_name'],
						'user_registered'	=> date( 'Y-m-d H:i:s' ),
						'role'				=> apply_filters( 'rcp_default_user_level', 'subscriber', $subscription_id )
					)
				);
			}
			if( $user_id ) {

				// get the details of this subscription
				$subscription = rcp_get_subscription_details( $_POST['rcp_level'] );

				// setup a unique key for this subscription
				$subscription_key = rcp_generate_subscription_key();
				update_user_meta( $user_id, 'rcp_subscription_key', $subscription_key );
				update_user_meta( $user_id, 'rcp_subscription_level', $subscription_id );
				update_user_meta( $user_id, 'rcp_status', 'pending' );
				update_user_meta( $user_id, 'rcp_expiration', $member_expires );

				do_action( 'rcp_form_processing', $_POST, $user_id, $price );

				// process a paid subscription
				if( $price > '0' ) {

					if( ! empty( $discount ) ) {

						$discounts = new RCP_Discounts();
						$discount = $discounts->get_by( 'code', $discount );

						// calculate the after-discount price
						$price = $discounts->calc_discounted_price( $price, $discount->amount, $discount->unit );

						// record the usage of this discount code
						$discounts->add_to_user( $user_id, $discount );

						// incrase the usage count for the code
						$discounts->increase_uses( $discount->id );

						// if the discount is 100%, log the user in and redirect to success page
						if( $price == '0' ) {
							rcp_set_status( $user_id, 'active' );
							rcp_email_subscription_status( $user_id, 'active' );
							rcp_login_user_in( $user_id, $user_login, $user_pass );
							wp_redirect( rcp_get_return_url() ); exit;
						}

					}

					// this is a premium registration
					if( isset( $_POST['rcp_auto_renew'] ) ) {
						// set the user to recurring
						update_user_meta( $user_id, 'rcp_recurring', 'yes' );
						$auto_renew = true;

					} else {
						$auto_renew = false;
					}

					$redirect = rcp_get_return_url();

					$subscription_data = array(
						'price' 			=> $price,
						'length' 			=> $expiration->duration,
						'length_unit' 		=> strtolower( $expiration->duration_unit ),
						'subscription_name' => $subscription->name,
						'key' 				=> $subscription_key,
						'user_id' 			=> $user_id,
						'user_name' 		=> $user_data['login'],
						'user_email' 		=> $user_email,
						'currency' 			=> $rcp_options['currency'],
						'auto_renew' 		=> $auto_renew,
						'return_url' 		=> $redirect,
						'new_user' 			=> $user_data['need_new'],
						'post_data' 		=> $_POST
					);

					// get the selected payment method/gateway
					if( ! isset( $_POST['rcp_gateway'] ) ) {
						$gateway = 'paypal';
					} else {
						$gateway = $_POST['rcp_gateway'];
					}

					// send all of the subscription data off for processing by the gateway
					rcp_send_to_gateway( $gateway, apply_filters( 'rcp_subscription_data', $subscription_data ) );

				// process a free or trial subscription
				} else {

					// This is a free user registration or trial

					// if the subscription is a free trial, we need to record it in the user meta
					if( $member_expires != 'none' ) {

						// this is so that users can only sign up for one trial
						update_user_meta( $user_id, 'rcp_has_trialed', 'yes' );

						// activate the user's trial subscription
						rcp_set_status( $user_id, 'active' );

						rcp_email_subscription_status( $user_id, 'trial' );

					} else {

						// set the user's status to free
						rcp_set_status( $user_id, 'free' );

						rcp_email_subscription_status( $user_id, 'free' );
					}

					// date for trial / paid users, "none" for free users
					update_user_meta( $user_id, 'rcp_expiration', $member_expires );

					if( $user_data['need_new'] ) {

						if( ! isset( $rcp_options['disable_new_user_notices'] ) ) {

							// send an email to the admin alerting them of the registration
							wp_new_user_notification( $user_id) ;

						}

						// log the new user in
						rcp_login_user_in( $user_id, $user_data['login'], $user_data['password'] );

					}
					// send the newly created user to the redirect page after logging them in
					wp_redirect( rcp_get_return_url() ); exit;

				} // end price check

			} // end if new user id

		} // end if no errors

	} // end nonce check
}
add_action( 'init', 'rcp_process_registration', 100 );

// logs the specified user in
function rcp_login_user_in( $user_id, $user_login, $user_pass ) {
	$user = get_userdata( $user_id );
	if( ! $user )
		return;
	wp_set_auth_cookie( $user_id );
	wp_set_current_user( $user_id, $user_login );
	do_action( 'wp_login', $user_login, $user );
}

// logs a member in after submitting a form
function rcp_process_login_form() {

	if( isset( $_POST['rcp_action'] ) && $_POST['rcp_action'] == 'login' ) {
		if( isset( $_POST['rcp_login_nonce'] ) && wp_verify_nonce( $_POST['rcp_login_nonce'], 'rcp-login-nonce' ) ) {

			// this returns the user ID and other info from the user name
			$user = get_user_by( 'login', $_POST['rcp_user_login'] );

			do_action( 'rcp_before_form_errors', $_POST );

			if( !$user ) {
				// if the user name doesn't exist
				rcp_errors()->add( 'empty_username', __( 'Invalid username', 'rcp' ), 'login' );
			}

			if( !isset( $_POST['rcp_user_pass'] ) || $_POST['rcp_user_pass'] == '') {
				// if no password was entered
				rcp_errors()->add( 'empty_password', __( 'Please enter a password', 'rcp' ), 'login' );
			}

			if( $user ) {
				// check the user's login with their password
				if( !wp_check_password( $_POST['rcp_user_pass'], $user->user_pass, $user->ID ) ) {
					// if the password is incorrect for the specified user
					rcp_errors()->add( 'empty_password', __( 'Incorrect password', 'rcp' ), 'login' );
				}
			}
			do_action( 'rcp_login_form_errors', $_POST );

			// retrieve all error messages
			$errors = rcp_errors()->get_error_messages();

			// only log the user in if there are no errors
			if( empty( $errors ) ) {

				rcp_login_user_in( $user->ID, $_POST['rcp_user_login'], $_POST['rcp_user_pass'] );

				// redirect the user back to the page they were previously on
				wp_redirect( $_POST['rcp_redirect'] ); exit;
			}
		}
	}
}
add_action('init', 'rcp_process_login_form');

function rcp_reset_password() {
	// reset a users password
	if( isset( $_POST['rcp_action'] ) && $_POST['rcp_action'] == 'reset-password' ) {

		global $user_ID;

		if( !is_user_logged_in() )
			return;

		if( wp_verify_nonce( $_POST['rcp_password_nonce'], 'rcp-password-nonce' ) ) {

			do_action( 'rcp_before_password_form_errors', $_POST );

			if( $_POST['rcp_user_pass'] == '' || $_POST['rcp_user_pass_confirm'] == '' ) {
				// password(s) field empty
				rcp_errors()->add( 'password_empty', __( 'Please enter a password, and confirm it', 'rcp' ), 'password' );
			}
			if( $_POST['rcp_user_pass'] != $_POST['rcp_user_pass_confirm'] ) {
				// passwords do not match
				rcp_errors()->add( 'password_mismatch', __( 'Passwords do not match', 'rcp' ), 'password' );
			}

			do_action( 'rcp_password_form_errors', $_POST );

			// retrieve all error messages, if any
			$errors = rcp_errors()->get_error_messages();

			if( empty( $errors ) ) {
				// change the password here
				$user_data = array(
					'ID' 		=> $user_ID,
					'user_pass' => $_POST['rcp_user_pass']
				);
				wp_update_user( $user_data );
				// send password change email here (if WP doesn't)
				wp_redirect( add_query_arg( 'password-reset', 'true', $_POST['rcp_redirect'] ) );
				exit;
			}
		}
	}
}
add_action( 'init', 'rcp_reset_password' );


function rcp_validate_user_data() {

	$user = array();

	if( ! is_user_logged_in() ) {
		$user['login']		      = sanitize_text_field( $_POST['rcp_user_login'] );
		$user['email']		      = sanitize_text_field( $_POST['rcp_user_email'] );
		$user['first_name'] 	  = sanitize_text_field( $_POST['rcp_user_first'] );
		$user['last_name']	 	  = sanitize_text_field( $_POST['rcp_user_last'] );
		$user['password']		  = sanitize_text_field( $_POST['rcp_user_pass'] );
		$user['password_confirm'] = sanitize_text_field( $_POST['rcp_user_pass_confirm'] );
		$user['need_new']         = true;
	} else {
		$userdata 		  = get_userdata( $user_id );
		$user['id']       = $userdata->ID;
		$user['login'] 	  = $userdata->user_login;
		$user['email'] 	  = $userdata->user_email;
		$user['need_new'] = false;
	}


	if( $user['need_new'] ) {
		if( username_exists( $user['login'] ) ) {
			// Username already registered
			rcp_errors()->add( 'username_unavailable', __( 'Username already taken', 'rcp' ), 'register' );
		}
		if( ! validate_username( $user['login'] ) ) {
			// invalid username
			rcp_errors()->add( 'username_invalid', __( 'Invalid username', 'rcp' ), 'register' );
		}
		if( empty( $user['login'] ) ) {
			// empty username
			rcp_errors()->add( 'username_empty', __( 'Please enter a username', 'rcp' ), 'register' );
		}
		if( ! is_email( $user['email'] ) ) {
			//invalid email
			rcp_errors()->add( 'email_invalid', __( 'Invalid email', 'rcp' ), 'register' );
		}
		if( email_exists( $user['email'] ) ) {
			//Email address already registered
			rcp_errors()->add( 'email_used', __( 'Email already registered', 'rcp' ), 'register' );
		}
		if( empty( $user['password'] ) ) {
			// passwords do not match
			rcp_errors()->add( 'password_empty', __( 'Please enter a password', 'rcp' ), 'register' );
		}
		if( $user['password'] !== $user['password_confirm'] ) {
			// passwords do not match
			rcp_errors()->add( 'password_mismatch', __( 'Passwords do not match', 'rcp' ), 'register' );
		}
	}

	return apply_filters( 'rcp_user_registration_data', $user );
}