# elgentos/magento2-composer-quality-patches

This extension adds one command: `bin/magento elgentos:quality-patches:convert`

It generates a `composer.quality-patches.json` file to use with the `vaimo/composer-patches` package. It will also add that file to `composer.json` when it hasn't been set yet, and add a post-update-cmd hook to automatically update the patches file.

This depends on `magento/quality-patches` and `vaimo/composer-patches`.

## Install & Usage
```
composer require elgentos/magento2-composer-quality-patches 
bin/magento s:up
bin/magento elgentos:quality-patches:convert
composer patch:apply
```
