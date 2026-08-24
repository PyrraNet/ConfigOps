<?php
/**
 * Capture adapter for the WooCommerce core settings screens.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class WooCommerceAdapter extends AbstractOptionAdapter implements ChangeAwareAdapter, DatabaseWriteAwareAdapter
{
	/** @var array<string, true> */
	private array $wholeOptionFields = array();

	/** @var array<string, true> */
	private array $nestedOptionFields = array();

	/** @var list<string> */
	private const RUNTIME_OPTIONS = array(
		'woocommerce_admin_install_timestamp',
		'woocommerce_admin_version',
		'woocommerce_db_version',
		'woocommerce_flush_rewrite_rules',
		'woocommerce_installed',
		'woocommerce_onboarding_profile',
		'woocommerce_queue_flush_rewrite_rules',
		'woocommerce_share_key',
		'woocommerce_task_list_complete',
		'woocommerce_version',
	);

	/** @var list<string> */
	private const EMAIL_IDS = array(
		'cancelled_order',
		'customer_cancelled_order',
		'customer_completed_order',
		'customer_failed_order',
		'customer_invoice',
		'customer_new_account',
		'customer_note',
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_refunded_order',
		'customer_reset_password',
		'failed_order',
		'new_order',
	);

	public function __construct()
	{
		$this->defineGeneralSettings();
		$this->defineProductSettings();
		$this->defineAccountAndPrivacySettings();
		$this->defineShippingAndTaxSettings();
		$this->defineAdvancedSettings();
		$this->defineFeatureSettings();
		$this->definePointOfSaleSettings();
		$this->defineEmailSettings();
		$this->defineGatewaySettings();
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'woocommerce',
			'WooCommerce',
			'woocommerce/woocommerce.php',
			'>=10.3 <10.4 || >=10.7 <10.8 || >=10.9 <11.1',
			1,
			array(
				array('id' => 'capture', 'label' => 'Record Options API writes', 'level' => 'full', 'note' => 'The settings APIs of the WordPress.org-visible 10.3, 10.7, 10.9, and 11.0 lines are audited separately for unknown fields.'),
				array('id' => 'explain', 'label' => 'Map settings fields', 'level' => 'full', 'note' => 'Store, catalog, inventory, accounts, privacy, performance, feature, Point of Sale, REST API, email, endpoint, and bundled offline-payment settings have explicit meanings.'),
				array('id' => 'secrets', 'label' => 'Redact protected values', 'level' => 'full', 'note' => 'The generated share key, BACS bank-account records, and credential-shaped nested values are removed before storage.'),
				array('id' => 'noise', 'label' => 'Classify runtime values', 'level' => 'partial', 'note' => 'Known version, inbox, scheduler, onboarding, task-list, empty-selector, note, and rewrite state stays technical. Unknown custom-table writes remain visible.'),
				array('id' => 'restore', 'label' => 'Conflict-checked undo', 'level' => 'partial', 'note' => 'Mapped Options API settings can be undone after a current-value check. Referenced pages must still exist; order-storage and content-schema switches are refused.'),
				array('id' => 'apply', 'label' => 'Cross-site apply', 'level' => 'planned', 'note' => 'Store addresses, currencies, local page IDs, email recipients, tax tables, shipping zones, and payment credentials need an explicit destination contract.'),
			),
			array(
				'Store location, selling and shipping countries, taxes, coupons, currency, and price display',
				'Shop behavior, measurements, reviews, inventory, stock notices, and downloadable-product policy',
				'Checkout, registration, privacy, retention, shipping display, tax display, pages, and account endpoints',
				'Performance controls, REST caching, stable and experimental feature flags, and Point of Sale receipt details',
				'Email sender and design plus the stable core order and customer notification families',
				'Bundled bank transfer, cheque, and cash-on-delivery presentation settings',
			),
			array(
				'Orders, products, customers, coupons, and other store content are not configuration and are excluded.',
				'Tax-rate tables, shipping zones and methods, webhooks, API keys, Action Scheduler jobs, and analytics tables are not restored.',
				'HPOS datastore migration, compatibility synchronization, and Cost of Goods content switches are explained but not undone.',
				'Extension-owned payment gateways and settings are not claimed by the WooCommerce core adapter.',
				'BACS bank-account values are redacted and cannot be restored from ConfigOps evidence.',
			),
			'https://github.com/woocommerce/woocommerce'
		);
	}

	public function ownsOption(string $optionName): bool
	{
		return isset($this->wholeOptionFields[$optionName])
			|| isset($this->nestedOptionFields[$optionName])
			|| in_array($optionName, self::RUNTIME_OPTIONS, true)
			|| str_starts_with($optionName, 'wc_remote_inbox_notifications_')
			|| str_starts_with($optionName, 'woocommerce_admin_scheduler_')
			|| str_starts_with($optionName, 'woocommerce_onboarding_')
			|| str_starts_with($optionName, 'woocommerce_task_list_');
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		if (
			in_array($optionName, self::RUNTIME_OPTIONS, true)
			|| str_starts_with($optionName, 'wc_remote_inbox_notifications_')
			|| str_starts_with($optionName, 'woocommerce_admin_scheduler_')
			|| str_starts_with($optionName, 'woocommerce_onboarding_')
			|| str_starts_with($optionName, 'woocommerce_task_list_')
		) {
			return new AdapterAnalysis('derived', 'WooCommerce generated this version, inbox, scheduler, onboarding, task-list, or rewrite state.', false);
		}
		if ('woocommerce_bacs_accounts' === $optionName) {
			return new AdapterAnalysis('secret', 'WooCommerce stores bank-account details in this option. ConfigOps removes their values before persistence.', false);
		}
		if (! $this->ownsOption($optionName)) {
			return new AdapterAnalysis('unknown', 'This option is outside the tested WooCommerce core settings contract.', false);
		}

		return $this->analyzeFields($optionName, $changes);
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if (isset($this->wholeOptionFields[$optionName]) || 'woocommerce_bacs_accounts' === $optionName) {
			return parent::field($optionName, '/');
		}
		if (isset($this->nestedOptionFields[$optionName])) {
			return parent::field($optionName, $jsonPointer);
		}

		return null;
	}

	public function fieldForChange(
		string $optionName,
		string $jsonPointer,
		array $change,
		array $changes
	): ?FieldDefinition {
		unset($changes);
		if (
			'/' === $jsonPointer
			&& in_array(
				$optionName,
				array(
					'woocommerce_all_except_countries',
					'woocommerce_specific_allowed_countries',
					'woocommerce_specific_ship_to_countries',
				),
				true
			)
			&& array() === ($change['after'] ?? null)
			&& in_array($change['before'] ?? null, array(null, '', false, array()), true)
		) {
			return new FieldDefinition(
				'Empty country-list default',
				'Plugin housekeeping',
				'runtime',
				'WooCommerce initialized an unused country selector with an empty list while saving another setting.'
			);
		}

		return $this->field($optionName, $jsonPointer);
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		if (in_array($optionName, array('woocommerce_bacs_accounts', 'woocommerce_share_key'), true)) {
			return true;
		}

		return isset($this->nestedOptionFields[$optionName]) && $this->pathMatchesSecret($path);
	}

	public function isKnownNonConfigurationWrite(string $table, array $source): bool
	{
		return 'woocommerce' === $source['component']
			&& (
				str_contains($table, 'actionscheduler_')
				|| 1 === preg_match('/(?:^|_)wc_admin_note(?:s|_actions)$/', $table)
			);
	}

	private function defineGeneralSettings(): void
	{
		$this->defineOption('woocommerce_store_address', 'Store address', 'Store location', 'environment', 'The street address used for tax, shipping, and customer-facing store details.');
		$this->defineOption('woocommerce_store_address_2', 'Store address line 2', 'Store location', 'environment', 'The additional street-address line for this store.');
		$this->defineOption('woocommerce_store_city', 'Store city', 'Store location', 'environment', 'The city used for this store’s location.');
		$this->defineOption('woocommerce_default_country', 'Store country and region', 'Store location', 'environment', 'The country and state or region used as this store’s base.');
		$this->defineOption('woocommerce_store_postcode', 'Store postcode', 'Store location', 'environment', 'The postcode used for this store’s location.');
		$this->defineOption('woocommerce_address_autocomplete_enabled', 'Address autocomplete', 'Customer addresses', 'environment', 'Enables predictive address search when an installed provider can supply it.');
		$this->defineOption('woocommerce_address_autocomplete_provider', 'Address autocomplete provider', 'Customer addresses', 'environment', 'Selects the installed service that supplies predictive customer addresses.');
		$this->defineOption('woocommerce_allowed_countries', 'Selling locations', 'Selling and shipping', 'environment', 'Controls whether the store sells everywhere, nowhere, or only in selected countries.');
		$this->defineOption('woocommerce_all_except_countries', 'Excluded selling countries', 'Selling and shipping', 'environment', 'Countries excluded when the store sells to every other location.');
		$this->defineOption('woocommerce_specific_allowed_countries', 'Allowed selling countries', 'Selling and shipping', 'environment', 'The explicit country list when selling is limited by location.');
		$this->defineOption('woocommerce_ship_to_countries', 'Shipping locations', 'Selling and shipping', 'environment', 'Controls whether the store ships to all, selling, selected, or no countries.');
		$this->defineOption('woocommerce_specific_ship_to_countries', 'Allowed shipping countries', 'Selling and shipping', 'environment', 'The explicit country list when shipping is limited by location.');
		$this->defineOption('woocommerce_default_customer_address', 'Default customer location', 'Selling and shipping', 'environment', 'Chooses how WooCommerce estimates a visitor’s location before an address is entered.');
		$this->defineOption('woocommerce_calc_taxes', 'Enable taxes', 'Taxes and coupons', 'portable', 'Enables tax calculations during cart and checkout.');
		$this->defineOption('woocommerce_enable_coupons', 'Enable coupons', 'Taxes and coupons', 'portable', 'Allows customers to apply coupon codes.');
		$this->defineOption('woocommerce_calc_discounts_sequentially', 'Apply coupons sequentially', 'Taxes and coupons', 'portable', 'Applies each coupon to the price left after the previous coupon.');
		$this->defineOption('woocommerce_currency', 'Store currency', 'Currency and prices', 'environment', 'The currency used for catalog prices, checkout, and orders.');
		$this->defineOption('woocommerce_currency_pos', 'Currency symbol position', 'Currency and prices', 'portable', 'Places the currency symbol before or after the amount.');
		$this->defineOption('woocommerce_price_thousand_sep', 'Thousands separator', 'Currency and prices', 'portable', 'The character between groups of thousands in displayed prices.');
		$this->defineOption('woocommerce_price_decimal_sep', 'Decimal separator', 'Currency and prices', 'portable', 'The character before the fractional part of displayed prices.');
		$this->defineOption('woocommerce_price_num_decimals', 'Price decimals', 'Currency and prices', 'portable', 'The number of decimal places shown in store prices.');
	}

	private function defineProductSettings(): void
	{
		$this->defineOption('woocommerce_shop_page_id', 'Shop page', 'Catalog pages', 'reference', 'The local page used as the main product archive.', 'content');
		$this->defineOption('woocommerce_cart_redirect_after_add', 'Redirect to cart after adding', 'Catalog behavior', 'portable', 'Sends customers to the cart after they add a product.');
		$this->defineOption('woocommerce_enable_ajax_add_to_cart', 'AJAX add to cart', 'Catalog behavior', 'portable', 'Adds products from catalog pages without a full page reload.');
		$this->defineOption('woocommerce_placeholder_image', 'Product placeholder image', 'Catalog images', 'environment', 'An attachment ID or image URL used when a product has no image.');
		$this->defineOption('woocommerce_product_match_featured_image_by_sku', 'Match product images by SKU', 'Catalog images', 'portable', 'Uses an uploaded image whose filename matches a product SKU as that product’s featured image.');
		$this->defineOption('woocommerce_weight_unit', 'Weight unit', 'Measurements', 'portable', 'The unit shown for product weights.');
		$this->defineOption('woocommerce_dimension_unit', 'Dimension unit', 'Measurements', 'portable', 'The unit shown for product dimensions.');
		$this->defineOption('woocommerce_enable_reviews', 'Product reviews', 'Reviews', 'portable', 'Allows customers to leave reviews on products.');
		$this->defineOption('woocommerce_review_rating_verification_label', 'Verified owner label', 'Reviews', 'portable', 'Marks reviews from customers who bought the product.');
		$this->defineOption('woocommerce_review_rating_verification_required', 'Reviews require purchase', 'Reviews', 'portable', 'Allows product reviews only from verified owners.');
		$this->defineOption('woocommerce_enable_review_rating', 'Star ratings', 'Reviews', 'portable', 'Enables star ratings on product reviews.');
		$this->defineOption('woocommerce_review_rating_required', 'Star rating required', 'Reviews', 'portable', 'Requires a star rating when a customer submits a review.');
		$this->defineOption('woocommerce_manage_stock', 'Stock management', 'Inventory', 'portable', 'Lets WooCommerce track product stock quantities.');
		$this->defineOption('woocommerce_hold_stock_minutes', 'Hold stock', 'Inventory', 'portable', 'Minutes unpaid orders reserve inventory before cancellation.');
		$this->defineOption('woocommerce_notify_low_stock', 'Low-stock notifications', 'Inventory', 'portable', 'Sends an email when product stock reaches the low-stock threshold.');
		$this->defineOption('woocommerce_notify_no_stock', 'Out-of-stock notifications', 'Inventory', 'portable', 'Sends an email when a product runs out of stock.');
		$this->defineOption('woocommerce_stock_email_recipient', 'Stock notification recipient', 'Inventory', 'environment', 'The address that receives low-stock and out-of-stock messages.');
		$this->defineOption('woocommerce_notify_backorder', 'Backorder notifications', 'Inventory', 'portable', 'Sends an email when an order places a product on backorder.');
		$this->defineOption('woocommerce_notify_low_stock_amount', 'Low-stock threshold', 'Inventory', 'portable', 'The stock quantity WooCommerce treats as low.');
		$this->defineOption('woocommerce_notify_no_stock_amount', 'Out-of-stock threshold', 'Inventory', 'portable', 'The stock quantity WooCommerce treats as unavailable.');
		$this->defineOption('woocommerce_hide_out_of_stock_items', 'Hide out-of-stock products', 'Inventory', 'portable', 'Removes unavailable products from the catalog.');
		$this->defineOption('woocommerce_stock_format', 'Stock quantity display', 'Inventory', 'portable', 'Controls when product pages show the remaining stock quantity.');
		$this->defineOption('woocommerce_file_download_method', 'Download delivery method', 'Downloadable products', 'environment', 'Chooses how this server delivers protected product files.');
		$this->defineOption('woocommerce_downloads_redirect_fallback_allowed', 'Download redirect fallback', 'Downloadable products', 'environment', 'Allows an unprotected redirect when the preferred server delivery method is unavailable.');
		$this->defineOption('woocommerce_downloads_require_login', 'Downloads require login', 'Downloadable products', 'portable', 'Requires customers to sign in before downloading purchased files.');
		$this->defineOption('woocommerce_downloads_grant_access_after_payment', 'Grant downloads after payment', 'Downloadable products', 'portable', 'Makes downloads available after payment instead of waiting for order completion.');
		$this->defineOption('woocommerce_downloads_deliver_inline', 'Open downloads in browser', 'Downloadable products', 'portable', 'Lets supported files open inline instead of forcing a download.');
		$this->defineOption('woocommerce_downloads_add_hash_to_filename', 'Append hash to download filenames', 'Downloadable products', 'portable', 'Adds a unique string to uploaded downloadable-product filenames.');
		$this->defineOption('woocommerce_downloads_count_partial', 'Count partial downloads', 'Downloadable products', 'portable', 'Counts interrupted or partial requests against a customer’s download limit.');
	}

	private function defineAccountAndPrivacySettings(): void
	{
		$this->defineOption('woocommerce_enable_guest_checkout', 'Guest checkout', 'Checkout accounts', 'portable', 'Allows checkout without creating an account.');
		$this->defineOption('woocommerce_enable_checkout_login_reminder', 'Checkout login reminder', 'Checkout accounts', 'portable', 'Invites returning customers to sign in during checkout.');
		$this->defineOption('woocommerce_enable_delayed_account_creation', 'Delayed account creation', 'Checkout accounts', 'portable', 'Creates an optional customer account after the order instead of during checkout.');
		$this->defineOption('woocommerce_enable_signup_and_login_from_checkout', 'Account creation at checkout', 'Checkout accounts', 'portable', 'Lets customers create an account while checking out.');
		$this->defineOption('woocommerce_enable_myaccount_registration', 'Account creation on My account', 'Checkout accounts', 'portable', 'Lets visitors register from the My account page.');
		$this->defineOption('woocommerce_registration_generate_password', 'Generate account password', 'Account creation', 'portable', 'Generates a password instead of asking the customer to choose one.');
		$this->defineOption('woocommerce_registration_generate_username', 'Generate account username', 'Account creation', 'portable', 'Generates a username from the customer’s email address.');
		$this->defineOption('woocommerce_erasure_request_removes_order_data', 'Erase order personal data', 'Privacy requests', 'portable', 'Removes personal data from orders when WordPress processes an erasure request.');
		$this->defineOption('woocommerce_erasure_request_removes_download_data', 'Revoke downloads on erasure', 'Privacy requests', 'portable', 'Removes download access when WordPress processes an erasure request.');
		$this->defineOption('woocommerce_allow_bulk_remove_personal_data', 'Bulk personal-data removal', 'Privacy requests', 'portable', 'Allows administrators to remove personal data from multiple orders.');
		$this->defineOption('woocommerce_registration_privacy_policy_text', 'Registration privacy notice', 'Privacy notices', 'portable', 'The privacy text shown on account registration.');
		$this->defineOption('woocommerce_checkout_privacy_policy_text', 'Checkout privacy notice', 'Privacy notices', 'portable', 'The privacy text shown during checkout.');
		$this->defineOption('woocommerce_delete_inactive_accounts', 'Inactive-account retention', 'Data retention', 'portable', 'How long WooCommerce keeps inactive customer accounts.');
		$this->defineOption('woocommerce_trash_pending_orders', 'Pending-order retention', 'Data retention', 'portable', 'How long WooCommerce keeps pending orders before moving them to Trash.');
		$this->defineOption('woocommerce_trash_failed_orders', 'Failed-order retention', 'Data retention', 'portable', 'How long WooCommerce keeps failed orders before moving them to Trash.');
		$this->defineOption('woocommerce_trash_cancelled_orders', 'Cancelled-order retention', 'Data retention', 'portable', 'How long WooCommerce keeps cancelled orders before moving them to Trash.');
		$this->defineOption('woocommerce_anonymize_refunded_orders', 'Refunded-order retention', 'Data retention', 'portable', 'How long WooCommerce keeps personal data in refunded orders.');
		$this->defineOption('woocommerce_anonymize_completed_orders', 'Completed-order retention', 'Data retention', 'portable', 'How long WooCommerce keeps personal data in completed orders.');
	}

	private function defineShippingAndTaxSettings(): void
	{
		$this->defineOption('woocommerce_enable_shipping_calc', 'Cart shipping calculator', 'Shipping display', 'portable', 'Shows the shipping calculator on the cart page.');
		$this->defineOption('woocommerce_shipping_cost_requires_address', 'Hide shipping until address', 'Shipping display', 'portable', 'Waits for a customer address before showing shipping costs.');
		$this->defineOption('woocommerce_shipping_hide_rates_when_free', 'Hide paid rates with free shipping', 'Shipping display', 'portable', 'Shows only free shipping when a free method is available.');
		$this->defineOption('woocommerce_ship_to_destination', 'Shipping destination default', 'Shipping display', 'portable', 'Chooses whether checkout defaults to the billing or shipping address.');
		$this->defineOption('woocommerce_shipping_debug_mode', 'Shipping debug mode', 'Shipping display', 'environment', 'Shows matched shipping zones and bypasses the shipping-rate cache for administrators.');
		$this->defineOption('woocommerce_prices_include_tax', 'Prices entered with tax', 'Tax calculation', 'portable', 'Defines whether catalog prices are stored including tax.');
		$this->defineOption('woocommerce_tax_based_on', 'Tax calculation address', 'Tax calculation', 'portable', 'Chooses the customer address WooCommerce uses to calculate tax.');
		$this->defineOption('woocommerce_shipping_tax_class', 'Shipping tax class', 'Tax calculation', 'portable', 'Selects the tax class applied to shipping charges.');
		$this->defineOption('woocommerce_tax_round_at_subtotal', 'Round tax at subtotal', 'Tax calculation', 'portable', 'Rounds tax after summing line items instead of on each line.');
		$this->defineOption('woocommerce_tax_classes', 'Additional tax classes', 'Tax calculation', 'portable', 'The named tax classes available beyond the standard rate.');
		$this->defineOption('woocommerce_tax_display_shop', 'Catalog tax display', 'Tax display', 'portable', 'Shows catalog prices including or excluding tax.');
		$this->defineOption('woocommerce_tax_display_cart', 'Cart tax display', 'Tax display', 'portable', 'Shows cart and checkout prices including or excluding tax.');
		$this->defineOption('woocommerce_price_display_suffix', 'Price display suffix', 'Tax display', 'portable', 'Text appended to displayed prices, including supported tax placeholders.');
		$this->defineOption('woocommerce_tax_total_display', 'Tax total display', 'Tax display', 'portable', 'Shows tax as one total or itemized by rate.');
	}

	private function defineAdvancedSettings(): void
	{
		$this->defineOption('woocommerce_cart_page_id', 'Cart page', 'Store pages', 'reference', 'The local page used for the cart.', 'content');
		$this->defineOption('woocommerce_checkout_page_id', 'Checkout page', 'Store pages', 'reference', 'The local page used for checkout.', 'content');
		$this->defineOption('woocommerce_myaccount_page_id', 'My account page', 'Store pages', 'reference', 'The local page used for customer accounts.', 'content');
		$this->defineOption('woocommerce_terms_page_id', 'Terms and conditions page', 'Store pages', 'reference', 'The local page shown as the store’s terms and conditions.', 'content');
		$this->defineOption('woocommerce_force_ssl_checkout', 'Force secure checkout', 'Checkout security', 'environment', 'Legacy setting that requires HTTPS on checkout pages.');
		$this->defineOption('woocommerce_unforce_ssl_checkout', 'Keep HTTP outside checkout', 'Checkout security', 'environment', 'Legacy setting that redirects non-checkout pages away from HTTPS.');
		$this->defineOption('woocommerce_checkout_pay_endpoint', 'Pay endpoint', 'Checkout endpoints', 'portable', 'The URL endpoint used to pay for an existing order.');
		$this->defineOption('woocommerce_checkout_order_received_endpoint', 'Order received endpoint', 'Checkout endpoints', 'portable', 'The URL endpoint used for the order confirmation screen.');
		$this->defineOption('woocommerce_myaccount_add_payment_method_endpoint', 'Add payment method endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to add a payment method.');
		$this->defineOption('woocommerce_myaccount_delete_payment_method_endpoint', 'Delete payment method endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to remove a payment method.');
		$this->defineOption('woocommerce_myaccount_set_default_payment_method_endpoint', 'Default payment method endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to choose a default payment method.');
		$this->defineOption('woocommerce_myaccount_orders_endpoint', 'Orders endpoint', 'Account endpoints', 'portable', 'The My account endpoint used for the order list.');
		$this->defineOption('woocommerce_myaccount_view_order_endpoint', 'View order endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to view an order.');
		$this->defineOption('woocommerce_myaccount_downloads_endpoint', 'Downloads endpoint', 'Account endpoints', 'portable', 'The My account endpoint used for purchased downloads.');
		$this->defineOption('woocommerce_myaccount_edit_account_endpoint', 'Edit account endpoint', 'Account endpoints', 'portable', 'The My account endpoint used for profile details.');
		$this->defineOption('woocommerce_myaccount_edit_address_endpoint', 'Edit address endpoint', 'Account endpoints', 'portable', 'The My account endpoint used for billing and shipping addresses.');
		$this->defineOption('woocommerce_myaccount_payment_methods_endpoint', 'Payment methods endpoint', 'Account endpoints', 'portable', 'The My account endpoint used for saved payment methods.');
		$this->defineOption('woocommerce_myaccount_lost_password_endpoint', 'Lost password endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to reset a password.');
		$this->defineOption('woocommerce_logout_endpoint', 'Logout endpoint', 'Account endpoints', 'portable', 'The My account endpoint used to sign out.');
		$this->defineOption('woocommerce_allow_tracking', 'Usage tracking', 'Data sharing', 'environment', 'Controls whether WooCommerce sends usage data from this store.');
		$this->defineOption('woocommerce_show_marketplace_suggestions', 'Marketplace suggestions', 'Administration', 'portable', 'Shows WooCommerce extension suggestions in the administration area.');
		$this->defineOption('woocommerce_rest_api_enable_backend_caching', 'REST API backend cache', 'REST API', 'environment', 'Caches eligible WooCommerce REST responses in the server’s persistent object cache.');
		$this->defineOption('woocommerce_rest_api_enable_cache_headers', 'REST API cache headers', 'REST API', 'environment', 'Adds cache validators and cache-control headers to eligible WooCommerce REST responses.');
	}

	private function defineFeatureSettings(): void
	{
		$this->defineOption('woocommerce_attribute_lookup_enabled', 'Product attribute lookup table', 'Catalog performance', 'environment', 'Uses WooCommerce’s generated attribute lookup table for catalog filtering.');
		$this->defineOption('woocommerce_attribute_lookup_direct_updates', 'Direct attribute lookup updates', 'Catalog performance', 'environment', 'Updates the product attribute lookup table during product changes instead of scheduling the work.');
		$this->defineOption('woocommerce_attribute_lookup_optimized_updates', 'Optimized attribute lookup updates', 'Catalog performance', 'environment', 'Uses faster lookup-table update queries that may be incompatible with extensions.');
		$this->defineOption('woocommerce_custom_orders_table_enabled', 'High-performance order storage', 'Order storage', 'unsupported', 'Switches the authoritative order datastore. ConfigOps records the setting but does not undo a storage migration.');
		$this->defineOption('woocommerce_custom_orders_table_data_sync_enabled', 'Order datastore compatibility sync', 'Order storage', 'unsupported', 'Synchronizes orders between HPOS tables and WordPress posts. ConfigOps does not undo datastore synchronization.');
		$this->defineOption('woocommerce_analytics_enabled', 'WooCommerce Analytics', 'Store features', 'portable', 'Collects store metrics locally and exposes the WooCommerce Analytics reports.');
		$this->defineOption('woocommerce_feature_rate_limit_checkout_enabled', 'Checkout rate limiting', 'Checkout security', 'environment', 'Limits order-submission requests to protect the checkout and Store API on this server.');
		$this->defineOption('woocommerce_feature_order_attribution_enabled', 'Order attribution', 'Store features', 'portable', 'Records the channels and campaigns that contributed to each order.');
		$this->defineOption('woocommerce_hpos_datastore_caching_enabled', 'HPOS order cache', 'Order performance', 'environment', 'Caches high-performance order-storage records when this site has a suitable object cache.');
		$this->defineOption('woocommerce_feature_remote_logging_enabled', 'Remote error logging', 'Diagnostics and privacy', 'environment', 'Allows WooCommerce to send error logs and diagnostic data when usage tracking is enabled.');
		$this->defineOption('woocommerce_feature_deferred_transactional_emails_enabled', 'Deferred transactional emails', 'Email delivery', 'environment', 'Queues transactional email through Action Scheduler instead of sending it in the current request.');
		$this->defineOption('woocommerce_feature_customer_review_request_enabled', 'Customer review requests', 'Customer email', 'environment', 'Sends a post-purchase email that invites customers to review products from a completed order.');
		$this->defineOption('woocommerce_feature_email_improvements_enabled', 'Modern transactional email design', 'Email design', 'portable', 'Uses WooCommerce’s modern layout for transactional email.');
		$this->defineOption('woocommerce_feature_blueprint_enabled', 'WooCommerce Blueprint', 'Import and export', 'portable', 'Enables WooCommerce’s settings import and export screen.');
		$this->defineOption('woocommerce_api_enabled', 'Legacy REST API', 'Legacy integrations', 'environment', 'Records whether the separate WooCommerce Legacy REST API integration is active on this site.');
		$this->defineOption('woocommerce_feature_site_visibility_badge_enabled', 'Site visibility badge', 'Administration', 'portable', 'Shows the store’s coming-soon or live status in the WordPress admin bar.');
		$this->defineOption('woocommerce_feature_point_of_sale_enabled', 'Point of Sale', 'Point of Sale', 'environment', 'Enables WooCommerce Point of Sale features in supported mobile apps.');
		$this->defineOption('woocommerce_feature_experimental-iapi-mini-cart_enabled', 'Interactivity API Mini-Cart', 'Cart features', 'portable', 'Uses the WordPress Interactivity API implementation of the Mini-Cart block.');
		$this->defineOption('woocommerce_feature_cost_of_goods_sold_enabled', 'Cost of goods sold', 'Product and order data', 'unsupported', 'Adds cost data to products and orders. ConfigOps records the toggle but does not undo changes to store content.');
		$this->defineOption('woocommerce_hpos_fts_index_enabled', 'HPOS full-text search indexes', 'Order performance', 'environment', 'Creates and uses full-text order indexes when high-performance order storage is active.');
		$this->defineOption('woocommerce_feature_abandoned_cart_recovery_enabled', 'Abandoned cart recovery', 'Customer email', 'environment', 'Sends reminder email to shoppers who leave checkout without placing an order.');
		$this->defineOption('woocommerce_feature_block_email_editor_enabled', 'Block email editor', 'Email design', 'portable', 'Enables block-based editing for WooCommerce transactional email templates.');
		$this->defineOption('woocommerce_feature_wc_visual_attribute_enabled', 'Product attribute color swatches', 'Catalog appearance', 'portable', 'Displays color swatches for configured product attribute values.');
		$this->defineOption('woocommerce_feature_mcp_integration_enabled', 'WooCommerce MCP integration', 'Agent access', 'environment', 'Exposes the experimental WooCommerce MCP integration and must be reviewed for each environment.');
		$this->defineOption('woocommerce_feature_destroy-empty-sessions_enabled', 'Clear empty customer sessions', 'Store performance', 'environment', 'Removes empty guest session cookies to improve page-cache reuse.');
		$this->defineOption('woocommerce_feature_rest_api_caching_enabled', 'REST API caching feature', 'REST API', 'environment', 'Enables WooCommerce’s experimental cache layer for eligible REST API responses.');
		$this->defineOption('woocommerce_cart_save_for_later_enabled', 'Save cart items for later', 'Cart features', 'portable', 'Lets shoppers move cart items to a list for a later purchase.');
		$this->defineOption('woocommerce_product_wishlist_enabled', 'Product wishlists', 'Catalog features', 'portable', 'Lets shoppers save products to a wishlist from supported product templates.');
		$this->defineOption('woocommerce_feature_product_instance_caching_enabled', 'Product object cache', 'Catalog performance', 'environment', 'Caches product objects during a request to avoid duplicate product loads.');
	}

	private function definePointOfSaleSettings(): void
	{
		$this->defineOption('woocommerce_pos_store_name', 'Point of Sale store name', 'Point of Sale receipts', 'environment', 'The physical store name printed on Point of Sale receipts.');
		$this->defineOption('woocommerce_pos_store_address', 'Point of Sale store address', 'Point of Sale receipts', 'environment', 'The physical store address printed on Point of Sale receipts.');
		$this->defineOption('woocommerce_pos_store_phone', 'Point of Sale phone number', 'Point of Sale receipts', 'environment', 'The store phone number printed on Point of Sale receipts.');
		$this->defineOption('woocommerce_pos_store_email', 'Point of Sale email', 'Point of Sale receipts', 'environment', 'The store contact address printed on Point of Sale receipts.');
		$this->defineOption('woocommerce_pos_refund_returns_policy', 'Point of Sale refund policy', 'Point of Sale receipts', 'portable', 'The refund and returns statement printed on Point of Sale receipts.');
	}

	private function defineEmailSettings(): void
	{
		$this->defineOption('woocommerce_email_from_name', 'Email sender name', 'Email sender', 'portable', 'The name shown as the sender of WooCommerce messages.');
		$this->defineOption('woocommerce_email_from_address', 'Email sender address', 'Email sender', 'environment', 'The address used to send WooCommerce messages from this store.');
		$this->defineOption('woocommerce_email_reply_to_enabled', 'Use a separate reply-to address', 'Email sender', 'portable', 'Allows WooCommerce messages to direct replies to a different address.');
		$this->defineOption('woocommerce_email_reply_to_name', 'Email reply-to name', 'Email sender', 'portable', 'The name shown when a recipient replies to a WooCommerce message.');
		$this->defineOption('woocommerce_email_reply_to_address', 'Email reply-to address', 'Email sender', 'environment', 'The store-specific address that receives replies to WooCommerce messages.');
		$this->defineOption('woocommerce_email_header_image', 'Email logo', 'Email design', 'environment', 'The local or remote image URL used as the email logo.');
		$this->defineOption('woocommerce_email_header_image_width', 'Email logo width', 'Email design', 'portable', 'The logo width used in WooCommerce email templates.');
		$this->defineOption('woocommerce_email_header_alignment', 'Email logo alignment', 'Email design', 'portable', 'Aligns the email logo to the left, center, or right.');
		$this->defineOption('woocommerce_email_font_family', 'Email font', 'Email design', 'portable', 'The font family used in WooCommerce email templates.');
		$this->defineOption('woocommerce_email_footer_text', 'Email footer text', 'Email design', 'portable', 'The text shown below WooCommerce email content.');
		$this->defineOption('woocommerce_email_base_color', 'Email accent color', 'Email design', 'portable', 'The main accent color used in WooCommerce emails.');
		$this->defineOption('woocommerce_email_background_color', 'Email background color', 'Email design', 'portable', 'The outer background color used in WooCommerce emails.');
		$this->defineOption('woocommerce_email_body_background_color', 'Email body color', 'Email design', 'portable', 'The content background color used in WooCommerce emails.');
		$this->defineOption('woocommerce_email_text_color', 'Email text color', 'Email design', 'portable', 'The main text color used in WooCommerce emails.');
		$this->defineOption('woocommerce_email_footer_text_color', 'Email footer color', 'Email design', 'portable', 'The footer text color used in WooCommerce emails.');
		$this->defineOption('woocommerce_email_auto_sync_with_theme', 'Sync email colors with theme', 'Email design', 'portable', 'Keeps WooCommerce email colors aligned with the active theme.');

		foreach (self::EMAIL_IDS as $emailId) {
			$optionName = 'woocommerce_' . $emailId . '_settings';
			$label = ucwords(str_replace('_', ' ', $emailId));
			$this->defineNestedFields(
				$optionName,
				array(
					'/enabled' => array($label . ' enabled', 'Email notifications', 'portable', 'Controls whether WooCommerce sends this notification.'),
					'/recipient' => array($label . ' recipients', 'Email notifications', 'environment', 'The store-specific addresses that receive this administrator notification.'),
					'/subject' => array($label . ' subject', 'Email notifications', 'portable', 'The subject template for this notification.'),
					'/heading' => array($label . ' heading', 'Email notifications', 'portable', 'The heading template inside this notification.'),
					'/additional_content' => array($label . ' additional content', 'Email notifications', 'portable', 'Text appended below the main notification content.'),
					'/email_type' => array($label . ' format', 'Email notifications', 'portable', 'Chooses plain text, HTML, or multipart delivery.'),
					'/cc' => array($label . ' Cc recipients', 'Email notifications', 'environment', 'Additional store-specific recipients copied on this notification.'),
					'/bcc' => array($label . ' Bcc recipients', 'Email notifications', 'environment', 'Additional store-specific recipients hidden on this notification.'),
					'/preheader' => array($label . ' preheader', 'Email notifications', 'portable', 'Preview text shown beside the subject in supported inboxes.'),
				)
			);
		}
	}

	private function defineGatewaySettings(): void
	{
		$this->defineNestedFields(
			'woocommerce_bacs_settings',
			$this->gatewayFields('Direct bank transfer')
		);
		$this->defineNestedFields(
			'woocommerce_cheque_settings',
			$this->gatewayFields('Cheque payments')
		);
		$this->defineNestedFields(
			'woocommerce_cod_settings',
			array_merge(
				$this->gatewayFields('Cash on delivery'),
				array(
					'/enable_for_methods' => array('Cash on delivery shipping methods', 'Offline payments', 'environment', 'Limits cash on delivery to selected local shipping methods.'),
					'/enable_for_virtual' => array('Cash on delivery for virtual orders', 'Offline payments', 'portable', 'Allows cash on delivery when an order contains only virtual products.'),
				)
			)
		);
		$this->nestedOptionFields['woocommerce_bacs_accounts'] = true;
		$this->defineFields(
			'woocommerce_bacs_accounts',
			array(
				'/' => array('Bank transfer accounts', 'Protected payment details', 'secret', 'Bank account values are removed before ConfigOps stores the capture.'),
			)
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private function gatewayFields(string $label): array
	{
		return array(
			'/enabled' => array($label . ' enabled', 'Offline payments', 'portable', 'Controls whether customers can select this bundled offline payment method.'),
			'/title' => array($label . ' title', 'Offline payments', 'portable', 'The payment-method name shown during checkout.'),
			'/description' => array($label . ' description', 'Offline payments', 'portable', 'The payment-method explanation shown during checkout.'),
			'/instructions' => array($label . ' instructions', 'Offline payments', 'portable', 'Instructions shown after the customer places an order.'),
		);
	}

	/**
	 * @param array<string, array{0: string, 1: string, 2: string, 3: string, 4?: string}> $fields
	 */
	private function defineNestedFields(string $optionName, array $fields): void
	{
		$this->nestedOptionFields[$optionName] = true;
		$this->defineFields($optionName, $fields);
	}

	private function defineOption(
		string $optionName,
		string $label,
		string $group,
		string $kind,
		string $explanation,
		?string $referenceType = null
	): void {
		$this->wholeOptionFields[$optionName] = true;
		$this->define($optionName, '/', $label, $group, $kind, $explanation, $referenceType);
	}
}
