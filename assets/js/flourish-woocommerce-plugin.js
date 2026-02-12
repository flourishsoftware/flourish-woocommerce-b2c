(function ($) {
  $(document).ready(function () {
    $('#flourish-woocommerce-plugin-filter-brands').on('click', function () {
      $('#flourish-woocommerce-plugin-brand-selection').toggle(this.checked);
    });

    // Validation rule for minimum and maximum order quantity
    const minOrderField = $('#_min_order_quantity');
    const maxOrderField = $('#_max_order_quantity');

    function showError(field, message) {
      let errorSpan = field.next('.error-message');
      if (!errorSpan.length) {
        errorSpan = $('<span class="error-message" style="display: flex; color: #f34e4e; font-size: 12px; padding: 0px 4px; width: 100%;"></span>');
        field.after(errorSpan);
      }
      errorSpan.text(message);
    }

    function clearError(field) {
      field.next('.error-message').remove();
    }

    function validateInput() {
      let value = $(this).val();

      if (/[^0-9]/.test(value)) {
        $(this).val(value.replace(/[^0-9]/g, ''));
        showError($(this), 'Only numeric values are allowed.');
      } else {
        clearError($(this));
      }
    }

    function validateMinMax() {
      const minVal = parseInt(minOrderField.val(), 10) || 0;
      const maxVal = parseInt(maxOrderField.val(), 10) || 0;

      if (minVal > maxVal && minOrderField.val() && maxOrderField.val()) {
        showError(minOrderField, 'Minimum order quantity cannot be greater than maximum order quantity.');
        showError(maxOrderField, 'Maximum order quantity cannot be less than minimum order quantity.');
      } else {
        clearError(minOrderField);
        clearError(maxOrderField);
      }
    }

    minOrderField.on('input', validateInput).on('blur', validateMinMax);
    maxOrderField.on('input', validateInput).on('blur', validateMinMax);
  });
})(jQuery);
