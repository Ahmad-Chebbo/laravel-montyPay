<!DOCTYPE html>
<html>
<head>
    <title>Payment Declined</title>
</head>
<body>
    <div class="container">
        <h1>Payment Declined</h1>
        <p>Your payment could not be completed. Please try again.</p>
        @isset($payment['order_id'])
            <p>Order: {{ $payment['order_id'] }}</p>
        @endisset
    </div>
</body>
</html>
