# elgentos/magento2-composer-quality-patches

## Work in progress!

This extension is in a broken/outdated state. I'm planning to pick it up once I have the energy/time/need :-)

For now, this is how we "solved" automatically applying eligible patches;

1. `composer require magento/quality-patches`
2. Add this to your `composer.json`;

```
{
    ...
    "scripts": {
        "post-install-cmd": [
            "./vendor/bin/magento-patches status | grep 'Not applied' | cut -d ' ' -f2 | xargs ./vendor/bin/magento-patches apply"
        ]
    }
    ...
}
```

## Original readme

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
