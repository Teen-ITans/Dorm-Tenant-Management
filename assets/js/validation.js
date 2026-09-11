/**
 * assets/js/validation.js
 * Client-side validation only. The server re-validates everything —
 * never trust the browser alone for data that ends up in the database.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    // ---- Bootstrap's standard "needs-validation" pattern ---------------
    document.querySelectorAll('.needs-validation').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });

    // ---- Password confirmation must match, everywhere both fields exist
    const pw = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    if (pw && confirm) {
      const checkMatch = function () {
        confirm.setCustomValidity(confirm.value !== pw.value ? 'Passwords do not match.' : '');
      };
      pw.addEventListener('input', checkMatch);
      confirm.addEventListener('input', checkMatch);
    }
  });
})();
