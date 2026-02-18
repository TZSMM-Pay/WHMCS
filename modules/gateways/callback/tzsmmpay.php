<?php
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = "tzsmmpay";
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$invoiceId = (int) $_REQUEST['cus_country'];
$transactionId = htmlspecialchars($_POST['trx_id'] ?? $_GET['trx_id']);
$paymentFee = isset($_REQUEST['charge']) ? (float) $_REQUEST['charge'] : 0.0;
$apiKey = $gatewayParams['apiKey']; // Use our own API key from config for security

// Ensure necessary parameters are set
if (empty($invoiceId) || empty($transactionId)) {
    die('Missing required parameters');
}

// 1. Check if the transaction ID has already been processed
// This is the most important check to prevent multiple credits for the same transaction
checkCbTransID($transactionId);

// 2. Retrieve invoice from WHMCS database and check status
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    die('Invalid invoice ID');
}

if ($invoice->status == 'Paid') {
    die('Invoice already paid');
}

$paymentAmount = $invoice->balance;

// 3. Verify payment via the API
$hostname = rtrim($gatewayParams['hostName'] ?: 'https://tzsmmpay.com', "/");
$verifyUrl = $hostname . '/api/payment/verify';
$data = [
    "trx_id" => $transactionId,
    "api_key" => $apiKey, // Sent our secure API key
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
    logTransaction($gatewayModuleName, $_REQUEST, "cURL Error: " . $error_msg);
    die('cURL error: ' . $error_msg);
}
curl_close($curl);

// Decode the response
$responseData = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logTransaction($gatewayModuleName, $response, "JSON Decode Error");
    die('JSON error: ' . json_last_error_msg());
}

// 4. Validate Status and Amount
if ($responseData['status'] === 'Completed') {
    
    // Optional: Validate amount if returned by API
    // $receivedAmount = (float)($responseData['amount'] ?? $responseData['pay_amount'] ?? 0);
    // if ($receivedAmount > 0 && abs($receivedAmount - $paymentAmount) > 0.01) {
    //    logTransaction($gatewayModuleName, $responseData, "Amount Mismatch: Expected $paymentAmount, Received $receivedAmount");
    //    die("Amount Mismatch");
    // }

    // Log the successful callback
    logTransaction($gatewayModuleName, $_REQUEST, "Success");

    /**
     * Add Payment to Invoice
     *
     * @param int $invoiceId      Invoice ID
     * @param string $transactionId Transaction ID
     * @param float $paymentAmount  Amount Paid
     * @param float $paymentFee     Payment Fee
     * @param string $gatewayModuleName Gateway Module Name
     */
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );

    echo 'Payment Success and Invoice Marked as Paid';

} else {
    // If the payment is not completed
    $statusText = $responseData['status'] ?? 'Unknown Status';
    logTransaction($gatewayModuleName, $responseData, "Payment Failed or Pending: " . $statusText);
    echo 'Payment failed or is still pending. Status: ' . htmlspecialchars($statusText);
}