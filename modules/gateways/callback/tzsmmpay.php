<?php
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$invoiceId = (int) $_REQUEST['cus_country'];
$transactionId = htmlspecialchars($_POST['trx_id']);
$paymentFee = isset($_REQUEST['charge']) ? (float) $_REQUEST['charge'] : 0.0; // default to 0 if not set
$gatewayModuleName = "tzsmmpay";
$api_key = htmlspecialchars($_REQUEST['api_key']);

// Ensure necessary parameters are set
if (empty($invoiceId) || empty($transactionId) || empty($api_key)) {
 die('Missing required parameters');
}

// Verify payment via the API
$verifyUrl = 'https://tzsmmpay.com/api/payment/verify';
$data = [
 "trx_id" => $transactionId,
 "api_key" => $api_key,
];

$curl = curl_init($verifyUrl);
curl_setopt_array($curl, [
 CURLOPT_RETURNTRANSFER => true,
 CURLOPT_POST => true,
 CURLOPT_POSTFIELDS => http_build_query($data),
 CURLOPT_HTTPHEADER => [
  'Content-Type: application/x-www-form-urlencoded',
 ],
]);

$response = curl_exec($curl);
if (curl_errno($curl)) {
 $error_msg = curl_error($curl);
 curl_close($curl);
 die('cURL error: ' . $error_msg);
}

curl_close($curl);

// Decode the response
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
 die('JSON error: ' . json_last_error_msg() . '<br>Raw response: ' . htmlspecialchars($response));
}

// Retrieve invoice from WHMCS database
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
 die('Invalid invoice ID');
}

$paymentAmount = $invoice->balance;

// Check if the payment was successful and matches the required amount
if ($data['status'] === 'Completed') {
 // Add payment to invoice
 addInvoicePayment(
  $invoiceId,
  $transactionId,
  $paymentAmount,
  $paymentFee,
  $gatewayModuleName
 );

 // Update invoice status to 'Paid'
 $command = 'UpdateInvoice';
 $postData = [
  'invoiceid' => $invoiceId,
  'status' => 'Paid'
 ];
 $adminUsername = 'admin'; // Replace with your WHMCS admin username
 $results = localAPI($command, $postData, $adminUsername);

 if ($results['result'] === 'success') {
  echo 'Payment Success and Invoice Marked as Paid';
 } else {
  echo 'Payment added but failed to mark invoice as paid: ' . htmlspecialchars($results['message']);
 }
} else {
 // If the payment is not completed or the amounts do not match
 echo 'Payment failed. Response Data: ' . print_r($data, true) . '<br>';
 echo 'Request Data: ' . json_encode($_POST);
}
?>