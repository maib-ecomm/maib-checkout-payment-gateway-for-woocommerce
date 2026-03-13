<?php
require __DIR__  . '/config.php';

$baseUrl = "https://sandbox.maibmerchants.md/v2/"; // optional, if is not provided, the production url will be used by default

// Get Access Token with Client ID and Client Secret
$auth = MaibCheckoutAuthRequest::create($baseUrl)->generateToken(CLIENT_ID, CLIENT_SECRET);

$token = $auth->accessToken;

$paymentId = 'g45b2d61-5739-4425-9ebb-7861002e8b10';

// Get payment request
$paymentResponse = MaibCheckoutApiRequest::create($baseUrl)->getPayment($paymentId, $token);

if (isset($paymentResponse->ok) && $paymentResponse->ok){
    $jsonData = json_encode($paymentResponse->result);
    echo $jsonData;
}
else{
    foreach ($paymentResponse->errors as $error) {
        echo $error->errorCode . ": " . $error->errorMessage;
    }
}
