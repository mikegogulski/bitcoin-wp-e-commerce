<?php
/**
 * @file Bitcoin for the WP e-Commerce shopping cart plugin for WordPress
 * @author Mike Gogulski - http://www.nostate.com/ http://www.gogulski.com/
 *
 * Donations: 1DcZfySDvUoNBzf2mwReVy3VL93WtwnALr
 */
/*
 * This is free and unencumbered software released into the public domain.
 *
 * Anyone is free to copy, modify, publish, use, compile, sell, or
 * distribute this software, either in source code form or as a compiled
 * binary, for any purpose, commercial or non-commercial, and by any
 * means.
 *
 * In jurisdictions that recognize copyright laws, the author or authors
 * of this software dedicate any and all copyright interest in the
 * software to the public domain. We make this dedication for the benefit
 * of the public at large and to the detriment of our heirs and
 * successors. We intend this dedication to be an overt act of
 * relinquishment in perpetuity of all present and future rights to this
 * software under copyright law.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS BE LIABLE FOR ANY CLAIM, DAMAGES OR
 * OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 * For more information, please refer to <http://unlicense.org/>
 */

$nzshpcrt_gateways[$num]['name'] = 'Bitcoin';
$nzshpcrt_gateways[$num]['internalname'] = 'bitcoin';
$nzshpcrt_gateways[$num]['function'] = 'gateway_bitcoin';
$nzshpcrt_gateways[$num]['form'] = "form_bitcoin";
$nzshpcrt_gateways[$num]['submit_function'] = "submit_bitcoin";
$nzshpcrt_gateways[$num]['payment_type'] = "credit_card";

add_filter("the_content", "bitcoin_checkout_complete_display_filter", 99);
add_filter("wp_mail", "bitcoin_checkout_complete_mail_filter", 99);
add_filter("cron_schedules", "bitcoin_create_cron_schedule", 10);
add_action("bitcoin_cron", "bitcoin_cron");

register_deactivation_hook(__FILE__ . DIRECTORY_SEPARATOR . "../wp-shopping-cart.php", "bitcoin_disable_cron");

/**
 * Set up a custom cron schedule to run every 5 minutes.
 *
 * Invoked via the cron_schedules filter.
 *
 * @param array $schedules
 */
function bitcoin_create_cron_schedule($schedules = '') {
  $schedules['every5minutes'] = array(
    'interval' => 300,
    'display' => __('Every five minutes'),
  );
  return $schedules;
}

/**
 * Cancel the Bitcoin processing cron job.
 *
 * Invoked at deactivation of WP e-Commerce
 */
function bitcoin_disable_cron() {
  wp_clear_scheduled_hook("bitcoin_cron");
}

function bitcoin_debug($message) {
  error_log($message);
}

/**
 * Cron job to process outstanding Bitcoin transactions.
 */
function bitcoin_cron() {
  /*
   * Find transactions where purchase status = 1 and gateway = bitcoin.
   * Bitcoin address for the transaction is stored in transactid
   */
  global $wpdb;
  bitcoin_debug("entering cron");
  $transactions = $wpdb->get_results("SELECT id,totalprice,sessionid,transactid,date FROM " . WPSC_TABLE_PURCHASE_LOGS . " WHERE gateway='bitcoin' AND processed='1'");
  if (count($transactions) < 1)
    return;
  bitcoin_debug("have transactions to process");
  include_once("library/bitcoin.inc");
  $bitcoin_client = new BitcoinClient(get_option("bitcoin_scheme"),
    get_option("bitcoin_username"),
    get_option("bitcoin_password"),
    get_option("bitcoin_address"),
    get_option("bitcoin_port"),
    get_option("bitcoin_certificate_path"));

  if (TRUE !== ($fault = $bitcoin_client->can_connect())) {
    error_log('The Bitcoin server is presently unavailable. Fault: ' . $fault);
    return;
  }
  bitcoin_debug("server reachable");
  foreach ($transactions as $transaction) {
    $address = $transaction->transactid;
    $order_id = $transaction->id;
    $order_total = $transaction->totalprice;
    $sessionid = $transaction->sessionid;
    $order_date = $transaction->date;
    bitcoin_debug("processing: " . var_export($transaction, TRUE));
    try {
      $paid = $bitcoin_client->query("getreceivedbyaddress", $address, get_option("bitcoin_confirms"));
    } catch (BitcoinClientException $e) {
      error_log("Bitcoin server communication failed on getreceivedbyaddress " . $address . " with fault string " . $e->getMessage());
      continue;
    }
    if ($paid >= $order_total) {
      bitcoin_debug("paid in full");
      // PAID IN FULL
      // Update payment log
      $wpdb->query("UPDATE " . WPSC_TABLE_PURCHASE_LOGS . " SET processed='2' WHERE id='" . $order_id . "'");
      // Email customer
      transaction_results($sessionid, false);
      continue;
    }
    if (time() > $order_date + get_option("bitcoin_timeout") * 60 * 60) {
      bitcoin_debug("order expired");
      // ORDER EXPIRED
      // Update payment log
      $wpdb->query("UPDATE " . WPSC_TABLE_PURCHASE_LOGS . " SET processed='5' WHERE id='" . $order_id . "'");
      // Can't email the customer via transaction_results
      // TODO: Email the customer, delete the order
    }
  }
  bitcoin_debug("leaving cron");
}

