# elgentos/magento2-composer-quality-patches

This extension adds one command: `bin/magento elgentos:quality-patches:convert`

It generates a `composer.quality-patches.json` file to use with the `vaimo/composer-patches` package. It will also add that file to `composer.json` when it hasn't been set yet.

This depends on `magento/quality-patches` and `vaimo/composer-packages`.
