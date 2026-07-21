'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const display = require('../../assets/js/storefront-price-display.js');

test('divides grouped Latin-digit IRR prices by exactly ten', () => {
    assert.equal(display.convertFormattedNumber('300,000'), '30,000');
    assert.equal(display.convertFormattedNumber('65,585'), '6,558.5');
    assert.equal(display.convertFormattedNumber('12,340.00'), '1,234');
    assert.equal(display.convertFormattedNumber('5'), '0.5');
    assert.equal(
        display.convertFormattedNumber('9,999,999,999,999,999'),
        '999,999,999,999,999.9'
    );
});

test('preserves Persian and Arabic digit families and separators', () => {
    assert.equal(display.convertFormattedNumber('۳۰۰٬۰۰۰'), '۳۰٬۰۰۰');
    assert.equal(display.convertFormattedNumber('۶۵٬۵۸۵'), '۶٬۵۵۸٫۵');
    assert.equal(display.convertFormattedNumber('۱٫۵'), '۰٫۱۵');
    assert.equal(display.convertFormattedNumber('٣٠٠٬٠٠٠'), '٣٠٬٠٠٠');
});

test('converts a visible amount and currency label without rounding', () => {
    assert.equal(display.convertPriceText('۶۵٬۵۸۵ ریال'), '۶٬۵۵۸٫۵ تومان');
    assert.equal(display.convertPriceText('300,000 IRR'), '30,000 تومان');
    assert.equal(display.convertPriceText('۱۰۰ ﷼'), '۱۰ تومان');
});

test('preserves exact original text formatting for converted carousel clones', () => {
    const originals = ['۳,۵۰۰', ' ریال', '12,340.00', '<img src=x onerror=alert(1)>'];
    const encoded = display.encodeTextValues(originals);
    assert.deepEqual(display.decodeTextValues(encoded), originals);
    assert.equal(display.decodeTextValues('{bad json'), null);
    assert.equal(display.decodeTextValues('{"html":"not an array"}'), null);
});

test('has a safe numeric fallback when a theme drops text metadata', () => {
    assert.equal(display.restoreIrrPriceText('۶٬۵۵۸٫۵ تومان'), '۶۵٬۵۸۵ ریال');
    assert.equal(display.convertFormattedTomanNumber('0.5'), '5');
});

test('render tokens change when a cloned price fragment is replaced', () => {
    assert.equal(display.markupToken('<span>30,000 تومان</span>'), display.markupToken('<span>30,000 تومان</span>'));
    assert.notEqual(display.markupToken('<span>30,000 تومان</span>'), display.markupToken('<span>40,000 ریال</span>'));
});

test('accepts only the two display preferences', () => {
    assert.equal(display.normalizeCurrency('IRT'), 'IRT');
    assert.equal(display.normalizeCurrency('IRR'), 'IRR');
    assert.equal(display.normalizeCurrency('USD'), 'IRR');
    assert.equal(display.normalizeCurrency(null), 'IRR');
});