function bitcoin_checkout_complete_display_filter($content = "") {
  if (!isset($_SESSION['bitcoin_address_display']) || empty($_SESSION['bitcoin_address_display']))
    return $content;
  $cart = unserialize($_SESSION['wpsc_cart']);
  $content = preg_replace('/@@TOTAL@@/', $cart->total_price, $content);
  $content = preg_replace('/@@ADDRESS@@/', $_SESSION['bitcoin_address_display'], $content);
  $content = preg_replace('/@@TIMEOUT@@/', get_option('bitcoin_timeout'), $content);
  $content = preg_replace('/@@CONFIRMATIONS@@/', get_option('bitcoin_confirms'), $content);
  unset($_SESSION['bitcoin_address_display']);
  return $content;
}

function bitcoin_checkout_complete_mail_filter($mail) {
  if (!isset($_SESSION['bitcoin_address_mail']) || empty($_SESSION['bitcoin_address_mail']))
    return $mail;
  $cart = unserialize($_SESSION['wpsc_cart']);
  $mail['message'] = preg_replace('/@@TOTAL@@/', $cart->total_price, $mail['message']);
  $mail['message'] = preg_replace('/@@ADDRESS@@/', $_SESSION['bitcoin_address_mail'], $mail['message']);
  $mail['message'] = preg_replace('/@@TIMEOUT@@/', get_option('bitcoin_timeout'), $mail['message']);
  $mail['message'] = preg_replace('/@@CONFIRMATIONS@@/', get_option('bitcoin_confirms'), $mail['message']);
  unset($_SESSION['bitcoin_address_mail']);
  return $mail;
}

function bitcoin_checkout_fail($sessionid, $message, $fault = "") {
  global $wpdb;
  $wpdb->query("UPDATE " . WPSC_TABLE_PURCHASE_LOGS . " SET processed='5' WHERE sessionid=" . $sessionid);
  $_SESSION['WpscGatewayErrorMessage'] = $message;
  $_SESSION['bitcoin'] = 'fail';
  error_log($message . ": " . $fault);
  header("Location: " . get_option("checkout_url"));
}

/**
 * Process Bitcoin checkout.
 *
 * @param string $separator
 * @param integer $sessionid
 * @todo Document better
 */
function gateway_bitcoin($separator, $sessionid) {
  global $wpdb, $wpsc_cart;

  include_once("library/bitcoin.inc");
  $bitcoin_client = new BitcoinClient(get_option("bitcoin_scheme"),
    get_option("bitcoin_username"),
    get_option("bitcoin_password"),
    get_option("bitcoin_address"),
    get_option("bitcoin_port"),
    get_option("bitcoin_certificate_path"));

  if (TRUE !== ($fault = $bitcoin_client->can_connect())) {
    bitcoin_checkout_fail($session, 'The Bitcoin server is presently unavailable. Please contact the site administrator.', $fault);
    return;
  }

  $row = $wpdb->get_row("SELECT id,totalprice FROM " . WPSC_TABLE_PURCHASE_LOGS . " WHERE sessionid=" . $sessionid);
  $label = $row->id . " " . $row->totalprice;
  try {
    $address = $bitcoin_client->query("getnewaddress", $label);
  } catch (BitcoinClientException $e) {
    bitcoin_checkout_fail($session, 'The Bitcoin server is presently unavailable. Please contact the site administrator.', $e->getMessage());
    return;
  }
  if (!Bitcoin::checkAddress($address)) {
    bitcoin_checkout_fail($session, 'The Bitcoin server returned an invalid address. Please contact the site administrator.', $e->getMessage());
    return;
  }
  //var_dump($_SESSION);
  unset($_SESSION['WpscGatewayErrorMessage']);
  // Set the transaction to pending payment and log the Bitcoin address as its transaction ID
  $wpdb->query("UPDATE " . WPSC_TABLE_PURCHASE_LOGS . " SET processed='1', transactid='" . $address . "' WHERE sessionid=" . $sessionid);
  $_SESSION['bitcoin'] = 'success';
  $_SESSION['bitcoin_address_display'] = $address;
  $_SESSION['bitcoin_address_mail'] = $address;
  header("Location: " . get_option('transact_url') . $separator . "sessionid=" . $sessionid);
  exit();
}

