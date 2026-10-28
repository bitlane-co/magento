<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Model\Screen;

// phpcs:disable Generic.Files.LineLength -- each text is one literal, which Magento's phrase collector reads.

/**
 * The Bitlane screen's texts (admin/src, t()), translated by Magento in the
 * admin's interface locale and sent with the page: so they're in the
 * extension's i18n/*.csv and in Stores › Translations like any other module's,
 * which Magento finds by these literal __() calls. A text the app adds goes
 * here too, and in i18n/it_IT.csv; %name% placeholders are left for the app
 * to fill.
 */
class Texts
{
    /**
     * Each text by its English.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $texts = [
            'Bitlane' => __('Bitlane'),
            'Open in Bitlane' => __('Open in Bitlane'),
            'Save' => __('Save'),
            'Save and set up' => __('Save and set up'),
            'Overview' => __('Overview'),
            'Search' => __('Search'),
            'Connection' => __('Connection'),
            'Loading…' => __('Loading…'),
            'Bitlane could not be reached. Try again in a moment.'
                => __('Bitlane could not be reached. Try again in a moment.'),

            // Not connected, a project, a store.
            'Bitlane ended this store’s connection, so its search stopped updating. Connect again to resume.'
                => __('Bitlane ended this store’s connection, so its search stopped updating. Connect again to resume.'),
            'Connect your store to Bitlane' => __('Connect your store to Bitlane'),
            'You’ll sign in to Bitlane (or create an account) and allow this store to use it. Bitlane then sets up:'
                => __('You’ll sign in to Bitlane (or create an account) and allow this store to use it. Bitlane then sets up:'),
            'an engine, or one your project already has' => __('an engine, or one your project already has'),
            'indexes for your catalogue in %languages%' => __('indexes for your catalogue in %languages%'),
            'a search widget for your storefront' => __('a search widget for your storefront'),
            'keys that only reach this store’s indexes' => __('keys that only reach this store’s indexes'),
            'Connect to Bitlane' => __('Connect to Bitlane'),
            'Choose a project' => __('Choose a project'),
            'Bitlane project for this store' => __('Bitlane project for this store'),
            'Continue' => __('Continue'),
            'Choose a store' => __('Choose a store'),
            'Each store is connected and set up on its own: pick the one to work on.'
                => __('Each store is connected and set up on its own: pick the one to work on.'),
            'All stores' => __('All stores'),
            'Connected' => __('Connected'),
            'Not connected' => __('Not connected'),

            // Setting up, the setup form.
            'Setting up' => __('Setting up'),
            'Bitlane is creating the engine, indexes, keys and search widget. A new engine takes a minute or two.'
                => __('Bitlane is creating the engine, indexes, keys and search widget. A new engine takes a minute or two.'),
            'Bitlane could not set this store up.' => __('Bitlane could not set this store up.'),
            'Engine' => __('Engine'),
            'A new engine, or one your Bitlane project already has.'
                => __('A new engine, or one your Bitlane project already has.'),
            'Saving moves the store to this engine: its indexes are made there and filled again, and the current engine’s are deleted. Search has fewer results until the sync is done.'
                => __('Saving moves the store to this engine: its indexes are made there and filled again, and the current engine’s are deleted. Search has fewer results until the sync is done.'),
            'A new engine' => __('A new engine'),
            'Content to search' => __('Content to search'),
            'Each kind gets its own index, kept up to date. Shoppers find those in the search widget in one list of results; the others are for your own use.'
                => __('Each kind gets its own index, kept up to date. Shoppers find those in the search widget in one list of results; the others are for your own use.'),
            '%label% (%count%)' => __('%label% (%count%)'),
            'In the search widget' => __('In the search widget'),
            'Store views' => __('Store views'),
            'Each store view gets its own indexes, in its language and currency, and the widget searches the one of the page. A store view you add later is added on its own.'
                => __('Each store view gets its own indexes, in its language and currency, and the widget searches the one of the page. A store view you add later is added on its own.'),
            'Filters' => __('Filters'),
            'What shoppers can narrow the results by, in the order they show.'
                => __('What shoppers can narrow the results by, in the order they show.'),
            'Sorting' => __('Sorting'),
            'The choices shoppers can sort the results by, in the order they show. Change them later in Bitlane’s widget designer.'
                => __('The choices shoppers can sort the results by, in the order they show. Change them later in Bitlane’s widget designer.'),
            'Drag to change the order' => __('Drag to change the order'),
            'Configurable products' => __('Configurable products'),
            'One result per variant (recommended)' => __('One result per variant (recommended)'),
            'Color, size and stock filter exactly: “red, XL, in stock” finds only products with a red XL in stock. Each variant counts as a record in your plan.'
                => __('Color, size and stock filter exactly: “red, XL, in stock” finds only products with a red XL in stock. Each variant counts as a record in your plan.'),
            'One result per product' => __('One result per product'),
            'Fewer records, but filters combine variants: a red S and a blue XL match “red, XL”, and stock is the product’s.'
                => __('Fewer records, but filters combine variants: a red S and a blue XL match “red, XL”, and stock is the product’s.'),
            'Brand color' => __('Brand color'),
            'The search widget’s buttons and highlights. Bitlane uses it for a new widget; after that, change the colors in Bitlane.'
                => __('The search widget’s buttons and highlights. Bitlane uses it for a new widget; after that, change the colors in Bitlane.'),
            'No color' => __('No color'),
            'Logo' => __('Logo'),
            'Shown at the top of the search widget’s filters. Bitlane uses it for a widget without a logo; after that, change it in Bitlane.'
                => __('Shown at the top of the search widget’s filters. Bitlane uses it for a widget without a logo; after that, change it in Bitlane.'),
            'Logo address' => __('Logo address'),
            'Use the store’s logo' => __('Use the store’s logo'),
            'Remove' => __('Remove'),
            'Search box' => __('Search box'),
            'CSS selectors for your theme’s search field and buttons, separated by commas: clicking one opens Bitlane’s search.'
                => __('CSS selectors for your theme’s search field and buttons, separated by commas: clicking one opens Bitlane’s search.'),

            // The set-up store.
            'Search widget' => __('Search widget'),
            'The widget opens from your theme’s search box. Its look and texts are set in Bitlane.'
                => __('The widget opens from your theme’s search box. Its look and texts are set in Bitlane.'),
            '%content% in %languages%' => __('%content% in %languages%'),
            'Open' => __('Open'),
            'Bitlane %version% is out: run composer update bitlane/magento, then bin/magento setup:upgrade. Your connection, settings and sync stay.'
                => __('Bitlane %version% is out: run composer update bitlane/magento, then bin/magento setup:upgrade. Your connection, settings and sync stay.'),
            'Engines' => __('Engines'),
            'Where your catalogue is searched: an index per kind of content and store view.'
                => __('Where your catalogue is searched: an index per kind of content and store view.'),
            'Open engine' => __('Open engine'),
            'No indexes yet.' => __('No indexes yet.'),
            // The sync.
            'Bitlane’s indexers are set to Update on Save, which misses changes: they need Update by Schedule.'
                => __('Bitlane’s indexers are set to Update on Save, which misses changes: they need Update by Schedule.'),
            'Switch them back' => __('Switch them back'),
            'Content' => __('Content'),
            'Each kind of content has its own index in each store view, kept up to date as it changes.'
                => __('Each kind of content has its own index in each store view, kept up to date as it changes.'),
            'Last error: %message% (%date%)' => __('Last error: %message% (%date%)'),
            'Syncing: %done% of %total%' => __('Syncing: %done% of %total%'),
            'Synced %date%' => __('Synced %date%'),
            'Not synced yet' => __('Not synced yet'),
            'Not in the search widget' => __('Not in the search widget'),
            'Resync' => __('Resync'),
            'Resync all' => __('Resync all'),
            'Changes waiting to sync: %count%' => __('Changes waiting to sync: %count%'),
            'Sync %count% changed items: %source%' => __('Sync %count% changed items: %source%'),
            'Full sync: %source%' => __('Full sync: %source%'),
            'Find the products of a changed category (#%id%)' => __('Find the products of a changed category (#%id%)'),
            'Find the products of a changed option (#%id%)' => __('Find the products of a changed option (#%id%)'),
            'Check %source% in %language%' => __('Check %source% in %language%'),
            'Daily check' => __('Daily check'),
            'Send the store’s address and name to Bitlane' => __('Send the store’s address and name to Bitlane'),
            'Refresh the storefront’s widget' => __('Refresh the storefront’s widget'),
            'Send order #%id%’s purchase' => __('Send order #%id%’s purchase'),
            'Send credit memo #%id%’s refund' => __('Send credit memo #%id%’s refund'),
            'Send additions to the cart' => __('Send additions to the cart'),

            // The storefront.
            'No active widget yet: the search shows on your pages once one is active in Bitlane.'
                => __('No active widget yet: the search shows on your pages once one is active in Bitlane.'),
            'Show the search on the storefront' => __('Show the search on the storefront'),
            'Every page but the checkout. With cookie restriction mode on (Stores › Configuration › General › Web), visits are counted once the shopper allows cookies.'
                => __('Every page but the checkout. With cookie restriction mode on (Stores › Configuration › General › Web), visits are counted once the shopper allows cookies.'),

            // The Jobs tab.
            'Jobs' => __('Jobs'),
            'Background sync' => __('Background sync'),
            'Changes reach Bitlane through Magento’s cron, in a group of its own that runs every minute. The server’s crontab needs Magento’s line:'
                => __('Changes reach Bitlane through Magento’s cron, in a group of its own that runs every minute. The server’s crontab needs Magento’s line:'),
            'Changes have waited since %date%: check that Magento’s cron runs.'
                => __('Changes have waited since %date%: check that Magento’s cron runs.'),
            'Magento’s cron hasn’t run the sync yet: changes wait until it does. While this page is open, it runs them.'
                => __('Magento’s cron hasn’t run the sync yet: changes wait until it does. While this page is open, it runs them.'),
            'Or keep this command running with a process manager such as Supervisor:'
                => __('Or keep this command running with a process manager such as Supervisor:'),
            'Last run: %date%' => __('Last run: %date%'),
            'It hasn’t run yet.' => __('It hasn’t run yet.'),
            'What the sync has to do: changes, full syncs and the daily check. Failed jobs stay a week.'
                => __('What the sync has to do: changes, full syncs and the daily check. Failed jobs stay a week.'),
            'Run now' => __('Run now'),
            'Waiting: %waiting% · Failed: %failed%' => __('Waiting: %waiting% · Failed: %failed%'),
            'Nothing to do: the sync is up to date.' => __('Nothing to do: the sync is up to date.'),
            'Error: %message%' => __('Error: %message%'),
            'Retry' => __('Retry'),
            'Delete' => __('Delete'),
            'Failed %date%' => __('Failed %date%'),
            'Attempt %count%' => __('Attempt %count%'),
            'Waiting' => __('Waiting'),
            'Scheduled: %date%' => __('Scheduled: %date%'),

            '%name% (%url%) is connected to Bitlane.' => __('%name% (%url%) is connected to Bitlane.'),
            'Disconnect this store from Bitlane? Its search stops updating; the engine and indexes stay in Bitlane.'
                => __('Disconnect this store from Bitlane? Its search stops updating; the engine and indexes stay in Bitlane.'),
            'Disconnect' => __('Disconnect'),

            // Engines' and widgets' statuses.
            'Active' => __('Active'),
            'Draft' => __('Draft'),
            'Running' => __('Running'),
            'Starting' => __('Starting'),
            'Updating' => __('Updating'),
            'Moving' => __('Moving'),
            'Failed' => __('Failed'),
            'Deleting' => __('Deleting'),
            'Stopped' => __('Stopped'),
        ];

        return array_map('strval', $texts);
    }
}
