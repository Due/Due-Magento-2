# Due Magento2 Payments

## Manual Installation

```bash
composer require due/due-magento2-payments
php bin/magento module:enable Due_Payments --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```