/**
 * Set Bitcoin payment options and start the cronjob.
 * @todo validate values
 */
function submit_bitcoin() {
  $options = array(
    "bitcoin_scheme",
    "bitcoin_certificate_path",
    "bitcoin_username",
    "bitcoin_password",
    "bitcoin_port",
    "bitcoin_address",
    "bitcoin_timeout",
    "bitcoin_confirms",
    "payment_instructions",
  );
  foreach ($options as $o)
    if ($_POST[$o] != NULL)
      update_option($o, $_POST[$o]);
  wp_clear_scheduled_hook("bitcoin_cron");
  wp_schedule_event(time(), "every5minutes", "bitcoin_cron");
  return true;
}

/**
 * Produce the HTML for the Bitcoin settings form.
 */
function form_bitcoin() {
  global $wpdb;
  $bitcoin_scheme = (get_option('bitcoin_scheme') == '' ? 'http' : get_option('bitcoin_scheme'));
  $bitcoin_certificate_path = get_option('bitcoin_certificate_path');
  $bitcoin_username = get_option('bitcoin_username');
  $bitcoin_password = get_option('bitcoin_password');
  $bitcoin_address = (get_option('bitcoin_address') == '' ? 'localhost' : get_option('bitcoin_address'));
  $bitcoin_port = (get_option('bitcoin_port') == '' ? '8332' : get_option('bitcoin_port'));
  $bitcoin_timeout = (get_option('bitcoin_timeout') == '' ? '72' : get_option('bitcoin_timeout'));
  $bitcoin_confirms = (get_option('bitcoin_confirms') == '' ? '0' : get_option('bitcoin_confirms'));
  if (get_option('payment_instructions') != '')
    $payment_instructions = get_option('payment_instructions');
  else {
    $payment_instructions = '<strong>Please send your payment of BTC @@TOTAL@@ to Bitcoin address @@ADDRESS@@.</strong> ';
    $payment_instructions .= 'If your payment is not received within @@TIMEOUT@@ hour(s) with at least @@CONFIRMATIONS@@ network confirmations, ';
    $payment_instructions .= 'your transaction will be canceled.';
  }

  // Create the Bitcoin currency if it doesn't already exist
  $sql = "SELECT currency FROM " . WPSC_TABLE_CURRENCY_LIST . " WHERE currency='Bitcoin'";
  if (!$wpdb->get_row($sql)) {
    $sql = "INSERT INTO " . WPSC_TABLE_CURRENCY_LIST . " VALUES (NULL, 'Bitcoin', 'BC', 'Bitcoin', '', '', 'BTC', '0', '0', 'antarctica', '1')";
    $wpdb->query($sql);
  }

  $output = "
		<tr>
			<td>&nbsp;</td>
			<td><small>Connection data for your bitcoin server HTTP-JSON-RPC interface.</small></td>
		</tr>
		<tr>
			<td>Server scheme (HTTP or HTTPS)</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_scheme . "' name='bitcoin_scheme' /></td>
		</tr>
		<tr>
			<td>SSL certificate path</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_certificate_path . "' name='bitcoin_certificate_path' /></td>
		</tr>
		<tr>
			<td>Server username</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_username . "' name='bitcoin_username' /></td>
		</tr>
		<tr>
			<td>Server password</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_password . "' name='bitcoin_password' /></td>
		</tr>
		<tr>
			<td>Server address (usually localhost)</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_address . "' name='bitcoin_address' /></td>
		</tr>
		<tr>
			<td>Server port (usually 8332)</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_port . "' name='bitcoin_port' /></td>
		</tr>
		<tr>
			<td>Transaction timeout (hours)</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_timeout . "' name='bitcoin_timeout' /></td>
		</tr>
		<tr>
			<td>Transaction confirmations required</td>
			<td><input type='text' size='40' value='"
    . $bitcoin_confirms . "' name='bitcoin_confirms' /></td>
		</tr>
		<tr>
			<td colspan='2'>
				<strong>Enter the template for payment instructions to be give to the customer on checkout.</strong><br />
				<textarea cols='40' rows='9' name='wpsc_options[payment_instructions]'>"
    . $payment_instructions . "</textarea><br />
    			Valid template tags:
    			<ul>
    				<li>@@TOTAL@@ - The order total</li>
    				<li>@@ADDRESS@@ - The Bitcoin address generated for the transaction</li>
    				<li>@@TIMEOUT@@ - Transaction timeout (hours)</li>
    				<li>@@CONFIRMATIONS@@ - Transaction confirmations required</li>
    			</ul>
			</td>
		</tr>
		<tr>
			<td colspan='2'>
				Like Bitcoin for WP e-Commerce? Your gifts to 1DcZfySDvUoNBzf2mwReVy3VL93WtwnALr are <strong>greatly</strong> appreciated. Thank you!
			</td>
		</tr>
	";
  return $output;
}
?>
