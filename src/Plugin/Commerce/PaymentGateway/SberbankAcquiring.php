<?php

namespace Drupal\commerce_sberbank_acquiring\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Voronkovich\SberbankAcquiring\Client as SberbankClient;
use Voronkovich\SberbankAcquiring\HttpClient\GuzzleAdapter as SberbankGuzzleAdapter;
use Voronkovich\SberbankAcquiring\OrderStatus as SberbankOrderStatus;

/**
 * Provides the Sberbank Acquiring payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "sberbank_acquiring",
 *   label = @Translation("Sberbank Acquiring"),
 *   display_label = @Translation("Sberbank"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_sberbank_acquiring\PluginForm\OffsiteRedirect\SberbankAcquiringForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "maestro", "mastercard", "visa", "mir",
 *   },
 * )
 */
class SberbankAcquiring extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'username' => '',
      'password' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Username"),
      '#required' => TRUE,
      '#default_value' => $this->configuration['username'],
    ];

    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t("Password"),
      '#description' => $this->t("Password stored in database. To change it, enter new password, or leave field empty and password won't change."),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);
    if (strlen($values['password']) == 0 && !$this->configuration['password']) {
      $form_state->setError($form['password'], $this->t("Password field is required."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['username'] = $values['username'];
      $this->configuration['password'] = $values['password'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // Get orderId from Sberbank.
    $remote_id = $request->query->get('orderId');

    // Set REST API url for test or live modes.
    switch ($this->getMode()) {
      default:
      case 'test':
        $api_uri = SberbankClient::API_URI_TEST;
        break;

      case 'live':
        $api_uri = SberbankClient::API_URI;
        break;
    }

    $client = new SberbankClient([
      'userName' => $this->configuration['username'],
      'password' => $this->configuration['password'],
      'apiUri' => $api_uri,
      'httpClient' => new SberbankGuzzleAdapter(\Drupal::httpClient()),
    ]);
    $order_status = $client->getOrderStatusExtended($remote_id);
    switch ($order_status['orderStatus']) {
      case SberbankOrderStatus::DEPOSITED:
        $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
        $payment = $payment_storage->create([
          'state' => 'completed',
          'amount' => $order->getTotalPrice(),
          'payment_gateway' => $this->entityId,
          'order_id' => $order->id(),
          'remote_id' => $remote_id,
          'remote_state' => $order_status['paymentAmountInfo']['paymentState'],
        ]);

        $payment->save();
        break;

      default:
      case SberbankOrderStatus::DECLINED:
        throw new PaymentGatewayException('Payment failed!');
    }
  }

}
