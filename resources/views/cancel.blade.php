<!DOCTYPE html>
<html>
<head>
    <title>Payment Canceled</title>
</head>
<body>
    <div class="container">
        <h1>Payment Canceled</h1>
        <p>Your payment was canceled.</p>
        @isset($payment['order_id'])
            <p>Order: {{ $payment['order_id'] }}</p>
        @endisset
    </div>
</body>
</html>
