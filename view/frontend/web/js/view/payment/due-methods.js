define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'due_cc',
                component: 'Due_Payments/js/view/payment/method-renderer/due-cc-method'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
