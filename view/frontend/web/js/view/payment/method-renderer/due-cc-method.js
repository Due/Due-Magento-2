/*browser:true*/
/*global define*/
define(
    [
        'ko',
        'jquery',
        'underscore',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/redirect-on-success',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Ui/js/model/messageList',
        'mage/translate',
        'jquery.payment',
        'jquery/validate'
    ],
    function (ko,
              $,
              _,
              Component,
              placeOrderAction,
              redirectOnSuccessAction,
              additionalValidators,
              quote,
              customer,
              fullScreenLoader,
              globalMessageList,
              $t
    ) {
        'use strict';
        var validator = null;

        return Component.extend({
            defaults: {
                self: this,
                template: 'Due_Payments/payment/cc'
            },
            initialize: function () {
                this._super();

                var self = this;
                var code = this.getCode();

                // Init Due
                this.initDue(window.checkoutConfig.payment.due_cc.env, window.checkoutConfig.payment.due_cc.app_id, window.checkoutConfig.payment.due_cc.rail_type);
                if (typeof window.dueTokens === 'undefined') {
                    window.dueTokens = {};
                }

                // Init Validator
                $.validator.addMethod('cardNumber', function (value, element) {
                    return this.optional(element) || $.payment.validateCardNumber(value);
                }, $t('Please specify a valid credit card number.'));

                $.validator.addMethod('cardExpiry', function (value, element) {
                    var expiry = $.payment.cardExpiryVal(value);
                    return this.optional(element) || $.payment.validateCardExpiry(expiry['month'], expiry['year']);
                }, $t('Invalid expiration date.'));

                $.validator.addMethod('cardCVC', function (value, element) {
                    return this.optional(element) || $.payment.validateCardCVC(value);
                }, $t('Invalid CVC.'));

                // Init jQuery Payment
                $(document).ready(function () {
                    window.setTimeout(function () {
                        var form = $('#payment_form_' + code);
                        /* Fancy restrictive input formatting via jQuery.payment library*/
                        $('[name="payment[cc_number]"]', form).payment('formatCardNumber');
                        $('[name="payment[cc_cvc]"]', form).payment('formatCardCVC');
                        $('[name="payment[cc_expiry]"]', form).payment('formatCardExpiry');

                        self.validator = form.closest('form').validate({
                            rules: {
                                'payment[cc_number]': {
                                    required: true,
                                    cardNumber: true
                                },
                                'payment[cc_expiry]': {
                                    required: true,
                                    cardExpiry: true
                                },
                                'payment[cc_cvc]': {
                                    required: true,
                                    cardCVC: true
                                }
                            },
                            highlight: function (element) {
                                $(element).closest('.control').removeClass('success').addClass('error');
                            },
                            unhighlight: function (element) {
                                $(element).closest('.control').removeClass('error').addClass('success');
                            },
                            errorPlacement: function (error, element) {
                                $(element).closest('.control').append(error);
                            }
                        });
                    }, 1000);
                });

                return this;
            },
            /**
             * @override
             */
            getData: function () {
                var form = $('#payment_form_' + this.getCode());
                var vault = $('[name="payment[vault]"]', form).val();
                if (vault !== '') {
                    return {
                        'method': this.getCode(),
                        'additional_data': {
                            'vault': vault
                        }
                    };
                }

                var cardNumberField = $('[name="payment[cc_number]"]', form);
                var cardCVCField = $('[name="payment[cc_cvc]"]', form);
                var cardNumber = cardNumberField.val().replace(/^\s+/, '');
                var cardCVC = cardCVCField.val();
                var cardExpiry = $.payment.cardExpiryVal($('[name="payment[cc_expiry]"]', form).val());
                var cardType = this.getCardType(cardNumber);
                var cardLast4 = cardNumber.substr(cardNumber.length - 4);
                var cardExpMonth = cardExpiry.month < 10 ? '0' + cardExpiry.month : cardExpiry.month;
                var cardExpYear = cardExpiry.year < 2000 ? cardExpiry.year + 2000 : cardExpiry.year;
                var dueToken = $('[name="payment[cc_due_token]"]', form).val();

                return {
                    'method': this.getCode(),
                    'additional_data': {
                        'cc_number': !cardNumberField.prop('disabled') ? cardNumber : '',
                        'cc_cvc': !cardCVCField.prop('disabled') ? cardCVC : '',
                        'cc_type': cardType,
                        'cc_last_4': cardLast4,
                        'cc_exp_month': cardExpMonth,
                        'cc_exp_year': cardExpYear,
                        'cc_due_token': dueToken,
                        'cc_save_card': this.isVaultEnabled() && $('[name="payment[save_card]"]', form).prop('checked')
                    }
                };
            },
            /**
             * @override
             */
            placeOrder: function () {
                var form = $('#payment_form_' + this.getCode());
                var vault = $('[name="payment[vault]"]', form).val();
                return (vault === '') ? this.placeOrderWithCard() : this.placeOrderWithSavedCard();
            },
            placeOrderWithCard: function () {
                var self = this;
                var form = $('#payment_form_' + self.getCode());

                if (!this.validator.form()) {
                    return false;
                }

                if (additionalValidators.validate()) {
                    var data = this.getData();
                    var address = quote.billingAddress();
                    var email = customer.isLoggedIn() ? window.checkoutConfig.quoteData.customer_email : quote.guestEmail;

                    // Prepare details
                    var cardDetails = {
                        "name"       : address.firstname + ' ' + address.lastname,
                        "email"      : email,
                        "card_number": data.additional_data.cc_number,
                        "cvv"        : data.additional_data.cc_cvc,
                        "exp_month"  : data.additional_data.cc_exp_month,
                        "exp_year"   : data.additional_data.cc_exp_year,
                        "address"    : {
                            "postal_code": address.postcode
                        }
                    };

                    fullScreenLoader.startLoader();
                    self.createDueToken(cardDetails, function (err, token) {
                        fullScreenLoader.stopLoader();
                        if (err) {
                            globalMessageList.addErrorMessage({
                                message: err
                            });
                            return false;
                        }

                        // Set token
                        $('[name="payment[cc_due_token]"]', form).val(token);

                        // Lock fields
                        $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', true);

                        self.isPlaceOrderActionAllowed(false);
                        self.getPlaceOrderDeferredObject()
                            .always(function() {
                                // Unlock fields
                                $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', false);
                            })
                            .fail(
                                function () {
                                    self.isPlaceOrderActionAllowed(true);
                                }
                            ).done(
                            function () {
                                self.afterPlaceOrder();
                                if (self.redirectAfterPlaceOrder) {
                                    redirectOnSuccessAction.execute();
                                }
                            }
                        );

                        return true;
                    });

                    return false;
                }
            },
            placeOrderWithSavedCard: function () {
                var self = this;
                var form = $('#payment_form_' + self.getCode());

                if (!this.validator.form()) {
                    return false;
                }

                if (additionalValidators.validate()) {
                    fullScreenLoader.startLoader();

                    // Lock fields
                    $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', true);

                    self.isPlaceOrderActionAllowed(false);
                    self.getPlaceOrderDeferredObject()
                        .always(function() {
                            // Unlock fields
                            $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', false);
                        })
                        .fail(
                            function () {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        ).done(
                        function () {
                            self.afterPlaceOrder();
                            if (self.redirectAfterPlaceOrder) {
                                redirectOnSuccessAction.execute();
                            }
                        }
                    );
                }
            },
            initDue: function (env, app_id, rail_type) {
                // Load Due dynamically
                if (typeof Due === 'undefined') {
                    $.getScript('https://static.due.com/v1.1/due.min.js', function () {
                        Due.load.init(env, rail_type);
                        Due.load.setAppId(app_id);
                    } );
                } else {
                    Due.load.init(env, rail_type);
                    Due.load.setAppId(app_id);
                }
            },
            getCardType: function (cardNumber) {
                var cardType = $.payment.cardType(cardNumber);
                var cardTypes = {
                    visa: 'VI',
                    mastercard: 'MC',
                    discover: 'DI',
                    jcb: 'JCB',
                    dinersclub: 'DN',
                    amex: 'AE'
                };

                return typeof cardTypes[cardType] !== 'undefined' ? cardTypes[cardType] : cardType;
            },
            createDueToken: function (cardDetails, callback) {
                var cardKey = JSON.stringify(cardDetails);
                if (window.dueTokens[cardKey]) {
                    return callback(null, window.dueTokens[cardKey]);
                }

                Due.payments.card.create(cardDetails, function (data) {
                    if (!data || !data.hasOwnProperty('card_id')) {
                        return callback($t('Unable to tokenize card'), false);
                    }

                    window.dueTokens[cardKey] = data.card_id + ':' + data.card_hash + ':' + data.risk_token;
                    return callback(null, window.dueTokens[cardKey]);
                });
            },
            /**
             * @returns {Bool}
             */
            isVaultEnabled: function () {
                return customer.isLoggedIn() && window.checkoutConfig.payment.due_cc.vault_active;
            },
            getSavedCards: function() {
                return _.map(window.checkoutConfig.payment.due_cc.saved_cards, function(value, key) {
                    return {
                        'key': value.id,
                        'value': value.title
                    }
                });
            },
            isHaveSavedCards: function () {
                return window.checkoutConfig.payment.due_cc.saved_cards.length > 0;
            },
            onVaultChange: function (obj, event) {
                var self = this;
                var form = $('#payment_form_' + self.getCode());
                var vault = $('[name="payment[vault]"]', form).val();
                if (vault === '') {
                    $('.credit-card-form', form).slideDown();
                    $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', false);
                } else {
                    $('.credit-card-form', form).slideUp();
                    $('[name="payment[cc_number]"], [name="payment[cc_expiry]"], [name="payment[cc_cvc]"]', form).prop('disabled', true);
                    $('[name="payment[save_card]"]', form).prop('checked', false);
                }
            }
        });
    }
);
