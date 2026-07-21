(function (factory) {
    'use strict';

    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (typeof window !== 'undefined' && typeof document !== 'undefined') {
        api.init(window, document);
    }
}(function () {
    'use strict';

    var TARGET_SELECTOR = [
        '.woocommerce-Price-amount.amount',
        '.wc-block-formatted-money-amount',
        '.wc-block-components-formatted-money-amount',
        '.price .screen-reader-text',
        '.wc-block-components-product-price .screen-reader-text'
    ].join(',');

    var PRODUCT_CONTEXT_SELECTOR = [
        '.price',
        '.product',
        '.woocommerce-variation-price',
        '.wc-block-grid__product-price',
        '.wc-block-components-product-price',
        '.wp-block-woocommerce-product-price',
        '[data-block-name="woocommerce/product-price"]',
        '.wd-product-price'
    ].join(',');

    var EXCLUDED_CONTEXT_SELECTOR = [
        '.widget_shopping_cart',
        '.woocommerce-mini-cart',
        '.wc-block-mini-cart',
        '.wc-block-components-drawer',
        '[data-block-name="woocommerce/mini-cart"]',
        '.cart-widget-side',
        '.woocommerce-cart-form',
        '.woocommerce-cart',
        '.cart_totals',
        '.wc-block-cart',
        '.woocommerce-checkout',
        'form.checkout',
        '.wc-block-checkout',
        '.woocommerce-order-pay',
        '.woocommerce-order-received',
        '.woocommerce-order',
        '.woocommerce-order-details',
        '.woocommerce-orders-table',
        '[class*="wc-block-order-confirmation"]',
        '.order_details',
        '.woocommerce-MyAccount-content'
    ].join(',');

    var NUMBER_PATTERN = /[0-9۰-۹٠-٩](?:[0-9۰-۹٠-٩]|[.,٬٫]|\u00a0|\u202f| )*[0-9۰-۹٠-٩]|[0-9۰-۹٠-٩]/g;
    var PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    var ARABIC_DIGITS = '٠١٢٣٤٥٦٧٨٩';

    function normalizeCurrency(value) {
        return value === 'IRT' ? 'IRT' : 'IRR';
    }

    function latinDigit(character) {
        var index = PERSIAN_DIGITS.indexOf(character);
        if (index !== -1) {
            return String(index);
        }
        index = ARABIC_DIGITS.indexOf(character);
        return index === -1 ? character : String(index);
    }

    function digitFamily(token) {
        if (/[۰-۹]/.test(token)) {
            return 'persian';
        }
        if (/[٠-٩]/.test(token)) {
            return 'arabic';
        }
        return 'latin';
    }

    function localizeDigits(value, family) {
        if (family === 'latin') {
            return value;
        }
        var digits = family === 'persian' ? PERSIAN_DIGITS : ARABIC_DIGITS;
        return value.replace(/[0-9]/g, function (digit) {
            return digits.charAt(Number(digit));
        });
    }

    function countCharacter(value, character) {
        return value.split(character).length - 1;
    }

    function digitsAfterLast(value, separator) {
        var index = value.lastIndexOf(separator);
        return index === -1 ? 0 : value.slice(index + 1).replace(/\D/g, '').length;
    }

    function detectDecimalSeparator(value) {
        if (value.indexOf('٫') !== -1) {
            return '٫';
        }

        var dot = value.lastIndexOf('.');
        var comma = value.lastIndexOf(',');
        if (dot !== -1 && comma !== -1) {
            return dot > comma ? '.' : ',';
        }

        var candidate = dot !== -1 ? '.' : (comma !== -1 ? ',' : '');
        if (!candidate || countCharacter(value, candidate) !== 1) {
            return '';
        }

        var trailingDigits = digitsAfterLast(value, candidate);
        return trailingDigits > 0 && trailingDigits !== 3 ? candidate : '';
    }

    function detectGroupSeparator(value, decimalSeparator) {
        if (value.indexOf('٬') !== -1) {
            return '٬';
        }
        if (value.indexOf('\u202f') !== -1) {
            return '\u202f';
        }
        if (value.indexOf('\u00a0') !== -1) {
            return '\u00a0';
        }
        if (value.indexOf(' ') !== -1) {
            return ' ';
        }
        if (decimalSeparator !== ',' && value.indexOf(',') !== -1) {
            return ',';
        }
        if (decimalSeparator !== '.' && value.indexOf('.') !== -1) {
            return '.';
        }
        return '';
    }

    function groupWholeNumber(value, separator) {
        if (!separator) {
            return value;
        }
        return value.replace(/\B(?=(\d{3})+(?!\d))/g, separator);
    }

    function scaleFormattedNumber(token, decimalShift) {
        var family = digitFamily(token);
        var latin = token.replace(/[۰-۹٠-٩]/g, latinDigit);
        var decimalSeparator = detectDecimalSeparator(latin);
        var groupSeparator = detectGroupSeparator(latin, decimalSeparator);
        var decimalIndex = decimalSeparator ? latin.lastIndexOf(decimalSeparator) : -1;
        var wholeSource = decimalIndex === -1 ? latin : latin.slice(0, decimalIndex);
        var fractionSource = decimalIndex === -1 ? '' : latin.slice(decimalIndex + 1);
        var wholeDigits = wholeSource.replace(/\D/g, '') || '0';
        var fractionDigits = fractionSource.replace(/\D/g, '');
        var combined = (wholeDigits + fractionDigits).replace(/^0+(?=\d)/, '');
        var scale = fractionDigits.length + decimalShift;

        if (scale < 0) {
            combined += new Array(Math.abs(scale) + 1).join('0');
            scale = 0;
        }

        if (scale > 0 && combined.length <= scale) {
            combined = new Array(scale - combined.length + 2).join('0') + combined;
        }

        var split = combined.length - scale;
        var whole = combined.slice(0, split).replace(/^0+(?=\d)/, '') || '0';
        var fraction = combined.slice(split).replace(/0+$/, '');
        whole = groupWholeNumber(whole, groupSeparator);

        if (!decimalSeparator) {
            if (family === 'persian' || family === 'arabic' || groupSeparator === '٬') {
                decimalSeparator = '٫';
            } else if (groupSeparator === '.') {
                decimalSeparator = ',';
            } else {
                decimalSeparator = '.';
            }
        }

        return localizeDigits(whole + (fraction ? decimalSeparator + fraction : ''), family);
    }

    /**
     * Exactly divides one formatted IRR number by ten without floating point.
     */
    function convertFormattedNumber(token) {
        return scaleFormattedNumber(token, 1);
    }

    function convertFormattedTomanNumber(token) {
        return scaleFormattedNumber(token, -1);
    }

    function convertPriceText(text, tomanLabel) {
        return text
            .replace(NUMBER_PATTERN, convertFormattedNumber)
            .replace(/ریال|IRR|﷼/g, tomanLabel || 'تومان');
    }

    function restoreIrrPriceText(text, rialLabel) {
        return text
            .replace(NUMBER_PATTERN, convertFormattedTomanNumber)
            .replace(/تومان|IRT/g, rialLabel || 'ریال');
    }

    function encodeTextValues(values) {
        return JSON.stringify(values);
    }

    function decodeTextValues(serialized) {
        try {
            var values = JSON.parse(serialized);
            if (!Array.isArray(values) || !values.every(function (value) {
                return typeof value === 'string';
            })) {
                return null;
            }
            return values;
        } catch (error) {
            return null;
        }
    }

    function markupToken(value) {
        var hash = 2166136261;
        for (var index = 0; index < value.length; index += 1) {
            hash ^= value.charCodeAt(index);
            hash = Math.imul(hash, 16777619);
        }
        return value.length.toString(36) + '-' + (hash >>> 0).toString(36);
    }

    function init(browserWindow, browserDocument) {
        function start() {
            var config = browserWindow.ashcoStorefrontPriceDisplay;
            var switcher = browserDocument.querySelector('.ashco-price-display-switch');
            if (!config || Number(config.conversionRate) !== 10 || !switcher || !browserDocument.body) {
                return;
            }

            var states = new WeakMap();
            var currentCurrency = readPreference(browserWindow, browserDocument, config);
            var updateQueued = false;

            function isProductDisplayTarget(element) {
                return element instanceof browserWindow.Element
                    && !!element.closest(PRODUCT_CONTEXT_SELECTOR)
                    && !element.closest(EXCLUDED_CONTEXT_SELECTOR);
            }

            function transformHtml(html, transformText) {
                var template = browserDocument.createElement('template');
                template.innerHTML = html;
                var walker = browserDocument.createTreeWalker(
                    template.content,
                    browserWindow.NodeFilter.SHOW_TEXT
                );
                var node;
                while ((node = walker.nextNode())) {
                    node.nodeValue = transformText(node.nodeValue);
                }
                return template.innerHTML;
            }

            function textValuesFromHtml(html) {
                var template = browserDocument.createElement('template');
                template.innerHTML = html;
                var walker = browserDocument.createTreeWalker(
                    template.content,
                    browserWindow.NodeFilter.SHOW_TEXT
                );
                var values = [];
                var node;
                while ((node = walker.nextNode())) {
                    values.push(node.nodeValue);
                }
                return values;
            }

            function restoreIrrTextHtml(html, serializedValues) {
                var values = decodeTextValues(serializedValues);
                if (values === null) {
                    return null;
                }
                var template = browserDocument.createElement('template');
                template.innerHTML = html;
                var walker = browserDocument.createTreeWalker(
                    template.content,
                    browserWindow.NodeFilter.SHOW_TEXT
                );
                var nodes = [];
                var node;
                while ((node = walker.nextNode())) {
                    nodes.push(node);
                }
                if (nodes.length !== values.length) {
                    return null;
                }
                nodes.forEach(function (textNode, index) {
                    // Attribute data is restored only through nodeValue. It is
                    // never parsed or assigned as HTML.
                    textNode.nodeValue = values[index];
                });
                return template.innerHTML;
            }

            function transformedTomanHtml(irrHtml) {
                return transformHtml(irrHtml, function (text) {
                    return convertPriceText(text, config.labels.IRT);
                });
            }

            function inverseIrrHtml(tomanHtml) {
                return transformHtml(tomanHtml, function (text) {
                    return restoreIrrPriceText(text, config.labels.IRR);
                });
            }

            function renderTarget(element) {
                var currentHtml = element.innerHTML;
                var state = states.get(element);

                if (!state) {
                    // A harmless checksum distinguishes an exact converted carousel
                    // clone from a fresh Woo price whose stale marker was retained.
                    var carriedCurrency = element.getAttribute('data-ashco-display-currency');
                    var carriedToken = element.getAttribute('data-ashco-render-token');
                    var carriedTextValues = element.getAttribute('data-ashco-irr-text-values');
                    var isExactTomanClone = carriedCurrency === 'IRT'
                        && carriedToken !== null
                        && carriedToken === markupToken(currentHtml);
                    var restoredIrrHtml = isExactTomanClone
                        ? restoreIrrTextHtml(currentHtml, carriedTextValues)
                        : null;
                    if (isExactTomanClone && restoredIrrHtml === null) {
                        restoredIrrHtml = inverseIrrHtml(currentHtml);
                    }
                    state = restoredIrrHtml !== null
                        ? {irrHtml: restoredIrrHtml, renderedHtml: currentHtml}
                        : {irrHtml: currentHtml, renderedHtml: currentHtml};
                } else if (currentHtml !== state.renderedHtml) {
                    // A Woo/theme fragment replaced the price. Its new markup is IRR.
                    state = {irrHtml: currentHtml, renderedHtml: currentHtml};
                }

                var desiredHtml = currentCurrency === 'IRT'
                    ? transformedTomanHtml(state.irrHtml)
                    : state.irrHtml;
                if (element.innerHTML !== desiredHtml) {
                    element.innerHTML = desiredHtml;
                }
                state.renderedHtml = desiredHtml;
                states.set(element, state);
                element.setAttribute('data-ashco-display-currency', currentCurrency);
                element.setAttribute('data-ashco-render-token', markupToken(desiredHtml));
                element.setAttribute(
                    'data-ashco-irr-text-values',
                    encodeTextValues(textValuesFromHtml(state.irrHtml))
                );
            }

            function restoreExcludedTarget(element) {
                var state = states.get(element);
                if (!state && !element.hasAttribute('data-ashco-display-currency')) {
                    return;
                }

                var currentHtml = element.innerHTML;
                var irrHtml = null;
                if (state && currentHtml === state.renderedHtml) {
                    irrHtml = state.irrHtml;
                } else if (
                    element.getAttribute('data-ashco-display-currency') === 'IRT'
                    && element.getAttribute('data-ashco-render-token') === markupToken(currentHtml)
                ) {
                    irrHtml = restoreIrrTextHtml(
                        currentHtml,
                        element.getAttribute('data-ashco-irr-text-values')
                    );
                    if (irrHtml === null) {
                        irrHtml = inverseIrrHtml(currentHtml);
                    }
                }

                // A mismatched marker means Woo already supplied fresh IRR markup.
                if (irrHtml !== null && currentHtml !== irrHtml) {
                    element.innerHTML = irrHtml;
                }
                states.delete(element);
                element.removeAttribute('data-ashco-display-currency');
                element.removeAttribute('data-ashco-render-token');
                element.removeAttribute('data-ashco-irr-text-values');
            }

            function outermostTargets(targets) {
                return targets.filter(function (candidate) {
                    return !targets.some(function (other) {
                        return other !== candidate && other.contains(candidate);
                    });
                });
            }

            function renderAll() {
                var allTargets = Array.prototype.slice.call(browserDocument.querySelectorAll(TARGET_SELECTOR));
                var excludedTargets = outermostTargets(allTargets.filter(function (element) {
                    return element instanceof browserWindow.Element
                        && !!element.closest(EXCLUDED_CONTEXT_SELECTOR);
                }));
                excludedTargets.forEach(restoreExcludedTarget);

                allTargets = Array.prototype.slice.call(browserDocument.querySelectorAll(TARGET_SELECTOR));
                var targets = allTargets
                    .filter(isProductDisplayTarget);

                // If a target contains another target, transforming the ancestor is sufficient.
                targets = outermostTargets(targets);
                targets.forEach(renderTarget);
                browserDocument.documentElement.setAttribute('data-ashco-display-currency', currentCurrency);
            }

            function queueRender() {
                if (updateQueued) {
                    return;
                }
                updateQueued = true;
                var schedule = browserWindow.requestAnimationFrame
                    ? browserWindow.requestAnimationFrame.bind(browserWindow)
                    : browserWindow.setTimeout.bind(browserWindow);
                schedule(function () {
                    updateQueued = false;
                    renderAll();
                });
            }

            function syncControl(announce) {
                var controls = switcher.querySelectorAll('input[name="ashco-price-display-currency"]');
                Array.prototype.forEach.call(controls, function (control) {
                    control.checked = control.value === currentCurrency;
                });
                var status = switcher.querySelector('.ashco-price-display-switch__status');
                if (status) {
                    status.textContent = config.status[currentCurrency];
                    if (announce) {
                        status.setAttribute('data-ashco-announced', currentCurrency);
                    }
                }
            }

            switcher.addEventListener('change', function (event) {
                var control = event.target;
                if (!control.matches('input[name="ashco-price-display-currency"]')) {
                    return;
                }
                currentCurrency = normalizeCurrency(control.value);
                persistPreference(browserWindow, browserDocument, config, currentCurrency);
                syncControl(true);
                renderAll();
            });

            syncControl(false);
            renderAll();
            switcher.hidden = false;

            var observer = new browserWindow.MutationObserver(queueRender);
            observer.observe(browserDocument.body, {childList: true, characterData: true, subtree: true});
            browserWindow.addEventListener('pageshow', function () {
                currentCurrency = readPreference(browserWindow, browserDocument, config);
                syncControl(false);
                renderAll();
            });
        }

        if (browserDocument.readyState === 'loading') {
            browserDocument.addEventListener('DOMContentLoaded', start, {once: true});
        } else {
            start();
        }
    }

    function readPreference(browserWindow, browserDocument, config) {
        var value = '';
        try {
            value = browserWindow.localStorage.getItem(config.storageKey) || '';
        } catch (error) {
            value = '';
        }
        if (value !== 'IRR' && value !== 'IRT') {
            var prefix = encodeURIComponent(config.cookieName) + '=';
            var cookies = browserDocument.cookie ? browserDocument.cookie.split(';') : [];
            cookies.some(function (cookie) {
                cookie = cookie.trim();
                if (cookie.indexOf(prefix) !== 0) {
                    return false;
                }
                try {
                    value = decodeURIComponent(cookie.slice(prefix.length));
                } catch (error) {
                    value = '';
                }
                return true;
            });
        }
        return normalizeCurrency(value || config.defaultCurrency);
    }

    function persistPreference(browserWindow, browserDocument, config, value) {
        value = normalizeCurrency(value);
        try {
            browserWindow.localStorage.setItem(config.storageKey, value);
        } catch (error) {
            // A same-site, non-identifying cookie remains as the persistence fallback.
        }
        var cookie = encodeURIComponent(config.cookieName) + '=' + encodeURIComponent(value)
            + ';path=/;max-age=31536000;samesite=lax';
        if (browserWindow.location && browserWindow.location.protocol === 'https:') {
            cookie += ';secure';
        }
        browserDocument.cookie = cookie;
    }

    return {
        convertFormattedNumber: convertFormattedNumber,
        convertFormattedTomanNumber: convertFormattedTomanNumber,
        convertPriceText: convertPriceText,
        restoreIrrPriceText: restoreIrrPriceText,
        encodeTextValues: encodeTextValues,
        decodeTextValues: decodeTextValues,
        markupToken: markupToken,
        normalizeCurrency: normalizeCurrency,
        init: init
    };
}));
