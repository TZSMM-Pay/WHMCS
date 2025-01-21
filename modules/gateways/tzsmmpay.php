<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function tzsmmpay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'TZSMM PAY Gateway',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'This is the TZSMM PAY payment gateway module for WHMCS, which allows users to process payments securely.',
        ],
        'DeveloperName' => [
            'Type' => 'System',
            'Value' => 'Zihadur Rahman Dev',
        ],
        'Version' => [
            'Type' => 'System',
            'Value' => '1.0.0', // Update the version as needed
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '40',
        ],
    ];
}

function tzsmmpay_link($params)
{
    $invoiceId = $params['invoiceid'];
    $payTxt = $params['langpaynow'];
    $errorMsg = tzsmmpay_errormessage();
    $sessionMessage = $_SESSION["tzsmmpay_pending_invoice_id_{$invoiceId}"];

    if (isset($sessionMessage) && !empty($sessionMessage)) {
        return '<div class="alert alert-danger" style="margin-top: 10px;" role="alert">' . $sessionMessage . '</div>';
    }

    $response = tzsmmpay_createPayment($params);
    if ($response->status) {
        $paymentUrl = $response->payment_url;
        return <<<HTML
        <form method="GET" action="$paymentUrl">
            <input class="btn btn-primary" type="submit" value="$payTxt" />
        </form>
        $errorMsg
HTML;
    } else {
        return '<div class="alert alert-danger" style="margin-top: 10px;" role="alert">' . $response->message . '</div>';
    }
}

function tzsmmpay_createPayment($params)
{
    $customer = $params['clientdetails'];
    $apikey = $params['apiKey'];
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];

    // Get WHMCS system URL
    $systemUrl = WHMCS\Config\Setting::getValue('SystemURL');
    $callbackUrl = $systemUrl . '/modules/gateways/callback/tzsmmpay.php';
    $successUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    $cancelUrl = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;

    $data = [
        "api_key" => $apikey,
        "cus_name" => $customer['firstname'] . " " . $customer['lastname'],
        "cus_number" => $customer['phonenumber'] ?? 000,
        "cus_email" => $customer['email'],
        "cus_country" => $invoiceId,
        "cus_city" => $customer['city'],
        "amount" => $amount,
        "success_url" => $successUrl,
        "callback_url" => $callbackUrl,
        "cancel_url" => $cancelUrl,
    ];

    $url = 'https://tzsmmpay.com/api/payment/create';
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($curl);

    if (curl_errno($curl)) {
        $error_message = curl_error($curl);
        curl_close($curl);
        return (object) [
            'status' => false,
            'message' => 'cURL Error: ' . $error_message,
        ];
    }

    curl_close($curl);

    $response = json_decode($response, true);

    if (isset($response['trx_id'])) {
        $paymentUrl = 'https://tzsmmpay.com/api/payment/' . $response['trx_id'];
        return (object) [
            'status' => true,
            'payment_url' => $paymentUrl,
        ];
    } else {
        return (object) [
            'status' => false,
            'message' => isset($response['message']) ? $response['message'] : 'An error occurred.',
        ];
    }
}


function tzsmmpay_errormessage()
{
    $errorMessage = [
        'cancelled' => 'Payment has been cancelled',
        'irs' => 'Invalid response from TZSMM API.',
        'tau' => 'The transaction has already been used.',
        'lpa' => 'You\'ve paid less than the required amount.',
        'pfv' => 'Your payment is pending verification.',
        'sww' => 'Something went wrong'
    ];

    $code = isset($_REQUEST['error']) ? $_REQUEST['error'] : null;
    if (empty($code)) {
        return null;
    }

    $error = isset($errorMessage[$code]) ? $errorMessage[$code] : $code;

    return '<div class="alert alert-danger" style="margin-top: 10px;" role="alert">' . $error . '</div>';
}
?>