/**
 * Date of Birth field for the WooCommerce block checkout.
 *
 * The field goes in the Contact information step, under the email address.
 * That step is always shown, unlike #billing, which block checkout leaves out
 * while "Use same address for billing" is ticked. Block checkout is a React
 * app that renders late and re-renders often, so a MutationObserver puts the
 * field back whenever it goes missing and keeps the entered value.
 *
 * The DOB is saved through the flourish/v1/save-dob REST route, keyed by the
 * email address. Placing the order waits until the DOB for the current email
 * has been saved, so order sync can read it.
 *
 * Classic checkout uses the billing_dob field rendered in PHP, so this script
 * does nothing there.
 */
(function () {
    const FIELD_ID = 'dob';
    const WRAPPER_CLASS = 'wc-block-components-address-form__dob';
    const PLACE_ORDER_SELECTOR = '.wc-block-components-checkout-place-order-button';

    let dobValue = '';
    let savedKey = '';
    let queuedKey = '';
    let saveQueue = Promise.resolve();
    let resumingClick = false;

    function getMinimumAge() {
        return (typeof dobData !== 'undefined' && dobData.minimumAge) ? parseInt(dobData.minimumAge, 10) : 21;
    }

    function getEmail() {
        const emailField = document.querySelector('#email');
        return emailField ? emailField.value.trim() : '';
    }

    function parseDate(value) {
        const parts = value.split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN) || parts[0] < 1900) {
            return null;
        }
        return parts;
    }

    function isOfLegalAge(parts, minimumAge) {
        const today = new Date();
        let age = today.getFullYear() - parts[0];
        const monthDiff = (today.getMonth() + 1) - parts[1];
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < parts[2])) {
            age--;
        }
        return age >= minimumAge;
    }

    function getErrorMessage() {
        const parts = dobValue ? parseDate(dobValue) : null;
        if (!parts) {
            return 'Please enter a valid Date of Birth';
        }
        const minimumAge = getMinimumAge();
        if (!isOfLegalAge(parts, minimumAge)) {
            return `You must be at least ${minimumAge} years old.`;
        }
        return '';
    }

    function showValidation() {
        const input = document.getElementById(FIELD_ID);
        if (!input) {
            return true;
        }
        const wrapper = input.closest('.' + WRAPPER_CLASS);
        const message = getErrorMessage();
        let errorDiv = wrapper.querySelector('.wc-block-components-validation-error');

        if (!message) {
            wrapper.classList.remove('has-error');
            if (errorDiv) {
                errorDiv.remove();
            }
            return true;
        }

        wrapper.classList.add('has-error');
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.className = 'wc-block-components-validation-error';
            errorDiv.setAttribute('role', 'alert');
            errorDiv.innerHTML = `
                <p>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                        <path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z"></path>
                    </svg>
                    <span></span>
                </p>`;
            wrapper.appendChild(errorDiv);
        }
        errorDiv.querySelector('span').textContent = message;
        return false;
    }

    function buildField() {
        const wrapper = document.createElement('div');
        wrapper.className = 'wc-block-components-text-input is-active ' + WRAPPER_CLASS;

        const input = document.createElement('input');
        input.type = 'date';
        input.id = FIELD_ID;
        input.name = FIELD_ID;
        input.autocomplete = 'bday';
        input.required = true;
        input.value = dobValue;
        input.setAttribute('aria-label', 'Date of Birth');

        const label = document.createElement('label');
        label.setAttribute('for', FIELD_ID);
        label.textContent = 'Date of Birth';

        wrapper.appendChild(input);
        wrapper.appendChild(label);

        // Date inputs fire change on every keystroke that forms a full date
        // (0001, 0019, 0199, 1992), so save only once the field loses focus.
        const update = () => {
            dobValue = input.value;
            if (wrapper.classList.contains('has-error')) {
                showValidation();
            }
        };
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        input.addEventListener('blur', () => {
            dobValue = input.value;
            if (showValidation()) {
                saveDOB();
            }
        });

        return wrapper;
    }

    /**
     * Put the field under the email address in the Contact information step.
     * Returns false while block checkout hasn't rendered that step yet.
     */
    function ensureField() {
        if (document.getElementById(FIELD_ID)) {
            return true;
        }

        const emailField = document.querySelector('.wp-block-woocommerce-checkout #email');
        const emailWrapper = emailField && emailField.closest('.wc-block-components-text-input');
        if (!emailWrapper || !emailWrapper.parentNode) {
            return false;
        }

        emailWrapper.parentNode.insertBefore(buildField(), emailWrapper.nextSibling);
        return true;
    }

    /**
     * Save the DOB for the current email. Resolves true once the server has
     * it. Saves run one at a time so an older value can't land last.
     */
    function saveDOB() {
        const email = getEmail();
        if (!dobValue || !email || typeof dobData === 'undefined' || getErrorMessage()) {
            return Promise.resolve(false);
        }

        const key = email.toLowerCase() + '|' + dobValue;
        if (key === savedKey && key === queuedKey) {
            return Promise.resolve(true);
        }
        if (key === queuedKey) {
            return saveQueue;
        }

        queuedKey = key;
        const body = JSON.stringify({ dob: dobValue, email: email });
        saveQueue = saveQueue.then(() => fetch(dobData.postApiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': dobData.nonce,
            },
            body: body,
        }))
            .then((response) => response.json())
            .then((data) => {
                if (data.status === 'success') {
                    savedKey = key;
                    return true;
                }
                console.error('Failed to save DOB:', data.message);
                return false;
            })
            .catch((error) => {
                console.error('Error saving DOB:', error);
                return false;
            })
            .then((saved) => {
                if (!saved && queuedKey === key) {
                    queuedKey = '';
                }
                return saved;
            });

        return saveQueue;
    }

    /**
     * Fill the field for a logged-in customer who already has a DOB on file.
     */
    function populateFromAccount() {
        if (typeof dobData === 'undefined' || !dobData.isLoggedIn) {
            return;
        }

        fetch(dobData.getApiUrl, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': dobData.nonce },
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.status === 'success' && data.dob && !dobValue) {
                    dobValue = data.dob;
                    const input = document.getElementById(FIELD_ID);
                    if (input) {
                        input.value = dobValue;
                    }
                    saveDOB();
                }
            })
            .catch(() => {});
    }

    /**
     * Hold the Place Order click until the DOB is valid and saved for the
     * email being used, then click again.
     */
    function onPlaceOrderClick(event) {
        const button = event.target.closest && event.target.closest(PLACE_ORDER_SELECTOR);
        if (!button || resumingClick || !document.getElementById(FIELD_ID)) {
            return;
        }

        if (!showValidation()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            document.getElementById(FIELD_ID).focus();
            return;
        }

        const email = getEmail();
        const key = email.toLowerCase() + '|' + dobValue;
        if (!email || (savedKey === key && queuedKey === key)) {
            // Without an email block checkout shows its own error.
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        saveDOB().then(() => {
            resumingClick = true;
            try {
                button.click();
            } finally {
                resumingClick = false;
            }
        });
    }

    function onDocumentChange(event) {
        if (event.target && event.target.id === 'email') {
            saveDOB();
        }
    }

    function init() {
        const checkout = document.querySelector('.wp-block-woocommerce-checkout');
        if (!checkout) {
            return;
        }

        document.addEventListener('click', onPlaceOrderClick, true);
        document.addEventListener('change', onDocumentChange, true);

        ensureField();
        populateFromAccount();

        new MutationObserver(ensureField).observe(checkout, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
