<!DOCTYPE html>
<html>
<head>
    <title>Payment Successful</title>
</head>
<body>
    <div class="container">
        <h1>Payment Successful</h1>
        <p>Thank you for your payment.</p>
        @isset($payment['order_id'])
            <p>Order: {{ $payment['order_id'] }}</p>
        @endisset
    </div>
</body>
</html>
