<?php

namespace Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\uc_credit\CreditCardPaymentMethodBase;
use Drupal\uc_order\OrderInterface;

use Paylike\Exception\ApiException;
use Paylike\Data\Currencies;

/**
 *  Ubercart gateway payment method.
 *
 *
 * @UbercartPaymentMethod(
 *   id = "lunar_mobilepay_gateway",
 *   name = @Translation("Lunar MobilePay gateway"),
 * )
 */
class LunarMobilePayGateway extends CreditCardPaymentMethodBase
{

  /** Lunar Ubercart plugin version. */
  const MODULE_VERSION = '8.x-2.0';
  /**
   * @var \Paylike\Paylike
   */
  protected $apiClient;

  /**
   * {@inheritdoc}
   */
  public function getTransactionTypes()
  {
    return [
      UC_CREDIT_AUTH_ONLY,
      UC_CREDIT_AUTH_CAPTURE,
      UC_CREDIT_PRIOR_AUTH_CAPTURE,
      UC_CREDIT_VOID,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return parent::defaultConfiguration() + [
      'txn_type' => UC_CREDIT_AUTH_CAPTURE,
      'public_key' => '',
      'app_key' => '',
      'logo_url' => '',
      'configuration_id' => '',
      'description' => '',
      'shop_title' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['app_key'] = [
      '#type' => 'textfield',
      '#title' => t('App Key'),
      '#description' => t('Private API key can be obtained by creating a merchant and adding an app through Lunar <a href="@dashboard" target="_blank">dashboard</a>.', ['@dashboard' => 'https://lunar.app/']),
      '#default_value' => $this->configuration['app_key'],
    ];

    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => t('Public Key'),
      '#description' => t('Public API key.'),
      '#default_value' => $this->configuration['public_key'],
    ];

    $form['logo_url'] = [
      '#type' => 'textfield',
      '#title' => t('Logo URL'),
      '#description' => t('A valid url to shop logo'),
      '#default_value' => $this->configuration['logo_url'],
    ];

    $form['configuration_id'] = [
      '#type' => 'textfield',
      '#title' => t('Configuration ID'),
      '#description' => t('Email onlinepayments@lunar.app to get it'),
      '#default_value' => $this->configuration['configuration_id'],
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => t('Payment method description'),
      '#description' => t('Description on checkout page.'),
      '#default_value' => $this->configuration['description'],
    ];

    $form['shop_title'] = [
      '#type' => 'textfield',
      '#title' => t('Popup title'),
      '#description' => t('Payment popup title. Leave blank to show the site name.'),
      '#default_value' => $this->configuration['popup_title'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    $items = [
      'app_key',
      'public_key',
      'logo_url',
      'configuration_id',
      'description',
      'shop_title',
    ];
    foreach ($items as $item) {
      $this->configuration[$item] = $form_state->getValue($item);
    }

    return parent::submitConfigurationForm($form, $form_state);
  }

  public function cartDetails(OrderInterface $order, array $form, FormStateInterface $form_state)
  {
    $form = [
      '#type' => 'container',
      '#attributes' => ['class' => 'uc-lunar-form'],
    ];

    // Products info
    $products = [];
    foreach ($order->products as $product) {
      $products[$product->id()] = [
        'id' => $product->id(),
        'SKU' => $product->model->value,
        'title' => $product->title->value,
        'price' => uc_currency_format($product->price->value),
        'quantity' => $product->qty->value,
        'total' => uc_currency_format($product->price->value * $product->qty->value),
      ];
    }

    $ubercartInfo = \Drupal::service('extension.list.module')->getExtensionInfo('uc_cart');

    $testMode = true; // !! USE cookie

    $currency = $order->getCurrency();

    $allCurrencies = (new Currencies)->all();

    $exponent = isset($allCurrencies[$currency]) ? ($allCurrencies[$currency]['exponent']) : (null);

    // Payment popup settings
    $config = [
      'locale' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
      'title' => $this->getPopupTitle(),
      'test' => $testMode,
      'amount' => [
        'currency' => $currency,
        'exponent' => $exponent,
        'value' => (int)(uc_currency_format($order->getTotal(), false, false, false)),
      ],
      'custom' => [
        'email' => $order->getEmail(),
        'orderId' => $order->id(),
        'products' => $products,
        'customer' => [
          'email' => $order->getEmail(),
          'IP' => \Drupal::request()->getClientIp(),
          'name' => '', // Empty at this moment
          'address' => '',
        ],
        'platform' => [
          'name' => 'Drupal',
          'version' => \DRUPAL::VERSION,
        ],
        'ecommerce' => [
          'name' => 'Ubercart',
          'version' => $ubercartInfo['version'],
        ],
        'lunarPluginVersion' => [
          'version' => self::MODULE_VERSION,
        ],
      ],
    ];

    $form['#attached']['drupalSettings']['uc_lunar'] = [
      'publicKey' => $this->configuration['public_key'],
      'config' => $config,
    ];
    $form['#attached']['library'][] = 'uc_lunar/form';

    $form['lunar_transaction_id'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'id' => 'lunar_transaction_id',
      ],
    ];
    $form['lunar_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Enter credit card details'),
      '#attributes' => [
        'class' => ['lunar-button'],
      ],
      '#prefix' => $this->getPaymentMethodDescription(),
    ];
    // Needed to remove cc_number notice on submitForm().
    $form['cc_number'] = [
      '#type' => 'hidden',
      '#value' => '1111',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function cartProcess(OrderInterface $order, array $form, FormStateInterface $form_state)
  {
    $transactionId = $form_state->getValue(['panes', 'payment', 'details', 'lunar_transaction_id']);

    // Load transaction ID from order
    if (empty($transactionId) && !empty($order->data->uc_lunar['transaction_id'])) {
      $transactionId = $order->data->uc_lunar['transaction_id'];
    }

    if (!empty($transactionId)) {
      $transaction = $this->getTransaction($transactionId);
      $card = $transaction['card'];
      $expiry = new \DateTime($card['expiry']);

      $data = [
        'transaction_id' => $transactionId,
        'payment_details' => [
          'cc_number' => $card['last4'],
          'cc_type' => $card['scheme'],
          'cc_exp_month' => $expiry->format('m'),
          'cc_exp_year' => $expiry->format('Y'),
        ],
      ];
      $order->data->uc_lunar = $data;
    } else {
      $form_state->setErrorByName('panes][payment][details][lunar_button', $this->t('Payment failed.'));
      return false;
    }
    return true;
  }

  /**
   * {@inheritdoc}
   */
  protected function chargeCard(OrderInterface $order, $amount, $txn_type, $reference = null)
  {
    $user = \Drupal::currentUser();

    if (!$this->prepareApi()) {
      $result = [
        'success' => false,
        'comment' => $this->t('Lunar API not found.'),
        'message' => $this->t('Lunar API not found. Contact the site administrator please.'),
        'uid' => $user->id(),
        'order_id' => $order->id(),
      ];
      return $result;
    }

    if (isset($_POST['select_auth']) && !empty($_POST['select_auth'])) {
      // Transaction selected from prior authorizations list on CreditCardTerminalForm
      $transactionId = Html::escape($_POST['select_auth']);
    } elseif (isset($_POST['cc_data']['lunar_transaction_id']) && !empty($_POST['cc_data']['lunar_transaction_id'])) {
      // Transaction made by administrator on CreditCardTerminalForm.
      $transactionId = Html::escape($_POST['cc_data']['lunar_transaction_id']);
    } else {
      // Transaction made by customer
      $transactionId = $order->data->uc_lunar['transaction_id'];
    }

    try {
      $formattedAmount = uc_currency_format($amount);
      $intAmount = uc_currency_format($amount, false, false, false);
      $transactions = $this->apiClient->transactions();

      //TODO: remove this when CreditCardTerminalForm->submitForm() will return correct txn_type.
      if (is_null($txn_type)) $txn_type = $this->getTransactionType();

      switch ($txn_type) {
        case UC_CREDIT_AUTH_CAPTURE:
        case UC_CREDIT_PRIOR_AUTH_CAPTURE:
          $transaction = $transactions->capture($transactionId, ['amount' => $intAmount]);
          if ($transaction['successful']) {
            // TODO: uc_credit_log_prior_auth_capture() is not working here ($order overrides data on save). Check this in newer versions.
            // uc_credit_log_prior_auth_capture($order->id(), $transactionId);
            $txns = $order->data->cc_txns;
            $txns['authorizations'][$transactionId]['captured'] = \Drupal::time()->getRequestTime();
            $txns['authorizations'][$transactionId]['capturedAmount'] = (float) $amount;
            $order->data->cc_txns = $txns;
            $order->save();
            $message = $this->t('Payment processed successfully for @amount.', ['@amount' => $formattedAmount]);
          }
          break;
        case UC_CREDIT_AUTH_ONLY:
          $transaction = $transactions->fetch($transactionId);
          if ($transaction['successful']) {
            uc_credit_log_authorization($order->id(), $transactionId, $amount);
            $message = $this->t('The order successfully created and will be processed by administrator.');
          }
          break;
        case UC_CREDIT_VOID:
          $transaction = $transactions->void($transactionId, ['amount' => $intAmount]);
          if ($transaction['successful']) {
            $message = $this->t('The transaction successfully voided.');
            // Hide authorization from select list on CreditCardTerminalForm
            $txns = $order->data->cc_txns;
            unset($txns['authorizations'][$transactionId]);
            $order->data->cc_txns = $txns;

            $order->setStatusId('canceled');
            $order->save();
          }
          break;
      }

      if ($transaction['successful']) {
        uc_order_comment_save($order->id(), $user->id(), $message, 'order');

        $result = [
          'success' => true,
          'comment' => $message,
          'message' => $message,
          'uid' => $user->id(),
        ];

        // Don't create receipts for authorizations and voids.
        if ($txn_type == UC_CREDIT_AUTH_ONLY || $txn_type == UC_CREDIT_VOID) $result['log_payment'] = false;

        return $result;
      } else {
        throw new \Exception($transaction['error']);
      }
    } catch (\Exception $e) {
      $message = $this->t('Credit card process failed. Transaction ID: @id. Message: @error', ['@id' => $transactionId, '@error' => $e->getMessage()]);
      $userMessage = $this->t('Credit card process failed. Contact the site administrator please.');

      $result = [
        'success' => false,
        'comment' => $message,
        'message' => $userMessage,
        'uid' => $user->id(),
        'order_id' => $order->id(),
      ];

      uc_order_comment_save($order->id(), $user->id(), $userMessage, 'order');
      uc_order_comment_save($order->id(), $user->id(), $message, 'admin');

      \Drupal::logger('uc_lunar')->error($message);
      return $result;
    }
  }

  /**
   * Returns popup title.
   * @return string
   */
  public function getPopupTitle()
  {
    return !empty($this->configuration['popup_title']) ? $this->configuration['popup_title'] : \Drupal::config('system.site')->get('name');
  }

  /**
   * Returns payment method description.
   * @return string
   */
  public function getPaymentMethodDescription()
  {
    return !empty($this->configuration['description']) ? '<p class="lunar-description">' . $this->configuration['description'] . '</p>' : '';
  }

  /**
   * {@inheritdoc}
   */
  public function cartReviewTitle()
  {
    return $this->t('Lunar');
  }

  /**
   * Load API.
   * @return bool
   */
  public function prepareApi()
  {
    if ($this->apiClient) return true;

    $privateKey = $this->configuration['testmode'] ? $this->configuration['test_app_key'] : $this->configuration['live_app_key'];
    try {
      $this->apiClient = new \Paylike\Paylike($privateKey);
    } catch (ApiException $e) {
      \Drupal::logger('uc_lunar')->notice($this->t('Lunar is not properly configured. Payments will not be processed: @error', ['@error' => $e->getMessage()]));
      return false;
    }
    return true;
  }

  /**
   * Returns transaction data.
   * @param $id
   * @return array
   */
  protected function getTransaction($id)
  {
    $transaction = ['successful' => false];
    try {
      if ($this->prepareApi()) {
        $transactions = $this->apiClient->transactions();
        $transaction = $transactions->fetch($id);
      }
    } catch (ApiException $e) {
      \Drupal::logger('uc_lunar')->warning($this->t('Transaction @id not found. Message: @message', ['@id' => $id, '@message' => $e->getMessage()]));
    }
    return $transaction;
  }

  /**
   * Returns transaction data.
   * @param $id
   * @return array
   */
  public function refund($id, $amount)
  {
    $transaction = ['successful' => false];
    try {
      if ($this->prepareApi()) {
        $transactions = $this->apiClient->transactions();
        $transaction = $transactions->refund($id, ['amount' => $amount]);
      }
    } catch (ApiException $e) {
      \Drupal::logger('uc_lunar')->warning($this->t('Refund failed. Transaction ID: @id. Message: @message', ['@id' => $id, '@message' => $e->getMessage()]));
      $transaction['error'] = $e->getMessage();
    }
    return $transaction;
  }

  //TODO: remove that when CreditCardTerminalForm->submitForm() will return correct txn_type.
  protected function getTransactionType()
  {
    $txn_type = null;
    $op = isset($_POST['op']) ? $_POST['op'] : null;
    switch ($op) {
      case $this->t('Charge amount'):
        $txn_type = UC_CREDIT_AUTH_CAPTURE;
        break;

      case $this->t('Authorize amount only'):
        $txn_type = UC_CREDIT_AUTH_ONLY;
        break;

      case $this->t('Set a reference only'):
        $txn_type = UC_CREDIT_REFERENCE_SET;
        break;

      case $this->t('Credit amount to this card'):
        $txn_type = UC_CREDIT_CREDIT;
        break;

      case $this->t('Capture amount to this authorization'):
        $txn_type = UC_CREDIT_PRIOR_AUTH_CAPTURE;
        break;

      case $this->t('Void authorization'):
        $txn_type = UC_CREDIT_VOID;
        break;

      case $this->t('Charge amount to this reference'):
        $txn_type = UC_CREDIT_REFERENCE_TXN;
        break;

      case $this->t('Remove reference'):
        $txn_type = UC_CREDIT_REFERENCE_REMOVE;
        break;

      case $this->t('Credit amount to this reference'):
        $txn_type = UC_CREDIT_REFERENCE_CREDIT;
    }
    return $txn_type;
  }

  /**
   * {@inheritdoc}
   */
  public function orderLoad(OrderInterface $order)
  {
    // Load the CC details data array.
    if (empty($order->payment_details) && isset($order->data->uc_lunar['payment_details'])) {
      $order->payment_details = $order->data->uc_lunar['payment_details'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function orderSave(OrderInterface $order)
  {
    echo '';
  }
}
