<?php

namespace Drupal\uc_lunar\Plugin\Ubercart\PaymentMethod;

use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\Html;
use Drupal\uc_order\OrderInterface;
use Drupal\uc_payment\PaymentMethodPluginBase;
use Drupal\uc_payment\OffsitePaymentMethodPluginInterface;

use Lunar\Lunar;
use Lunar\Exception\ApiException;

/**
 *  Ubercart gateway payment method.
 *
 *
 * @UbercartPaymentMethod(
 *   id = "lunar_mobilepay_gateway",
 *   name = @Translation("Lunar MobilePay gateway"),
 * )
 */
class LunarMobilePayGateway extends PaymentMethodPluginBase implements OffsitePaymentMethodPluginInterface
{
  /** Lunar Ubercart plugin version. */
  const MODULE_VERSION = '8.x-2.0';

  protected $apiClient;
  private $paymentMethodCode = 'mobilePay';

  /**
   * 
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $database);

    if (!empty($this->configuration['app_key'])) {
      $this->apiClient = new Lunar($this->configuration['app_key'], null, true);
    }
  }
  
  /**
   * @return array
   */
  public function buildRedirectForm(array $form, FormStateInterface $form_state, OrderInterface $order)
  {
    $form['#action'] = Url::fromRoute('uc_lunar.redirect',
      ['uc_order' => $order->id()], ['absolute' => true])->toString();

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Pay for order!'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayLabel($label)
  {
    $build['#attached']['library'][] = 'uc_lunar/uc_lunar.css';
    $build['label'] = [
      '#plain_text' => $label,
    ];

    $cc_types = [
      'visa' => $this->t('Visa'),
      'visaelectron' => $this->t('Visa Electron'),
      'mastercard' => $this->t('MasterCard'),
      'maestro' => $this->t('Maestro'),
    ];

    foreach ($cc_types as $type => $description) {
      $build['image'][$type] = [
        '#theme' => 'image',
        '#uri' => drupal_get_path('module', 'uc_lunar') . '/images/' . $type . '.png',
        '#alt' => $description,
        '#attributes' => ['class' => ['uc-lunar-card']],
      ];
    }
    return $build;
  }

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
    return [
      // 'payment_method' => 'card',
      'txn_type' => UC_CREDIT_AUTH_ONLY,
      'app_key' => '',
      'public_key' => '',
      'configuration_id' => '',
      'logo_url' => '',
      'shop_title' => '',
      'description' => 'Secure payment with MobilePay via © Lunar',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    return [
      // 'payment_method' => [
      //   '#type' => 'radios',
      //   '#title' => $this->t('Payment method'),
      //   '#default_value' => $this->configuration['payment_method'],
      //   '#options' => [
      //     'card' => $this->t('Card'),
      //     'mobilePay' => $this->t('MobilePay'),
      //   ],
      // ],
      'txn_type' => [
        '#type' => 'radios',
        '#title' => $this->t('Transaction type'),
        '#default_value' => $this->configuration['txn_type'],
        '#options' => [
          UC_CREDIT_AUTH_CAPTURE => $this->t('Authorize and capture immediately'),
          UC_CREDIT_AUTH_ONLY => $this->t('Authorization only'),
        ],
      ],
      'app_key' => [
        '#type' => 'textfield',
        '#title' => t('App Key'),
        '#description' => t('Private API key can be obtained by creating a merchant and adding an app through Lunar <a href="@dashboard" target="_blank">dashboard</a>.', ['@dashboard' => 'https://lunar.app/']),
        '#default_value' => $this->configuration['app_key'],
      ],
      'public_key' => [
        '#type' => 'textfield',
        '#title' => t('Public Key'),
        '#description' => t('Public API key.'),
        '#default_value' => $this->configuration['public_key'],
      ],
      'configuration_id' => [
        '#type' => 'textfield',
        '#title' => t('Configuration ID'),
        '#description' => t('Email onlinepayments@lunar.app to get it'),
        '#default_value' => $this->configuration['configuration_id'],
      ],
      'logo_url' => [
        '#type' => 'textfield',
        '#title' => t('Logo URL'),
        '#description' => t('Must be a link begins with "https://" to a JPG, JPEG or PNG file'),
        '#default_value' => $this->configuration['logo_url'],
      ],
      'shop_title' => [
        '#type' => 'textfield',
        '#title' => t('Shop title'),
        '#description' => t('The title shown in the page where the customer is redirected. Leave blank to show the site name.'),
        '#default_value' => $this->configuration['shop_title'],
      ],
      'description' => [
        '#type' => 'textarea',
        '#title' => t('Payment method description'),
        '#description' => t('Description on checkout page.'),
        '#default_value' => $this->configuration['description'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    // @TODO validate keys or other fields
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {
    $items = [
      // 'payment_method',
      'txn_type',
      'app_key',
      'public_key',
      'configuration_id',
      'logo_url',
      'shop_title',
      'description',
    ];

    foreach ($items as $item) {
      $this->configuration[$item] = $form_state->getValue($item);
    }
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
    return $this->configuration['label'];
  }

  /**
   * Load API.
   * @return bool
   */
  private function prepareApi()
  {
    try {
      $this->apiClient = new Lunar($this->configuration['app_key'], null, true);
    } catch (ApiException $e) {
      \Drupal::logger('uc_lunar')->notice($this->t("Lunar method {$this->paymentMethodCode} is not properly configured. Payments will not be processed: @error", ['@error' => $e->getMessage()]));
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
