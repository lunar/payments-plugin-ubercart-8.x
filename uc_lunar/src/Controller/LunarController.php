<?php

namespace Drupal\uc_lunar\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod\LunarMobilePayGateway;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Lunar\Lunar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * 
 */
class LunarController extends ControllerBase implements ContainerInjectionInterface
{
  const REMOTE_URL = 'https://pay.lunar.money/?id=';
  const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

  private $paymentMethodManager;
  private $session;
  private $dateTime;
  private $database;


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
      $container->get('database')
    );
  }

  /**
   *
   */
  public function __construct(
    PaymentMethodManager $payment_method_manager,
    SessionInterface $session,
    TimeInterface $date_time,
    Connection $database
  ) {
    $this->paymentMethodManager = $payment_method_manager;
    $this->session = $session;
    $this->dateTime = $date_time;
    $this->database = $database;

    $request = \Drupal::request();
    $this->order = Order::load($request->get('uc_order'));

    $paymentMethod = $this->paymentMethodManager->createFromOrder($this->order);

    $this->configuration = $paymentMethod->getConfiguration();

    $this->testMode = !! $request->cookies->get('lunar_testmode');

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
          'version' => Yaml::parseFile(dirname(__DIR__, 2) . '/uc_lunar.info.yml')['version'],
        ],
      ],
      'redirectUrl' => Url::fromRoute('uc_lunar.callback', ['uc_order' => $order->id()], 
                        ['absolute' => true])->toString(),
      'preferredPaymentMethod' => $this->paymentMethodCode,
    ];


    if ($this->configuration['configuration_id']) {
      $args['mobilePayConfiguration'] = [
        'configurationID' => $this->configuration['configuration_id'],
        'logo' => $this->configuration['logo_url'],
      ];
    }

    if ($this->testMode) {
      $args['test'] = $this->getTestObject();
    }

    $paymentIntentId = $this->apiClient->payments()->create($args);

    $redirectUrl = ($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId;

    $response = new TrustedRedirectResponse($redirectUrl, Response::HTTP_FOUND);
    $response->send();

    // Have to exit as a return will add additional headers and html code.
    exit;
  }

  /**
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the cart or checkout complete page.
   */
  public function callback()
  {
    if (!$this->session->has('cart_order') || intval($this->session->get('cart_order')) != $this->order->id()) {
      $this->messenger()->addMessage($this->t('Thank you for your order! 
        You\'ll be notified once your payment has been processed.'));
      return $this->redirect('uc_cart.cart');
    }

    $method = \Drupal::service('plugin.manager.uc_payment.method')->createFromOrder($this->order);
    if (!$method instanceof LunarMobilePayGateway) {
      return $this->redirect('uc_cart.cart');
    }

    // This lets us know it's a legitimate access of the complete page.
    $this->session->set('uc_checkout_complete_' . $this->order->id(), true);

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
