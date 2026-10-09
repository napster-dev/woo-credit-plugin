(function () {
    'use strict';

    // 1. Monkey-patch Google PaymentsClient to ensure clean plain button and fill sizing mode
    function patchGooglePayments() {
        try {
            if (window.google && window.google.payments && window.google.payments.api && window.google.payments.api.PaymentsClient) {
                if (!window.cwdGooglePayPatched) {
                    window.cwdGooglePayPatched = true;
                    var origCreateButton = window.google.payments.api.PaymentsClient.prototype.createButton;
                    window.google.payments.api.PaymentsClient.prototype.createButton = function (options) {
                        options = options || {};
                        options.buttonColor = 'black';
                        options.buttonType = 'plain'; // Clean G Pay logo without overflowing "Buy with" text
                        options.buttonSizeMode = 'fill'; // Fill container perfectly
                        var btn = origCreateButton.call(this, options);
                        setTimeout(alignButtons, 50);
                        setTimeout(alignButtons, 200);
                        return btn;
                    };
                }
                return true;
            }
        } catch (e) {}
        return false;
    }

    patchGooglePayments();
    var patchTimer = setInterval(function () {
        if (patchGooglePayments()) {
            clearInterval(patchTimer);
        }
    }, 30);

    if (window.cwdBlinkExpressLoaded) {
        return;
    }
    window.cwdBlinkExpressLoaded = true;

    var config = window.cwdBlinkExpressConfig || {};

    // Programmatically align inside elements of both Apple Pay & Google Pay
    function alignButtons() {
        // 1. Align Apple Pay button
        var apBtn = document.querySelector('#apple-pay-btn, apple-pay-button');
        if (apBtn) {
            apBtn.style.setProperty('--apple-pay-button-height', '48px', 'important');
            apBtn.style.setProperty('--apple-pay-button-width', '100%', 'important');
            apBtn.style.setProperty('--apple-pay-button-border-radius', '6px', 'important');
            apBtn.style.setProperty('--apple-pay-button-box-sizing', 'border-box', 'important');
            apBtn.style.setProperty('height', '48px', 'important');
            apBtn.style.setProperty('display', 'flex', 'important');
            apBtn.style.setProperty('align-items', 'center', 'important');
            apBtn.style.setProperty('justify-content', 'center', 'important');
            apBtn.style.setProperty('margin', '0 auto', 'important');
            apBtn.style.setProperty('padding', '0', 'important');

            if (apBtn.shadowRoot) {
                try {
                    var shadowStyle = apBtn.shadowRoot.querySelector('style#cwd-ap-align');
                    if (!shadowStyle) {
                        shadowStyle = document.createElement('style');
                        shadowStyle.id = 'cwd-ap-align';
                        shadowStyle.textContent = ':host { height: 48px !important; display: flex !important; align-items: center !important; justify-content: center !important; } button, div, svg { height: 100% !important; max-height: 48px !important; display: flex !important; align-items: center !important; justify-content: center !important; margin: 0 auto !important; padding: 0 !important; }';
                        apBtn.shadowRoot.appendChild(shadowStyle);
                    }
                } catch (e) {}
            }
        }

        // 2. Align Google Pay button
        var gpContainer = document.getElementById('container');
        if (gpContainer) {
            var gpBtn = gpContainer.querySelector('button, .gpay-button, iframe');
            if (gpBtn) {
                gpBtn.style.setProperty('height', '48px', 'important');
                gpBtn.style.setProperty('width', '100%', 'important');
                gpBtn.style.setProperty('display', 'flex', 'important');
                gpBtn.style.setProperty('align-items', 'center', 'important');
                gpBtn.style.setProperty('justify-content', 'center', 'important');
                gpBtn.style.setProperty('margin', '0 auto', 'important');
                gpBtn.style.setProperty('padding', '0', 'important');
                gpBtn.style.setProperty('line-height', 'normal', 'important');
            }
            var gpInnerDivs = gpContainer.querySelectorAll('.gpay-button-fill, .gpay-card-info-container');
            if (gpInnerDivs && gpInnerDivs.length) {
                gpInnerDivs.forEach(function (el) {
                    el.style.setProperty('height', '100%', 'important');
                    el.style.setProperty('display', 'flex', 'important');
                    el.style.setProperty('align-items', 'center', 'important');
                    el.style.setProperty('justify-content', 'center', 'important');
                    el.style.setProperty('margin', '0', 'important');
                    el.style.setProperty('padding', '0', 'important');
                });
            }
        }
    }

    // Strip (optional) label and mark phone input required
    function fixPhoneOptionalTag() {
        var phoneInputs = document.querySelectorAll('#phone, #billing-phone, #billing_phone, input[type="tel"], input[autocomplete="tel"]');
        phoneInputs.forEach(function (input) {
            input.setAttribute('required', 'required');
            input.required = true;
            var wrapper = input.closest('.wc-block-components-text-input, .form-row');
            if (wrapper) {
                var optionalSpan = wrapper.querySelector('.wc-block-components-text-input__optional, .optional');
                if (optionalSpan) {
                    optionalSpan.style.setProperty('display', 'none', 'important');
                }
                var label = wrapper.querySelector('label');
                if (label && label.childNodes) {
                    label.childNodes.forEach(function (node) {
                        if (node.nodeType === 3 && node.nodeValue && node.nodeValue.indexOf('(optional)') !== -1) {
                            node.nodeValue = node.nodeValue.replace('(optional)', '').trim();
                        }
                    });
                }
            }
        });
    }

    // HTML template for Express Checkout block inside payment section
    function buildExpressHtml() {
        return [
            '<div id="cwd-blink-express-container" class="cwd-blink-express-container cwd-blink-in-payment-section">',
            '  <div class="cwd-blink-express-header">',
            '    <div class="cwd-blink-divider-line"></div>',
            '    <span class="cwd-blink-express-title">OR PAY WITH</span>',
            '    <div class="cwd-blink-divider-line"></div>',
            '  </div>',
            '  <div class="cwd-blink-express-grid">',
            '    <div id="cwd-blink-gp-slot" class="cwd-blink-btn-slot">',
            '      <button type="button" id="cwd-gpay-placeholder" class="cwd-native-pay-btn cwd-gpay-native-btn">',
            '        <svg width="42" height="18" viewBox="0 0 42 18" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;">',
            '          <path fill="#fff" d="M12.7 7.4v4.5H11V2.8h4.5c1.1 0 2 .4 2.8 1.1.7.7 1.1 1.6 1.1 2.8 0 1.1-.4 2.1-1.1 2.8-.7.7-1.6 1.1-2.8 1.1h-2.8zm0-3.3v2h2.8c.6 0 1.1-.2 1.5-.6.4-.4.6-.9.6-1.5 0-.5-.2-1-.6-1.4-.4-.4-.9-.6-1.5-.6h-2.8v2.1zm8.7 7.8c-.8 0-1.4-.2-1.9-.7-.5-.5-.7-1.2-.7-2s.2-1.5.7-2c.5-.5 1.1-.7 1.9-.7.8 0 1.4.2 1.9.7.5.5.7 1.2.7 2s-.2 1.5-.7 2c-.5.5-1.1.7-1.9.7zm0-1.3c.4 0 .7-.1 1-.4.3-.3.4-.7.4-1.2 0-.5-.1-.9-.4-1.2-.3-.3-.6-.4-1-.4s-.7.1-1 .4c-.3.3-.4.7-.4 1.2 0 .5.1.9.4 1.2.3.3.6.4 1 .4zm7.4 1.3l-2.4-5.6h1.8l1.5 3.8 1.5-3.8h1.8l-4.1 9.3h-1.8l1.7-3.7z"/>',
            '          <path fill="#4285F4" d="M6.3 7.8c0-.3 0-.6-.1-.8H0v2.7h3.6c-.2.9-.7 1.7-1.5 2.2v1.8h2.4C5.9 12.3 6.3 10.3 6.3 7.8z"/>',
            '          <path fill="#34A853" d="M0 14.3c1.9 0 3.6-.6 4.8-1.7l-2.4-1.8c-.6.4-1.4.7-2.4.7-1.8 0-3.4-1.2-4-2.9h-2.4v1.9c1.2 2.3 3.6 3.8 6.4 3.8z"/>',
            '          <path fill="#FBBC05" d="M-4 8.6c-.2-.5-.3-1-.3-1.6 0-.6.1-1.1.3-1.6V3.5h-2.4C-6.9 4.5-7.2 5.7-7.2 7s.3 2.5.8 3.5l2.4-1.9z"/>',
            '          <path fill="#EA4335" d="M0 2.3c1.1 0 2 .4 2.8 1.1l2.1-2.1C3.6.5 1.9 0 0 0 -2.8 0-5.2 1.5-6.4 3.8l2.4 1.9C-3.4 3.5-1.8 2.3 0 2.3z"/>',
            '        </svg>',
            '      </button>',
            '    </div>',
            '    <div id="cwd-blink-ap-slot" class="cwd-blink-btn-slot">',
            '      <button type="button" id="cwd-applepay-placeholder" class="cwd-native-pay-btn cwd-applepay-native-btn">',
            '        <svg width="46" height="20" viewBox="0 0 46 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;">',
            '          <path fill="#fff" d="M6.9 4.8c-.5.6-1.3 1-2.1.9-.1-.8.2-1.6.7-2.2.5-.6 1.4-1 2.1-1 .1.8-.2 1.6-.7 2.3zm2.2 4.4c0-1.8 1.4-2.6 1.5-2.7-1.1-1.6-2.7-1.8-3.3-1.8-1.4-.2-2.8.8-3.5.8-.8 0-1.9-.8-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 3-.4 7.4 1.2 9.8.8 1.2 1.8 2.5 3 2.5 1.2 0 1.7-.8 3.1-.8 1.5 0 1.9.8 3.2.8 1.3 0 2.1-1.2 2.9-2.4.9-1.4 1.3-2.7 1.3-2.8-.1 0-2.4-1-2.4-3.6v-.2zm7.7 5.5v-7.3h3.5c1.8 0 3 1.2 3 2.8s-1.2 2.8-3 2.8h-1.9v1.7h-1.6zm1.6-3.1h1.7c1 0 1.6-.6 1.6-1.4s-.6-1.4-1.6-1.4h-1.7v2.8zm9.5 3.2c-1.3 0-2.4-.7-2.5-1.8h1.4c.1.5.6.8 1.2.8.7 0 1.1-.3 1.1-.8 0-.4-.3-.6-1.2-.8-1.5-.4-2.2-.9-2.2-2.1 0-1.2 1-2.1 2.4-2.1 1.2 0 2.1.6 2.3 1.7h-1.4c-.1-.4-.5-.7-1-.7-.6 0-1 .3-1 .7 0 .4.3.6 1.2.8 1.5.3 2.2.9 2.2 2.1 0 1.4-1 2.2-2.5 2.2zm7.6 0l-.8-2.3h-3.3l-.8 2.3h-1.6l3.3-9.1h1.6l3.3 9.1h-1.7zm-2.4-6.8l-1.3 3.5h2.5l-1.2-3.5zm7 6.8v-3.7l-2.6-5.4h1.7l1.7 3.9 1.7-3.9h1.7l-2.6 5.4v3.7h-1.6z"/>',
            '        </svg>',
            '      </button>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('');
    }

    // Mount Express Checkout INSIDE THE PAYMENT OPTIONS SECTION (directly below payment options list)
    function mountExpressContainer() {
        var container = document.getElementById('cwd-blink-express-container');
        if (!container) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = buildExpressHtml();
            container = wrapper.firstChild;
        }

        // Priority 1: Blocks Checkout - directly after the payment methods radio list
        var paymentMethods = document.querySelector('.wc-block-components-checkout-payment-methods');
        if (paymentMethods && paymentMethods.parentNode) {
            if (paymentMethods.nextSibling !== container) {
                paymentMethods.parentNode.insertBefore(container, paymentMethods.nextSibling);
            }
            alignButtons();
            return true;
        }

        // Priority 2: Blocks Checkout - inside payment methods block
        var paymentBlock = document.querySelector('.wc-block-checkout__payment-method');
        if (paymentBlock) {
            if (paymentBlock.lastChild !== container) {
                paymentBlock.appendChild(container);
            }
            alignButtons();
            return true;
        }

        // Priority 3: Classic Checkout - inside #payment after ul.wc_payment_methods
        var classicMethods = document.querySelector('#payment ul.wc_payment_methods')
                          || document.querySelector('.woocommerce-checkout-payment ul.payment_methods');
        if (classicMethods && classicMethods.parentNode) {
            if (classicMethods.nextSibling !== container) {
                classicMethods.parentNode.insertBefore(container, classicMethods.nextSibling);
            }
            alignButtons();
            return true;
        }

        // Priority 4: Classic Checkout - inside #payment before place-order box
        var paymentSec = document.querySelector('#payment') || document.querySelector('.woocommerce-checkout-payment');
        if (paymentSec) {
            var placeOrderBox = paymentSec.querySelector('.place-order');
            if (placeOrderBox && placeOrderBox.parentNode) {
                if (placeOrderBox.previousSibling !== container) {
                    placeOrderBox.parentNode.insertBefore(container, placeOrderBox);
                }
            } else {
                paymentSec.appendChild(container);
            }
            alignButtons();
            return true;
        }

        // Priority 5: Fallback before actions row
        var actionsRow = document.querySelector('.wc-block-checkout__actions_row')
                      || document.querySelector('.wc-block-checkout__actions')
                      || document.querySelector('.wc-block-components-checkout-place-order-button')
                      || document.querySelector('#place_order');

        if (actionsRow && actionsRow.parentNode) {
            actionsRow.parentNode.insertBefore(container, actionsRow);
            alignButtons();
            fixPhoneOptionalTag();
            return true;
        }

        fixPhoneOptionalTag();
        return false;
    }

    // Keep mounted in payment section even when React re-renders checkout blocks
    function startMounting() {
        mountExpressContainer();
        fixPhoneOptionalTag();

        var attempts = 0;
        var interval = setInterval(function () {
            attempts++;
            mountExpressContainer();
            alignButtons();
            fixPhoneOptionalTag();
            if (attempts > 50) {
                clearInterval(interval);
            }
        }, 100);

        // Observer for dynamic WooCommerce Blocks changes
        try {
            var observer = new MutationObserver(function () {
                var paymentMethods = document.querySelector('.wc-block-components-checkout-payment-methods');
                var container = document.getElementById('cwd-blink-express-container');
                if (paymentMethods && (!container || paymentMethods.nextSibling !== container)) {
                    mountExpressContainer();
                }
                alignButtons();
                fixPhoneOptionalTag();
            });

            var target = document.querySelector('.wc-block-checkout__main')
                      || document.querySelector('.woocommerce-checkout')
                      || document.body;

            if (target) {
                observer.observe(target, { childList: true, subtree: true });
            }
        } catch (e) {}
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        startMounting();
    } else {
        document.addEventListener('DOMContentLoaded', startMounting);
    }
    window.addEventListener('load', function () {
        mountExpressContainer();
        alignButtons();
        fixPhoneOptionalTag();
    });

    // Fetch intent and load native Blink scripts
    function initBlinkElements() {
        // Prevent removal of Apple Pay button on desktop browsers
        try {
            if (typeof window.ApplePaySession !== 'undefined') {
                window.ApplePaySession.canMakePayments = function () { return true; };
            }
            window.blinkApplePaySdkLoaded = true;
        } catch (e) {}

        var ajaxUrl = config.ajax_url || '/wp-admin/admin-ajax.php';
        var nonce = config.nonce || '';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res && res.success && res.data && res.data.element) {
                        applyBlinkElements(res.data.element);
                    }
                } catch (err) {
                    console.warn('[Blink Express] JSON parse error:', err);
                }
            }
        };
        xhr.send('action=cwd_blink_express_intent&nonce=' + encodeURIComponent(nonce));
    }

    // Inject scripts and HTML elements safely
    function applyBlinkElements(element) {
        var processUrl = config.process_url || window.location.href;

        // 1. Google Pay Element
        if (element.gpElement) {
            var gpSlot = document.getElementById('cwd-blink-gp-slot');
            if (gpSlot) {
                var gpForm = document.createElement('form');
                gpForm.method = 'POST';
                gpForm.action = processUrl;
                gpForm.id = 'gpPayment';
                gpForm.className = 'cwd-blink-pay-form';
                gpForm.innerHTML = '<input type="hidden" name="cwd_express_provider" value="googlepay"/>' +
                                   '<input type="hidden" name="cwd_blink_express_nonce" value="' + (config.nonce || '') + '"/>' +
                                   element.gpElement;

                gpSlot.innerHTML = '';
                gpSlot.appendChild(gpForm);

                // Patch before scripts run
                patchGooglePayments();

                // Re-execute scripts inside gpElement
                Array.from(gpForm.querySelectorAll('script')).forEach(function (oldScript) {
                    var newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(function (attr) {
                        newScript.setAttribute(attr.name, attr.value);
                    });
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });

                setTimeout(alignButtons, 100);
                setTimeout(alignButtons, 300);
                setTimeout(alignButtons, 800);
            }
        }

        // 2. Apple Pay Element
        if (element.apElement) {
            var apSlot = document.getElementById('cwd-blink-ap-slot');
            if (apSlot) {
                var apForm = document.createElement('form');
                apForm.method = 'POST';
                apForm.action = processUrl;
                apForm.id = 'apPayment';
                apForm.className = 'cwd-blink-pay-form';
                apForm.innerHTML = '<input type="hidden" name="cwd_express_provider" value="applepay"/>' +
                                   '<input type="hidden" name="cwd_blink_express_nonce" value="' + (config.nonce || '') + '"/>' +
                                   element.apElement;

                apSlot.innerHTML = '';
                apSlot.appendChild(apForm);

                // Prevent Apple Pay button from being dropped on Windows/desktop
                try {
                    if (typeof window.ApplePaySession !== 'undefined') {
                        window.ApplePaySession.canMakePayments = function () { return true; };
                    }
                } catch (e) {}

                // Re-execute scripts inside apElement
                Array.from(apForm.querySelectorAll('script')).forEach(function (oldScript) {
                    var newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(function (attr) {
                        newScript.setAttribute(attr.name, attr.value);
                    });
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });

                setTimeout(alignButtons, 100);
                setTimeout(alignButtons, 300);
                setTimeout(alignButtons, 800);
            }
        }
    }

    // Trigger intent loading when ready
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(initBlinkElements, 200);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initBlinkElements, 200);
        });
    }

    // Delegate click on placeholders if clicked before scripts finish loading
    document.addEventListener('click', function (e) {
        var gBtn = e.target.closest('#cwd-gpay-placeholder');
        if (gBtn) {
            var actualBtn = document.querySelector('.cwd-blink-gp-container button, #container button, .gpay-button');
            if (actualBtn) actualBtn.click();
        }

        var aBtn = e.target.closest('#cwd-applepay-placeholder');
        if (aBtn) {
            var actualApple = document.querySelector('#apple-pay-btn, apple-pay-button');
            if (actualApple) actualApple.click();
        }
    });

})();
