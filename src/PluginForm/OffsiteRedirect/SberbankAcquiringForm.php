<?php

namespace Drupal\commerce_sberbank_acquiring\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Voronkovich\SberbankAcquiring\Client;
use Voronkovich\SberbankAcquiring\Exception\ActionException;
use Voronkovich\SberbankAcquiring\HttpClient\GuzzleAdapter;

/**
 * Class SberbankAcquiringForm
 *
 * @package Drupal\commerce_payment_example\PluginForm\OffsiteRedirect
 */
class SberbankAcquiringForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configs = $payment_gateway_plugin->getConfiguration();
    // Get username and password for payment method.
    $username = $configs['username'];
    $password = $configs['password'];
    /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
    $currency = \Drupal::entityTypeManager()
      ->getStorage('commerce_currency')
      ->load($payment->getAmount()->getCurrencyCode());

    // Set REST API url for test or live modes.
    switch ($this->plugin->getMode()) {
      default:
      case 'test':
        $api_uri = Client::API_URI_TEST;
        break;

      case 'live':
        $api_uri = Client::API_URI;
        break;
    }

    // Prepare client to be executed.
    $client = new Client([
      'userName' => $username,
      'password' => $password,
      'language' => \Drupal::languageManager()->getCurrentLanguage()->getId(),
      // ISO 4217 currency code.
      'currency' => $currency->getNumericCode(),
      'apiUri' => $api_uri,
      'httpClient' => new GuzzleAdapter(\Drupal::httpClient()),
    ]);

    // Sett additional params to order.
    $order_id = $payment->getOrderId();
    $order_amount = (int) ($payment->getAmount()->getNumber() * 100);
    $params = [
      'failUrl' => $form['#cancel_url'],
      'orderBundle' => [
        'cartItems' => [],
      ],
    ];

    // Execute request to Sberbank.
    try {
      $result = $client->registerOrder($order_id, $order_amount, $form['#return_url'], $params);
    } catch (ActionException $exception) {
      // If something goes wrong, we stop payment and show error for it.
      throw new PaymentGatewayException(t("Payment initialization finished with error. Contact site administrator. Error code: @code, message: @message", [
        '@code' => $exception->getCode(),
        '@message' => $exception->getMessage(),
      ]));
      return $form;
    }
    $payment_form_url = $result['formUrl'];
    return $this->buildRedirectForm($form, $form_state, $payment_form_url, [], self::REDIRECT_POST);
  }

}
