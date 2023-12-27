(function ($, Drupal, drupalSettings) {

  Drupal.behaviors.lunarForm = {
    attach: function (context) {
      // Attach the code only once
      $('.lunar-button', context).once('lunar').each(function() {
        if (!drupalSettings.uc_lunar || !drupalSettings.uc_lunar.publicKey || drupalSettings.uc_lunar.publicKey === '') {
          $('#edit-payment-information').prepend('<div class="messages messages--error">' + Drupal.t('Configure Lunar payment gateway settings please') + '</div>');
          return;
        }

        function handleResponse(error, response) {
          if (error) {
            return console.log(error);
          }
          console.log(response);
          $('.lunar-button').val(Drupal.t('Change credit card details'));
          $('#lunar_transaction_id').val(response.transaction.id);
        }

        $(this).click(function (event) {
          event.preventDefault();
          var lunar = Paylike({key: drupalSettings.uc_lunar.publicKey});

          config = drupalSettings.uc_lunar.config;

          // Get customer information from delivery or billing pane
          var customer = [
            $('.uc-cart-checkout-form [name*="first_name"]').val(),
            $('.uc-cart-checkout-form [name*="last_name"]').val(),
          ];
          var address = [
            $('.uc-cart-checkout-form [name*="postal_code"]').val(),
            $('.uc-cart-checkout-form [name*="city"]').val(),
            $('.uc-cart-checkout-form [name*="street1"]').val(),
            $('.uc-cart-checkout-form [name*="street2"]').val(),
          ];

          config.custom.customer.name = customer.filter(String).join(' ');
          config.custom.customer.address = address.filter(String).join(', ');

          lunar.pay(config, handleResponse);
        });
      });
    }
  }

})(jQuery, Drupal, drupalSettings);
