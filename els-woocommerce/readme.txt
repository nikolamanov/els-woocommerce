=== ELS for WooCommerce ===
Contributors: nikolamanov
Tags: woocommerce, shipping, els, ecologistics, macedonia
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later

Configurable WooCommerce integration for creating ELS postal shipments.

== Description ==

The plugin sends eligible WooCommerce orders to the ELS bulk order API. API credentials, endpoints, list type, company/sender details, automatic statuses, COD rules, delimiter, weight fallback and every CSV column are configured under WooCommerce > ELS Integration.

No client ID, secret, username, password or company-specific value is built into the plugin.

Features:

* OAuth-style password login with cached access token.
* Configurable `/users/login` and `/orders/bulk` endpoints.
* Automatic asynchronous sending for selected WooCommerce order statuses.
* Manual order action and bulk queue action.
* Direct “Create shipment in ELS” button inside an existing order.
* Searchable official ELS place list at classic checkout and on existing orders.
* Checkout Block suggestions and safe background Cyrillic/Latin place normalization.
* Latin-script search inside the SelectWoo place dropdown.
* Configurable synchronization of the ELS tracking number to an existing WooCommerce order meta field.
* Legacy `get_post_meta()` compatibility mirror and backfill for HPOS orders.
* Built-in Tracking Number and CFMA Number admin fields when no existing snippet provides them.
* Customer tracking link in the Completed order email and My Account order details.
* Place autocomplete is limited to checkout addresses in North Macedonia (`MK`).
* Clean custom checkout autocomplete with one canonical result per place and background Latin search.
* Default list type and CSV mapping for the `ЛИСТА ПАКЕТ-ПИ` template.
* Choose whether the sender or customer pays ELS shipping under Shipment import.
* Duplicate prevention after an ELS serial/tracking number is saved.
* ELS tracking, label and delivery-note links on the order.
* HPOS compatibility.
* Flexible `ELS header=source` mapping, including order meta and static values.
* Optional WooCommerce technical logging without credentials or CSV contents.
* Automatic order status sync from ELS every 30 minutes (WP-Cron): picked up / in delivery, delivered and returned shipments move the order to configurable statuses, never backwards.
* “Refresh ELS shipment status” order action and “Sync statuses now” button.

== Installation ==

Request API access and an assigned import template directly from ELS at info@els.mk or iskra@els.mk. Ask for the exact list type name and its CSV example. Request `ЛИСТА ПАКЕТ-ПИ` if the sender pays ELS shipping, `ЛИСТА ПАКЕТ-ПП` if the recipient pays ELS shipping, or both if you use both arrangements.

Get the latest installable ZIP at https://github.com/nikolamanov/els-woocommerce/releases/latest/download/els-woocommerce.zip

1. Upload the `els-woocommerce` directory to `/wp-content/plugins/`, or upload its ZIP in Plugins > Add New.
2. Activate “ELS for WooCommerce”.
3. Open WooCommerce > ELS Integration.
4. Enter the ELS API account data and the exact list type supplied by ELS.
5. Choose who pays ELS shipping under Shipment import. `ЛИСТА ПАКЕТ-ПП` uses its built-in CSV format with the customer-paid delimiter set to “Automatic” and the customer-paid mapping left blank. For a custom template, match the exported header names, column order and delimiter.
6. Save and run “Save first, then test connection”.
7. Enable the integration after the test succeeds.

For help installing or configuring the plugin, call 074 222 074 or email info@nikolamanov.com.

== Column mapping ==

Each line uses `Exact ELS header=source`. The line order is the CSV order.

Example:

`Име на примач=recipient_name`
`Адреса на примач=recipient_address`
`Место на примач=recipient_place`
`Телефон на примач=recipient_phone`
`Откуп=cod_amount`
`Тежина=weight`
`Забелешка=note`
`Број на Испратница=reference_number`

Use `meta:your_order_meta_key` for custom order metadata and `static:any text` for a fixed value required by the selected ELS list type.

== Important ==

ELS templates can have different mandatory columns. Always match the exported template from the ELS customer portal. Test with a non-production order before enabling automatic shipment creation.

The current public Swagger lists `POST /users/login` and `POST /orders/bulk`. Both paths remain configurable in case ELS provides different endpoints for an account.

== Changelog ==

= 1.6.2 =
* Open ELS delivery notes and labels from the WooCommerce order using the configured ELS API login. This also works with document links saved on existing orders.

= 1.6.1 =
* Use the ELS export's column order and tab delimiter for customer-paid shipments with `ЛИСТА ПАКЕТ-ПП`. Other customer-paid templates can have their own CSV settings.
* Creating or retrying a shipment now returns to the same order in WooCommerce, including when ELS reports an error.

= 1.6.0 =
* Choose who pays ELS shipping. Customer-paid shipments use a separate ELS list type and exclude WooCommerce shipping and its tax from the exported COD amount.

= 1.5.1 =
* Fix: deleting the plugin no longer wipes the ELS settings (new opt-in "Delete settings on uninstall"); replacing the plugin by delete + upload kept resetting the list type and other settings.
* ELS shipment errors now show which list type was sent, to make a template mismatch obvious.

= 1.5.0 =
* New: automatic order status synchronisation from ELS (`POST /orders/status`), with configurable target statuses and optional status-code map.

= 1.4.1 =
* Previous release.
