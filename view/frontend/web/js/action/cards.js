define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'Magento_Checkout/js/model/full-screen-loader'
    ],
    function ($, quote, urlBuilder, fullScreenLoader) {
        'use strict';

        return $.ajax('/due/vault/cards', {
            method: 'GET',
            beforeSend: function () {
                fullScreenLoader.startLoader();
            }
        }).always(function () {
            fullScreenLoader.stopLoader();
        });
    }
);
