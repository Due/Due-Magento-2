# Due Magento2 Payments

## Manual Installation

1. Go to root directory of magento2

2. Enter following commands to install module:

```bash
composer require due/due-magento2-payments
```

3. Enter following commands to install module:

```bash
php bin/magento module:enable Due_Payments --clear-static-content
php bin/magento setup:upgrade
php bin/magento cache:clean
```

4. Enable and configure Due.com Payment gateway in Magento Admin under Stores > Configuration > Sales > Payment Methods > Due.com Payments.
