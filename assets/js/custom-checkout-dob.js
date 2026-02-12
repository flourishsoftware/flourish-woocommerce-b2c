/**
 * Custom checkout DOB field for Flourish WooCommerce B2C.
 *
 * FIX: Uses nonce-authenticated REST endpoints instead of unauthenticated ones.
 * FIX: Adds configurable minimum age from server settings.
 */
let attempts = 0;
const maxAttempts = 20;

function waitForBillingFieldsContainer() {
    const billingFieldsContainer = document.querySelector('#billing');
    attempts++;

    if (!billingFieldsContainer && attempts < maxAttempts) {
        setTimeout(waitForBillingFieldsContainer, 500);
    } else if (billingFieldsContainer) {
        const wrapperDiv = document.createElement('div');
        wrapperDiv.className = 'wc-block-components-text-input wc-block-components-address-form__dob is-active';

        const customFieldInput = document.createElement('input');
        customFieldInput.type = 'date';
        customFieldInput.id = 'dob';
        customFieldInput.name = 'dob';
        customFieldInput.autocapitalize = 'none';
        customFieldInput.autocomplete = 'bday';
        customFieldInput.setAttribute('aria-label', 'Date of Birth');
        customFieldInput.required = true;

        const customFieldLabel = document.createElement('label');
        customFieldLabel.setAttribute('for', 'dob');
        customFieldLabel.textContent = 'Date of Birth';

        wrapperDiv.appendChild(customFieldInput);
        wrapperDiv.appendChild(customFieldLabel);
        billingFieldsContainer.appendChild(wrapperDiv);

        populateDOBFromSession();

        customFieldInput.addEventListener('change', handleDOBChange);
        customFieldInput.addEventListener('blur', toggleErrorMessage);
        customFieldInput.addEventListener('input', toggleErrorMessage);
        customFieldInput.addEventListener('focus', () => {
            customFieldInput.closest('.wc-block-components-address-form__dob').classList.add('is-active');
        });

        const emailField = document.querySelector('#email');
        if (emailField) {
            emailField.addEventListener('change', handleEmailChange);
        }

        waitForPlaceOrderButton();
    }
}

function getMinimumAge() {
    return (typeof dobData !== 'undefined' && dobData.minimumAge) ? parseInt(dobData.minimumAge) : 21;
}

function toggleErrorMessage() {
    const customFieldInput = document.querySelector('#dob');
    const fieldWrapper = customFieldInput.closest('.wc-block-components-address-form__dob');
    const errorDiv = fieldWrapper.querySelector('.wc-block-components-validation-error');
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');

    const dobValue = customFieldInput.value;
    const minimumAge = getMinimumAge();

    if (!dobValue || !isOfLegalAge(dobValue, minimumAge)) {
        fieldWrapper.classList.add('has-error');

        if (!errorDiv) {
            const newErrorDiv = document.createElement('div');
            newErrorDiv.classList.add('wc-block-components-validation-error');
            newErrorDiv.setAttribute('role', 'alert');

            const message = !dobValue
                ? 'Please enter a valid Date of Birth'
                : `You must be at least ${minimumAge} years old.`;

            newErrorDiv.innerHTML = `
                <p>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 24 24" width="24" height="24" aria-hidden="true" focusable="false">
                        <path d="M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z"></path>
                    </svg>
                    <span>${message}</span>
                </p>`;
            fieldWrapper.appendChild(newErrorDiv);
        }

        if (placeOrderButton) placeOrderButton.disabled = true;
    } else {
        fieldWrapper.classList.add('is-active');
        fieldWrapper.classList.remove('has-error');

        if (errorDiv) {
            errorDiv.remove();
        }

        if (placeOrderButton) placeOrderButton.disabled = false;
    }
}

function isOfLegalAge(dobValue, minimumAge) {
    const dobDate = new Date(dobValue);
    const today = new Date();
    let age = today.getFullYear() - dobDate.getFullYear();
    const monthDiff = today.getMonth() - dobDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dobDate.getDate())) {
        age--;
    }
    return age >= minimumAge;
}

function populateDOBFromSession() {
    const emailField = document.querySelector('#email');
    const dobField = document.querySelector('#dob');

    if (emailField && dobField && emailField.value && typeof dobData !== 'undefined') {
        fetch(dobData.getApiUrl, {
            headers: {
                'X-WP-Nonce': dobData.nonce
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' && data.dob) {
                    dobField.value = data.dob;
                }
            })
            .catch(() => {
                // Silently fail — guest users won't have stored DOB
            });
    }
}

function handleDOBChange(event) {
    const dobValue = event.target.value;
    const emailValue = document.querySelector('#email') ? document.querySelector('#email').value : null;

    if (dobValue) {
        sendDOBToServer(dobValue, emailValue);
    } else {
        toggleErrorMessage();
    }
}

function handleEmailChange(event) {
    const emailValue = event.target.value;
    const dobValue = document.querySelector('#dob') ? document.querySelector('#dob').value : null;

    if (emailValue && dobValue) {
        sendDOBToServer(dobValue, emailValue);
    }
}

function waitForPlaceOrderButton() {
    const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');

    if (placeOrderButton) {
        placeOrderButton.addEventListener('click', toggleErrorMessage);
    } else {
        setTimeout(waitForPlaceOrderButton, 500);
    }
}

function sendDOBToServer(dob, email) {
    if (typeof dobData === 'undefined') return;

    fetch(dobData.postApiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': dobData.nonce
        },
        body: JSON.stringify({ dob: dob, email: email }),
    })
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                console.error('Failed to save DOB:', data.message);
            }
        })
        .catch(error => {
            console.error('Error saving DOB:', error);
        });
}

document.addEventListener('DOMContentLoaded', waitForBillingFieldsContainer);
