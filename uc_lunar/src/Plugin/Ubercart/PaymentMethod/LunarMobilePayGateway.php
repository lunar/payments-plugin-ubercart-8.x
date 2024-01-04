<?php

namespace Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod;

/**
 *  Ubercart gateway payment method.
 *
 *
 * @UbercartPaymentMethod(
 *   id = "lunarmobilepay_gateway",
 *   name = @Translation("Lunar MobilePay gateway"),
 * )
 */
class LunarMobilePayGateway extends LunarGatewayBase
{
  protected $paymentMethodCode = 'mobilePay';
}
