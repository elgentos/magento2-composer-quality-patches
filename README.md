# elgentos/magento2-composer-quality-patches

This extension adds one command: `bin/magento elgentos:quality-patches:convert`

It generates a `composer.quality-patches.json` file to use with the `vaimo/composer-patches` package. It will also add that file to `composer.json` when it hasn't been set yet, and add a post-update-cmd hook to automatically update the patches file.

This depends on `magento/quality-patches` and `vaimo/composer-patches`.

Some patches will give a "Hmm...  Ignoring the trailing garbage." warnings, causing the patch to fail. There are two ways to handle this;

1. Add this to your `composer.json` to let patches fail without stopping the patching:

```json
{
  ...
    "extra": {
        "patcher": {
            "graceful": true
        }
    }
  ...
}
```

2. Add this to your post-install-cmd to fix the double new lines and run the patcher again:

```json
{
  ...
    "scripts": {
        "post-install-cmd": [
            "# Remove double new lines from patches to make vaimo/composer-patches process them correctly",
            "find vendor/magento/quality-patches -type f -name '*.patch' -exec    sed --in-place -e :a -e '/^\\n*$/{$d;N;};/\\n$/ba' {} \\;",
            "# Now run patch:apply again to apply the patches and use --no-scripts to avoid an infinite loop",
            "composer2 patch:apply --no-scripts"
        ]
    }
  ...
}
```

## Install & Usage
```bash
composer require elgentos/magento2-composer-quality-patches 
bin/magento s:up
bin/magento elgentos:quality-patches:convert
composer patch:apply
```

## Alternative method of automatically applying patches

1. `composer require magento/quality-patches`
2. Add this to your `composer.json`;

```json
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
