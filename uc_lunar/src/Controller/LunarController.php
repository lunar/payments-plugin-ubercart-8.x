<?php

namespace Drupal\uc_lunar\Controller;

use Drupal\Core\Url;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod\LunarMobilePayGateway;
use Drupal\uc_order\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;

use Lunar\Lunar;

/**
 * 
 */
class LunarController extends ControllerBase
{
  const REMOTE_URL = 'https://pay.lunar.money/?id=';
  const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

  private $paymentMethodManager;
  private $session;
  private $dateTime;
  private $database;
  private $requestContext;


  private $apiClient;
  private $order;
  private $testMode;
  private $configuration;
  private $paymentMethodCode = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.uc_payment.method'),
      $container->get('session'),
      $container->get('datetime.time'),
      $container->get('database'),
      $container->get('router.request_context')
    );
  }

  /**
   *
   */
  public function __construct(
    PaymentMethodManager $payment_method_manager,
    SessionInterface $session,
    TimeInterface $date_time,
    Connection $database,
    RequestContext $requestContext
  ) {
    $this->paymentMethodManager = $payment_method_manager;
    $this->session = $session;
    $this->dateTime = $date_time;
    $this->database = $database;
    $this->requestContext = $requestContext;

    $orderId = str_replace('uc_order=', '', $this->requestContext->getQueryString());

    $this->order = Order::load($orderId);

    $paymentMethod = $this->paymentMethodManager->createFromOrder($this->order);

    $this->configuration = $paymentMethod->getConfiguration();

    $this->testMode = true; // Get it from cookie

    if ($this->getConfig('app_key')) {
      $this->apiClient = new Lunar($this->getConfig('app_key'), null, $this->testMode);
    }
  }



  /**
   * @return TrustedRedirectResponse
   */
  public function redirectToLunar()
  {
    $order = $this->order;

    $method = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($order);

    if (
      !$this->session->has('cart_order')
      || intval($this->session->get('cart_order')) != $order->id()
      || !$method instanceof LunarMobilePayGateway
    ) {
      return $this->redirect('uc_cart.cart');
    }


    $products = [];
    foreach ($order->products as $product) {
      $products[] = [
        'ID' => $product->id(),
        'name' => $product->title->value,
        'quantity' => $product->qty->value,
      ];
    }

    $args = [
      'integration' => [
        'key' => $this->configuration['public_key'],
        'name' => $this->getShopTitle(),
        'logo' => $this->configuration['logo_url'],
      ],
      'amount' => [
        'currency' => $order->getCurrency(),
        'decimal' => (string) $order->getTotal(),
      ],
      'custom' => [
        'orderId' => $order->id(),
        'products' => $products,
        'customer' => [
          'email' => $order->getEmail(),
          'IP' => \Drupal::request()->getClientIp(),
          'name' => '', // @TODO
          'address' => $order->getAddress('billing')->__toString(),
        ],
        'platform' => [
          'name' => 'Drupal',
          'version' => \DRUPAL::VERSION,
        ],
        'ecommerce' => [
          'name' => 'Ubercart',
          'version' => \Drupal::service('extension.list.module')->getExtensionInfo('uc_cart')['version'],
        ],
        'lunarPluginVersion' => [
          'version' => '2.0.0.0', // @TODO get it properly
        ],
      ],
      'redirectUrl' => Url::fromRoute('uc_lunar.complete', ['uc_order' => $order->id()], ['absolute' => true])->toString(),
      'preferredPaymentMethod' => $this->paymentMethodCode,
    ];


    if ($this->configuration['configuration_id']) {
      $args['mobilePayConfiguration'] = [
        'configurationID' => $this->configuration['configuration_id'],
        'logo' => $this->configuration['logo_url'],
      ];
    }

    if (true) {
      $args['test'] = $this->getTestObject();
    }

    $paymentIntentId = $this->apiClient->payments()->create($args);


    $redirectUrl = ($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId;


    return new TrustedRedirectResponse($redirectUrl);
  }

  /**
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the cart or checkout complete page.
   */
  public function complete(OrderInterface $uc_order)
  {
    if (!$this->session->has('cart_order') || intval($this->session->get('cart_order')) != $uc_order->id()) {
      $this->messenger()->addMessage($this->t('Thank you for your order! 
        You\'ll be notified once your payment has been processed.'));
      return $this->redirect('uc_cart.cart');
    }

    $method = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($uc_order);
    if (!$method instanceof LunarMobilePayGateway) {
      return $this->redirect('uc_cart.cart');
    }

    // This lets us know it's a legitimate access of the complete page.
    $this->session->set('uc_checkout_complete_' . $uc_order->id(), true);

    return $this->redirect('uc_cart.checkout_complete');
  }

  
  /**
   * @return string
   */
  private function getShopTitle()
  {
    return $this->getConfig('shop_title') ?? \Drupal::config('system.site')->get('name');
  }

  /**
   * 
   */
  private function getConfig($key)
  {
    return !empty($this->configuration[$key]) ? $this->configuration[$key] : null;
  }

  /**
   *
   */
  private function getTestObject(): array
  {
    return [
      "card"        => [
        "scheme"  => "supported",
        "code"    => "valid",
        "status"  => "valid",
        "limit"   => [
          "decimal"  => "25000.99",
          // "currency" => $this->order->info['currency'],
          "currency" => 'USD',

        ],
        "balance" => [
          "decimal"  => "25000.99",
          // "currency" => $this->order->info['currency'],
          "currency" => 'USD',

        ]
      ],
      "fingerprint" => "success",
      "tds"         => array(
        "fingerprint" => "success",
        "challenge"   => true,
        "status"      => "authenticated"
      ),
    ];
  }
}
