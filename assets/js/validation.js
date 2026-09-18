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

    // ---- Contact numbers: a fixed "+63 " the user can't type over ------
    // Every contact-number box in the system shows "+63" before anything
    // is typed, and the tenant fills in only the 10-digit mobile number
    // (which always starts with 9), so the field reads and posts as
    // "+63 9123456789". The prefix is part of the field's value rather
    // than a separate label, so it survives autofill, back-button
    // restores and a re-rendered form after a failed submit.
    const PH_PREFIX = '+63';
    const PH_HEAD = PH_PREFIX.length + 1; // index of the first typed digit

    /** The 10-digit subscriber number inside whatever the field holds. */
    function phDigits(value) {
      // Strip the mask's own prefix first — including a half-deleted one
      // like "+6 " — so its 6 and 3 are never read as typed digits.
      let rest = String(value == null ? '' : value).replace(/^[\s(]*\+?[\s(]*6?3?[\s)\-.]*/, '');
      let digits = rest.replace(/\D+/g, '');
      digits = digits.replace(/^0+/, '');             // 0912... -> 912...
      if (digits.length > 10 && digits.indexOf('63') === 0) {
        digits = digits.slice(2);                     // pasted +63 / 63 again
      }
      return digits.slice(0, 10);
    }

    function phFormat(digits) {
      return digits ? PH_PREFIX + ' ' + digits : PH_PREFIX;
    }

    function phValidity(input, digits) {
      if (digits === '') {
        input.setCustomValidity(input.required ? 'Please enter a contact number.' : '');
      } else if (digits.charAt(0) !== '9') {
        input.setCustomValidity('A Philippine mobile number starts with 9, e.g. +63 9123456789.');
      } else if (digits.length < 10) {
        input.setCustomValidity('Enter all 10 digits, e.g. +63 9123456789.');
      } else {
        input.setCustomValidity('');
      }
    }

    /** Re-render the field from its digits, keeping the caret in place. */
    function phRender(input, keepCaret) {
      const raw = input.value;
      const digits = phDigits(raw);
      const formatted = phFormat(digits);

      if (keepCaret) {
        const at = input.selectionStart === null ? raw.length : input.selectionStart;
        const before = phDigits(raw.slice(0, at)).length;
        if (formatted !== raw) {
          input.value = formatted;
        }
        const caret = Math.min(PH_HEAD + before, formatted.length);
        try { input.setSelectionRange(caret, caret); } catch (e) { /* not a text input */ }
      } else if (formatted !== raw) {
        input.value = formatted;
      }

      phValidity(input, digits);
    }

    /** Never let the caret sit inside "+63 ". */
    function phGuardCaret(input) {
      const min = Math.min(PH_HEAD, input.value.length);
      if (input.selectionStart < min && input.selectionStart === input.selectionEnd) {
        try { input.setSelectionRange(min, min); } catch (e) { /* ignore */ }
      }
    }

    function phSetup(input) {
      if (input.dataset.phMaskReady) {
        return;
      }
      input.dataset.phMaskReady = '1';
      input.setAttribute('inputmode', 'tel');
      input.setAttribute('autocomplete', 'tel');
      if (!input.getAttribute('placeholder')) {
        input.setAttribute('placeholder', '+63 9123456789');
      }

      phRender(input, false);

      input.addEventListener('input', function () { phRender(input, true); });
      input.addEventListener('focus', function () { window.setTimeout(function () { phGuardCaret(input); }, 0); });
      input.addEventListener('click', function () { phGuardCaret(input); });
      input.addEventListener('keyup', function (e) {
        if (e.key === 'ArrowLeft' || e.key === 'Home') { phGuardCaret(input); }
      });
      input.addEventListener('blur', function () { phRender(input, false); });

      // Backspace/Delete with the caret parked in the prefix would eat
      // the "+63" itself; every other edit is safe because the value is
      // rebuilt from its digits on the way out.
      input.addEventListener('keydown', function (e) {
        if (e.key !== 'Backspace' && e.key !== 'Delete') {
          return;
        }
        if (input.selectionStart !== input.selectionEnd) {
          return; // a selection: clearing it is fine, the prefix comes back
        }
        const eatsPrefix = e.key === 'Backspace'
          ? input.selectionStart <= PH_HEAD
          : input.selectionStart < PH_HEAD;
        if (eatsPrefix) {
          e.preventDefault();
          phGuardCaret(input);
        }
      });
    }

    // Anything explicitly marked, plus any phone/contact-number field —
    // so a form added later is covered without having to remember this.
    document.querySelectorAll(
      'input[data-ph-mobile], input[type="tel"], input[name*="phone"], input[name*="contact_number"], input[name*="contact_no"]'
    ).forEach(function (input) {
      if (input.tagName === 'INPUT' && !input.disabled && !input.readOnly) {
        phSetup(input);
      }
    });

    // Fields inside a modal are filled in from a button's data-* just
    // before it opens, which writes straight past the mask.
    document.addEventListener('shown.bs.modal', function (e) {
      e.target.querySelectorAll('input[data-ph-mobile]').forEach(function (input) {
        phSetup(input);
        phRender(input, false);
      });
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
