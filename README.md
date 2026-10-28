# Bitlane Search for Magento

Fast, typo-tolerant search for your Magento store, from [Bitlane](https://bitlane.co). Click **Connect**, approve the store in Bitlane, and Bitlane creates the search engine, an index for each kind of content and store view, and the search widget, then keeps them in sync as your catalogue changes.

- Products and their variants, categories and CMS pages, in each store view's language and currency.
- Every store of the install, each set up on its own under one connection.
- The search widget on your storefront, opened from your theme's search box (Luma and Hyvä).
- Additions to the cart, paid orders and credit memos reported for Bitlane's conversion reports, following Magento's cookie restriction mode.

## Requirements

- Magento Open Source, Adobe Commerce or Mage-OS, 2.4.7 to 2.4.9 (not Adobe Commerce as a Cloud Service)
- PHP 8.2 or later
- Command-line access to the server, and Magento's cron running, which runs the sync

## Install

With Composer, in your Magento root:

```bash
composer require bitlane/magento
bin/magento setup:upgrade
```

In production mode, then:

```bash
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
```

Then open **Bitlane** in the admin menu, click **Connect**, sign in to Bitlane (or create an account), approve the store, and choose what to search.

## Update

`composer update bitlane/magento`, then `bin/magento setup:upgrade` (and the production-mode commands above). The Bitlane screen tells you when a new version is out. Your connection, settings and sync stay.

## Help

Questions and bugs: open an issue in [this repository](https://github.com/bitlane-co/magento/issues), or write to us from [bitlane.co](https://bitlane.co). This repository holds each release as it ships; the extension is developed elsewhere, so pull requests can't be merged here: describe the change in an issue instead.

## License

Commercial (NMC): see [LICENSE.md](LICENSE.md).
