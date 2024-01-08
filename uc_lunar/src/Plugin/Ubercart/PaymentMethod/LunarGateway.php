<?php

namespace Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod;

/**
 *  Ubercart gateway payment method.
 *
 *
 * @UbercartPaymentMethod(
 *   id = "lunar_gateway",
 *   name = @Translation("Lunar gateway"),
 * )
 */
class LunarGateway extends LunarGatewayBase
{
  protected $paymentMethodCode = 'card';
}
