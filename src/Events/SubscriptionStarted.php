<?php

namespace AhmadChebbo\LaravelMontypay\Events;

/**
 * The first payment of a subscription settled and a recurring_token was issued
 * ($callback->recurringToken, ->recurringInitTransId, ->scheduleId, ->consentId).
 * Fired in addition to PaymentSettled, which is what to fulfil the order on.
 */
class SubscriptionStarted extends CallbackEvent
{
}
