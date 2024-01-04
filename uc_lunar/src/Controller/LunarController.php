<?php

namespace Drupal\uc_lunar\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod\LunarMobilePayGateway;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\uc_order\Entity\Order;
use Drupal\uc_payment\Plugin\PaymentMethodManager;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Lunar\Exception\ApiException;
use Lunar\Lunar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

use Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod\LunarGatewayBase;

/**
 * 
 */
class LunarController extends ControllerBase implements ContainerInjectionInterface
{
  const REMOTE_URL = 'https://pay.lunar.money/?id=';
  const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

  private $paymentMethodManager;
  private $session;

  private $lunarApiClient;
  private $order;
  private $testMode;
  private $configuration;
  private $currencyCode;
  private $totalAmount;
  private $args = [];
  private $paymentMethodCode = '';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('plugin.manager.uc_payment.method'),
      $container->get('session')
    );
  }

  /**
   *
   */
  public function __construct(
    PaymentMethodManager $payment_method_manager,
    SessionInterface $session
  ) {
    $this->paymentMethodManager = $payment_method_manager;
    $this->session = $session;

    $request = \Drupal::request();
    $this->order = Order::load($request->get('uc_order'));

    $this->currencyCode = $this->order->getCurrency();
    $this->totalAmount = (string) $this->order->getTotal();

    $paymentMethod = $this->paymentMethodManager->createFromOrder($this->order);

    $this->paymentMethodCode = strstr($paymentMethod->getPluginId(), 'mobilepay') ? 'mobilePay' : 'card';

    $this->configuration = $paymentMethod->getConfiguration();

    $this->testMode = !!$request->cookies->get('lunar_testmode');

    if ($this->getConfig('app_key')) {
      $this->lunarApiClient = new Lunar($this->getConfig('app_key'), null, $this->testMode);
    }
  }

  /**
   * @return TrustedRedirectResponse
   */
  public function redirectToLunar()
  {
    $method = $this->paymentMethodManager->createFromOrder($this->order);

    if (
      !$this->session->has('cart_order')
      || intval($this->session->get('cart_order')) != $this->order->id()
      || !$method instanceof LunarGatewayBase
    ) {
      return $this->redirectWithNotification('Something was wrong. Try again');
    }

    $this->setArgs();

    try {
      $paymentIntentId = $this->lunarApiClient->payments()->create($this->args);
      $this->savePaymentIntent($paymentIntentId);
    } catch (ApiException $e) {
      return $this->redirectWithNotification($e->getMessage());
    }

    if (!$paymentIntentId) {
      return $this->redirectWithNotification('An error occurred creating payment intent. 
        Please try again or contact system administrator.');
    }

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
      return $this->redirectWithNotification($this->t('Thank you for your order! 
        You\'ll be notified once your payment has been processed.'));
    }

    $method = $this->paymentMethodManager->createFromOrder($this->order);
    if (!$method instanceof LunarGatewayBase) {
      return $this->redirect('uc_cart.checkout_review');
    }

    if (!($paymentIntentId = $this->order->data->uc_lunar['transactionId'])) {
      return $this->redirectWithNotification($this->t('No transaction ID found!'));
    }

    $isInstantMode = $this->getConfig('txn_type') == UC_CREDIT_AUTH_CAPTURE;

    try {
      $apiResponse = $this->lunarApiClient->payments()->fetch($paymentIntentId);

      if (!$this->parseApiTransactionResponse($apiResponse)) {
        return $this->redirectWithNotification('Something was wrong. Please contact system administrator.');
      }

      $cc_txns['authorizations'][$paymentIntentId] = [
        'amount' => $this->totalAmount,
        'authorized' => \Drupal::time()->getRequestTime(),
      ];

      $message = $this->t('The order successfully created and will be processed by administrator.');

      if ($isInstantMode) {
        $captureResponse = $this->lunarApiClient->payments()->capture($paymentIntentId, [
          'amount' => [
            'currency' => $this->currencyCode,
            'decimal' => $this->totalAmount,
          ]
        ]);

        if ('completed' != ($captureResponse['captureState'] ?? null)) {
          return $this->redirectWithNotification('Capture payment failed. Please try again or contact system administrator.');
        }

        $cc_txns['authorizations'][$paymentIntentId]['capturedAmount'] = $this->totalAmount;
        $cc_txns['authorizations'][$paymentIntentId]['captured'] = \Drupal::time()->getRequestTime();

        $message = $this->t('Payment processed successfully for @amount.', ['@amount' => uc_currency_format($this->totalAmount)]);
      }

      $this->order->data->cc_txns = $cc_txns;
      $this->order->save();

      uc_order_comment_save($this->order->id(), \Drupal::currentUser()->id(), $message, 'order');

    } catch (ApiException $e) {
      \Drupal::logger('uc_lunar')->error($e->getMessage());
      return $this->redirectWithNotification($e->getMessage());

    } catch (\Exception $e) {
      \Drupal::logger('uc_lunar')->error($e->getMessage());
      uc_order_comment_save($this->order->id(), \Drupal::currentUser()->id(), $e->getMessage(), 'admin');
      return $this->redirectWithNotification($e->getMessage());
    }

    $comment = $this->totalAmount . ' ' . $this->currencyCode . ' . Transaction ID: ' . $paymentIntentId;
    $comment = ($isInstantMode  ? 'Captured ' : 'Authorized ') . $comment;

    uc_payment_enter(
      $this->order->id(),
      $method->getPluginId(),
      $this->totalAmount,
      $uid = 0,
      $data = null,
      $comment,
      $received = null
    );

    // This lets us know it's a legitimate access of the complete page.
    $this->session->set('uc_checkout_complete_' . $this->order->id(), true);

    return $this->redirect('uc_cart.checkout_complete');
  }


  /**
   * @return void
   */
  private function setArgs()
  {
    $products = [];
    foreach ($this->order->products as $product) {
      $products[] = [
        'ID' => $product->model->value, // SKU
        'name' => $product->title->value,
        'quantity' => $product->qty->value,
      ];
    }

    $address = $this->order->getAddress('billing');

    $this->args = [
      'integration' => [
        'key' => $this->configuration['public_key'],
        'name' => $this->getShopTitle(),
        'logo' => $this->configuration['logo_url'],
      ],
      'amount' => [
        'currency' => $this->currencyCode,
        'decimal' => $this->totalAmount,
      ],
      'custom' => [
        'orderId' => $this->order->id(),
        'products' => $products,
        'customer' => [
          'email' => $this->order->getEmail(),
          'IP' => \Drupal::request()->getClientIp(),
          'name' => $address->getFirstName() . ' ' . $address->getLastName(),
          'address' => $address->getStreet1() . ' ' . $address->getCity() . ' ' . $address->getZone() . ' ' .
            $address->getPostalCode() . ' ' . $address->getCountry(),
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
      'redirectUrl' => Url::fromRoute(
        'uc_lunar.callback',
        ['uc_order' => $this->order->id()],
        ['absolute' => true]
      )->toString(),
      'preferredPaymentMethod' => $this->paymentMethodCode,
    ];

    if ($this->configuration['configuration_id']) {
      $this->args['mobilePayConfiguration'] = [
        'configurationID' => $this->configuration['configuration_id'],
        'logo' => $this->configuration['logo_url'],
      ];
    }

    if ($this->testMode) {
      $this->args['test'] = $this->getTestObject();
    }
  }

  /**
   * Parses api transaction response for errors
   */
  private function parseApiTransactionResponse($transaction): bool
  {
    if (!$this->isTransactionSuccessful($transaction)) {
      return false;
    }

    return true;
  }

  /**
   * Checks if the transaction was successful and
   * the data was not tempered with.
   */
  private function isTransactionSuccessful($transaction): bool
  {
    $matchCurrency = $this->currencyCode == ($transaction['amount']['currency'] ?? '');
    $matchAmount = $this->totalAmount == ($transaction['amount']['decimal'] ?? '');

    return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
  }

  /**
   * Gets errors from a failed api request
   * @param array $result The result returned by the api wrapper.
   */
  private function getResponseError($result): string
  {
    $error = [];
    // if this is just one error
    if (isset($result['text'])) {
      return $result['text'];
    }

    if (isset($result['declinedReason'])) {
      return $result['declinedReason']['error'];
    }

    // otherwise this is a multi field error
    if ($result) {
      foreach ($result as $fieldError) {
        if (isset($fieldError['field']) && isset($fieldError['message'])) {
          $error[] = $fieldError['field'] . ':' . $fieldError['message'];
        } else {
          $error[] = 'General error';
        }
      }
    }

    return implode(' ', $error);
  }

  /**
   * 
   */
  private function redirectWithNotification($message)
  {
    $this->messenger()->addError($message);
    return $this->redirect('uc_cart.checkout_review');
  }

  /**
   * @return void
   */
  private function savePaymentIntent($paymentIntentId)
  {
    $this->order->data->uc_lunar = ['transactionId' => $paymentIntentId];
    $this->order->save();
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
          "currency" => $this->currencyCode,

        ],
        "balance" => [
          "decimal"  => "25000.99",
          "currency" => $this->currencyCode,

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
