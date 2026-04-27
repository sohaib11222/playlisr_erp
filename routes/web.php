<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

include_once('install_r.php');

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SellPosController;

Route::get('/ebay/search-product-price', [ProductController::class, 'searchEbayProductPrice']);
Route::get('/discogs/search-product-price', [ProductController::class, 'searchDiscogsProductPrice']);
Route::get('/discogs/search-product-price-2', [ProductController::class, 'searchDiscogsProductPrice2']);


Route::middleware(['setData'])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });


    Auth::routes();

    Route::get('/business/register', 'BusinessController@getRegister')->name('business.getRegister');
    Route::post('/business/register', 'BusinessController@postRegister')->name('business.postRegister');
    Route::post('/business/register/check-username', 'BusinessController@postCheckUsername')->name('business.postCheckUsername');
    Route::post('/business/register/check-email', 'BusinessController@postCheckEmail')->name('business.postCheckEmail');

    Route::get('/invoice/{token}', 'SellPosController@showInvoice')
        ->name('show_invoice');
    Route::get('/quote/{token}', 'SellPosController@showInvoice')
        ->name('show_quote');

    Route::get('/pay/{token}', 'SellPosController@invoicePayment')
        ->name('invoice_payment');
    Route::post('/confirm-payment/{id}', 'SellPosController@confirmPayment')
        ->name('confirm_payment');

    Route::get('/business/quickbooks/callback', 'QuickBooksController@callback')->name('business.quickbooks.callback');

    // Clover webhook — signature-verified in the controller. Must be outside
    // the auth group (Clover calls us) and outside CSRF (see VerifyCsrfToken::$except).
    Route::post('/webhooks/clover', 'CloverController@webhook')->name('clover.webhook');
});

//Routes for authenticated users only
Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone', 'AdminSidebarMenu', 'CheckUserLogin'])->group(function () {

    Route::get('testing-report', [ReportController::class, 'testingReport']);

    Route::get('/sign-in-as-user/{id}', 'ManageUserController@signInAsUser')->name('sign-in-as-user');

    Route::get('/home', 'HomeController@index')->name('home');
    Route::get('/home/get-totals', 'HomeController@getTotals');
    Route::get('/home/product-stock-alert', 'HomeController@getProductStockAlert');
    Route::get('/home/purchase-payment-dues', 'HomeController@getPurchasePaymentDues');
    Route::get('/home/sales-payment-dues', 'HomeController@getSalesPaymentDues');
    Route::post('/attach-medias-to-model', 'HomeController@attachMediasToGivenModel')->name('attach.medias.to.model');
    Route::get('/calendar', 'HomeController@getCalendar')->name('calendar');
    
    Route::post('/test-email', 'BusinessController@testEmailConfiguration');
    Route::post('/test-sms', 'BusinessController@testSmsConfiguration');
    Route::post('/business/test-streetpulse-connection', 'BusinessController@testStreetpulseConnection');
    Route::post('/business/sync-streetpulse', 'BusinessController@syncStreetpulse');
    Route::get('/business/quickbooks/connect', 'QuickBooksController@connect')->name('business.quickbooks.connect');
    Route::post('/business/quickbooks/disconnect', 'QuickBooksController@disconnect')->name('business.quickbooks.disconnect');
    Route::post('/business/quickbooks/test-connection', 'QuickBooksController@testConnection')->name('business.quickbooks.testConnection');
    Route::post('/business/quickbooks/sync-sale', 'QuickBooksController@syncSale')->name('business.quickbooks.syncSale');
    Route::get('/business/quickbooks/dashboard', 'QuickBooksController@dashboard')->name('business.quickbooks.dashboard');
    Route::post('/business/quickbooks/backfill', 'QuickBooksController@backfill')->name('business.quickbooks.backfill');
    
    // Clover Customer Import
    Route::post('/business/test-clover-connection', 'CloverController@testConnection');
    Route::get('/business/preview-clover-customers', 'CloverController@previewCustomers');
    Route::post('/business/import-clover-customers', 'CloverController@importCustomers');
    Route::post('/business/sync-clover-rewards', 'CloverController@syncRewards')->name('clover.sync-rewards');
    Route::post('/business/clover/sync-now', 'CloverController@syncNow')->name('clover.sync-now');
    Route::get('/business/clover/sync-status', 'CloverController@syncStatus')->name('clover.sync-status');
    Route::post('/reports/clover-eod/mark-reconciled', 'ReportController@cloverEodMarkReconciled')->name('reports.clover-eod.reconciled');
    Route::post('/reports/clover-eod/save-notes', 'ReportController@cloverEodSaveNotes')->name('reports.clover-eod.notes');
    Route::get('/clover/shift-summary', 'CloverController@shiftSummary')->name('clover.shift-summary');
    
    Route::get('/business/settings', 'BusinessController@getBusinessSettings')->name('business.getBusinessSettings');
    Route::post('/business/update', 'BusinessController@postBusinessSettings')->name('business.postBusinessSettings');
    Route::post('/business/update-artists', 'BusinessController@updateArtistNames')->name('business.updateArtistNames');
    Route::get('/user/profile', 'UserController@getProfile')->name('user.getProfile');
    Route::post('/user/update', 'UserController@updateProfile')->name('user.updateProfile');
    Route::post('/user/update-password', 'UserController@updatePassword')->name('user.updatePassword');

    Route::resource('brands', 'BrandController');
    
    //Route::resource('payment-account', 'PaymentAccountController');

    Route::resource('tax-rates', 'TaxRateController');

    Route::resource('units', 'UnitController');

    Route::resource('ledger-discount', 'LedgerDiscountController', ['only' => [
        'edit', 'destroy', 'store', 'update'
    ]]);

    Route::post('check-mobile', 'ContactController@checkMobile');
    Route::get('/get-contact-due/{contact_id}', 'ContactController@getContactDue');
    Route::get('/contacts/payments/{contact_id}', 'ContactController@getContactPayments');
    Route::get('/contacts/map', 'ContactController@contactMap');
    Route::get('/contacts/update-status/{id}', 'ContactController@updateStatus');
    Route::get('/contacts/stock-report/{supplier_id}', 'ContactController@getSupplierStockReport');
    Route::get('/contacts/ledger', 'ContactController@getLedger');
    Route::post('/contacts/send-ledger', 'ContactController@sendLedger');
    Route::get('/contacts/import', 'ContactController@getImportContacts')->name('contacts.import');
    Route::post('/contacts/import', 'ContactController@postImportContacts');
    Route::post('/contacts/check-contacts-id', 'ContactController@checkContactId');
    Route::get('/contacts/customers', 'ContactController@getCustomers');
    Route::post('/contacts/{id}/avatar', 'ContactController@updateAvatar');
    Route::post('/contacts/{id}/genres', 'ContactController@updateGenres');
    Route::post('/contacts/{id}/store-credit', 'ContactController@updateStoreCredit');
    Route::post('/contacts/{id}/adjust-credit', 'ContactController@adjustStoreCredit')->name('contacts.adjustCredit');
    Route::get('/contacts/campaigns', 'ContactCampaignController@index');
    Route::post('/contacts/campaigns/send', 'ContactCampaignController@send');
    Route::resource('contacts', 'ContactController');
    
    // Gift Cards
    Route::resource('gift-cards', 'GiftCardController');
    
    // Preorders
    Route::resource('preorders', 'PreorderController');
    Route::get('/preorders/customer/{contact_id}', 'PreorderController@getCustomerPreorders');
    Route::post('/preorders/{id}/fulfill', 'PreorderController@fulfill');

    // Customer Pickups
    Route::resource('customer-pickups', 'CustomerPickupController');
    Route::get('/customer-pickups/customer/{contact_id}', 'CustomerPickupController@getCustomerPickups');
    Route::post('/customer-pickups/{id}/mark-picked-up', 'CustomerPickupController@markPickedUp');

    // Loyalty Tiers
    Route::resource('loyalty-tiers', 'LoyaltyTierController');

    Route::get('taxonomies-ajax-index-page', 'TaxonomyController@getTaxonomyIndexPage');
    Route::resource('taxonomies', 'TaxonomyController');

    Route::resource('variation-templates', 'VariationTemplateController');

    Route::get('/products/download-excel', 'ProductController@downloadExcel');

    Route::get('/products/stock-history/{id}', 'ProductController@productStockHistory');
    Route::get('/delete-media/{media_id}', 'ProductController@deleteMedia');
    Route::post('/products/mass-deactivate', 'ProductController@massDeactivate');
    Route::get('/products/activate/{id}', 'ProductController@activate');
    Route::get('/products/view-product-group-price/{id}', 'ProductController@viewGroupPrice');
    Route::get('/products/add-selling-prices/{id}', 'ProductController@addSellingPrices');
    Route::post('/products/save-selling-prices', 'ProductController@saveSellingPrices');
    Route::post('/products/mass-delete', 'ProductController@massDestroy');
    Route::get('/products/view/{id}', 'ProductController@view');
    Route::get('/products/list', 'ProductController@getProducts');
    Route::get('/products/list-no-variation', 'ProductController@getProductsWithoutVariations');
    Route::post('/products/bulk-edit', 'ProductController@bulkEdit');
    Route::post('/products/bulk-update', 'ProductController@bulkUpdate');
    Route::post('/products/{id}/list-to-ebay', 'ProductController@listToEbay');
    Route::post('/products/{id}/list-to-discogs', 'ProductController@listToDiscogs');
    Route::post('/products/bulk-list-to-ebay', 'ProductController@bulkListToEbay');
    Route::post('/products/bulk-list-to-discogs', 'ProductController@bulkListToDiscogs');
    Route::post('/products/bulk-update-location', 'ProductController@updateProductLocation');
    Route::get('/products/get-product-to-edit/{product_id}', 'ProductController@getProductToEdit');
    
    Route::post('/products/get_sub_categories', 'ProductController@getSubCategories');
    Route::post('/products/get_sub_categories', [ProductController::class, 'getSubCategories'])->name('product.get_sub_categories');
    Route::get('/products/autocomplete-suggestions', 'ProductController@autocompleteSuggestions')->name('products.autocompleteSuggestions');
    Route::get('/products/export-artists-titles', 'ProductController@exportArtistsAndTitles')->name('products.exportArtistsTitles');

    Route::get('/products/get_sub_units', 'ProductController@getSubUnits');
    Route::post('/products/product_form_part', 'ProductController@getProductVariationFormPart');
    Route::post('/products/get_product_variation_row', 'ProductController@getProductVariationRow');
    Route::post('/products/get_variation_template', 'ProductController@getVariationTemplate');
    Route::get('/products/get_variation_value_row', 'ProductController@getVariationValueRow');
    Route::post('/products/check_product_sku', 'ProductController@checkProductSku');
    Route::post('/products/validate_variation_skus', 'ProductController@validateVaritionSkus'); //validates multiple skus at once
    Route::get('/products/quick_add', 'ProductController@quickAdd');
    Route::post('/products/save_quick_product', 'ProductController@saveQuickProduct');
    Route::get('/product/mass-create', [ProductController::class, 'massCreate'])->name('product.massCreate');
    Route::get('/product/mass-create/row', [ProductController::class, 'getMassProductRow'])->name('product.getMassProductRow');
    Route::get('/product/mass-create/get-products', [ProductController::class, 'massProductGetProducts'])->name('product.massCreate.getProduct');
    Route::get('/product/mass-create/get-product-price-recommendation', [ProductController::class, 'getProductPriceRecommendation']);
    Route::get('/product/mass-create/get-discogs-prices', [ProductController::class, 'getDiscogsPrices']);
    Route::post('/product/mass-store', [ProductController::class, 'massStore'])->name('product.massStore');
    Route::post('/products/bulk-send-to-purchase', [ProductController::class, 'bulkSendToPurchase'])->name('products.bulkSendToPurchase');
    Route::get('/products/get-combo-product-entry-row', 'ProductController@getComboProductEntryRow');
    Route::post('/products/toggle-woocommerce-sync', 'ProductController@toggleWooCommerceSync');
    Route::get('/products/bulk-update-categories', 'ProductController@bulkCategoryUpdatePage');
    Route::post('/products/bulk-update-categories', 'ProductController@bulkUpdateCategories');
    Route::get('/products/export-uncategorized', 'ProductController@exportUncategorized');
    Route::get('/products/import-sold-items', 'ProductController@importSoldItems')->name('products.importSoldItems');
    Route::post('/products/process-import-sold-items', 'ProductController@processImportSoldItems')->name('products.processImportSoldItems');
    Route::post('/products/process-import-sold-items-from-file', 'ProductController@processImportSoldItemsFromFile')->name('products.processImportSoldItemsFromFile');
    Route::post('/products/{id}/set-current-stock', 'ProductController@setCurrentStock')->name('products.setCurrentStock');
    Route::get('/products/{id}/set-current-stock-quick', 'ProductController@setCurrentStockQuickPage')->name('products.setCurrentStockQuick');

    Route::resource('products', 'ProductController');

    Route::post('/import-purchase-products', 'PurchaseController@importPurchaseProducts');
    Route::post('/purchases/update-status', 'PurchaseController@updateStatus');
    Route::get('/purchases/get_products', 'PurchaseController@getProducts');
    Route::get('/purchases/get_suppliers', 'PurchaseController@getSuppliers');
    Route::post('/purchases/get_purchase_entry_row', 'PurchaseController@getPurchaseEntryRow');
    Route::post('/purchases/check_ref_number', 'PurchaseController@checkRefNumber');
    Route::get('/buy-from-customer', 'BuyFromCustomerController@create')->name('buy-from-customer.create');
    Route::get('/buy-from-customer/calculate', 'BuyFromCustomerController@create');
    Route::post('/buy-from-customer/calculate', 'BuyFromCustomerController@calculate')->name('buy-from-customer.calculate');
    Route::post('/buy-from-customer', 'BuyFromCustomerController@store')->name('buy-from-customer.store');
    Route::post('/buy-from-customer/accept', 'BuyFromCustomerController@accept')->name('buy-from-customer.accept');
    Route::post('/buy-from-customer/reject', 'BuyFromCustomerController@reject')->name('buy-from-customer.reject');
    Route::get('/buy-from-customer/history', 'BuyFromCustomerController@history')->name('buy-from-customer.history');
    Route::resource('purchases', 'PurchaseController')->except(['show']);

    Route::get('/toggle-subscription/{id}', 'SellPosController@toggleRecurringInvoices');
    Route::post('/sells/pos/get-types-of-service-details', 'SellPosController@getTypesOfServiceDetails');
    Route::get('/sells/subscriptions', 'SellPosController@listSubscriptions');
    Route::get('/sells/duplicate/{id}', 'SellController@duplicateSell');
    Route::get('/sells/drafts', 'SellController@getDrafts');
    Route::get('/sells/convert-to-draft/{id}', 'SellPosController@convertToInvoice');
    Route::get('/sells/convert-to-proforma/{id}', 'SellPosController@convertToProforma');
    Route::get('/sells/quotations', 'SellController@getQuotations');
    Route::get('/sells/draft-dt', 'SellController@getDraftDatables');
    Route::resource('sells', 'SellController')->except(['show']);

    // Itemized sales report (Sabina/accountant view) — one row per product
    // sold, with cost + margin per line. Same filters as /sells.
    Route::get('/sales-itemized', 'SellController@itemized')->name('sales.itemized');

    Route::get('/import-sales', 'ImportSalesController@index');
    Route::post('/import-sales/preview', 'ImportSalesController@preview');
    Route::post('/import-sales', 'ImportSalesController@import');
    Route::get('/revert-sale-import/{batch}', 'ImportSalesController@revertSaleImport');

    Route::get('/sells/pos/get_product_row/{variation_id}/{location_id}', 'SellPosController@getProductRow');
    Route::post('/sells/pos/get_manual_product_row', [SellPosController::class, 'getManualProductRow']);
    Route::post('/sells/pos/get_manual_product_rows', [SellPosController::class, 'getManualProductRows']);
    Route::post('/sells/pos/get_plastic_bag_row', [SellPosController::class, 'getPlasticBagRow']);
    Route::get('/sells/pos/get-customer-account-info', [SellPosController::class, 'getCustomerAccountInfo']);
    Route::get('/sells/pos/lookup-gift-card', [SellPosController::class, 'lookupGiftCard']);
    Route::get('/sells/pos/get-customer-preorders/{contact_id}', 'PreorderController@getCustomerPreorders');
    Route::post('/sells/pos/get_payment_row', 'SellPosController@getPaymentRow');
    Route::post('/sells/pos/send-to-clover/{id}', 'SellPosController@sendToClover');
    Route::get('/sells/pos/clover-status/{payment_id}', 'SellPosController@getCloverPaymentStatus');
    Route::post('/sells/pos/get-reward-details', 'SellPosController@getRewardDetails');
    Route::get('/sells/pos/get-recent-transactions', 'SellPosController@getRecentTransactions');
    Route::get('/sells/pos/get-product-suggestion', 'SellPosController@getProductSuggestion');
    Route::get('/sells/pos/get-featured-products/{location_id}', 'SellPosController@getFeaturedProducts');
    Route::get('/settings/manual-item-price-rules', 'ManualItemPriceRuleController@index')->name('manual-item-price-rules.index');
    Route::post('/settings/manual-item-price-rules', 'ManualItemPriceRuleController@store')->name('manual-item-price-rules.store');
    Route::put('/settings/manual-item-price-rules/{id}', 'ManualItemPriceRuleController@update')->name('manual-item-price-rules.update');
    Route::delete('/settings/manual-item-price-rules/{id}', 'ManualItemPriceRuleController@destroy')->name('manual-item-price-rules.destroy');
    Route::get('/settings/product-entry-rules', 'ProductEntryRuleController@index')->name('product-entry-rules.index');
    Route::post('/settings/product-entry-rules', 'ProductEntryRuleController@store')->name('product-entry-rules.store');
    Route::put('/settings/product-entry-rules/{id}', 'ProductEntryRuleController@update')->name('product-entry-rules.update');
    Route::delete('/settings/product-entry-rules/{id}', 'ProductEntryRuleController@destroy')->name('product-entry-rules.destroy');
    Route::get('/settings/product-entry-rules/resolve', 'ProductEntryRuleController@resolve')->name('product-entry-rules.resolve');
    Route::get('/reset-mapping', 'SellController@resetMapping');

    // Export routes must be defined BEFORE resource route to avoid conflicts
    Route::get('/pos/export-csv', 'SellPosController@exportPosSalesCsv')->name('pos.exportCsv');
    Route::get('/pos/export-excel', 'SellPosController@exportPosSalesExcel')->name('pos.exportExcel');
    Route::get('/pos/export-manual-products', 'SellPosController@exportManualProducts')->name('pos.exportManualProducts');

    Route::get('/pos/recent-feed', 'SellPosController@recentSalesFeed')->name('pos.recentFeed');

    Route::resource('pos', 'SellPosController');

    Route::resource('roles', 'RoleController');

    Route::resource('users', 'ManageUserController');

    Route::resource('group-taxes', 'GroupTaxController');

    Route::get('/barcodes/set_default/{id}', 'BarcodeController@setDefault');
    Route::resource('barcodes', 'BarcodeController');

    //Invoice schemes..
    Route::get('/invoice-schemes/set_default/{id}', 'InvoiceSchemeController@setDefault');
    Route::resource('invoice-schemes', 'InvoiceSchemeController');

    //Print Labels
    Route::get('/labels/show', 'LabelsController@show')->name('labels.show');;
    Route::get('/labels/add-product-row', 'LabelsController@addProductRow');
    Route::match(['get', 'post'], '/labels/preview', 'LabelsController@preview');

    //Reports...
    Route::get('/reports/get-stock-by-sell-price', 'ReportController@getStockBySellingPrice');
    Route::get('/reports/category-sales-report', 'ReportController@categorySalesReport');
    Route::get('/reports/purchase-report', 'ReportController@purchaseReport');
    Route::get('/reports/purchase-report/summary', 'ReportController@purchaseReportSummary')->name('reports.purchase-report.summary');
    Route::get('/reports/purchase-report/export', 'ReportController@purchaseReportExport')->name('reports.purchase-report.export');
    Route::get('/reports/purchase-report/walkin-history', 'ReportController@purchaseReportWalkinHistory')->name('reports.purchase-report.walkin-history');
    Route::get('/reports/sale-report', 'ReportController@saleReport');
    Route::get('/reports/service-staff-report', 'ReportController@getServiceStaffReport');
    Route::get('/reports/service-staff-line-orders', 'ReportController@serviceStaffLineOrders');
    Route::get('/reports/table-report', 'ReportController@getTableReport');
    Route::get('/reports/profit-loss', 'ReportController@getProfitLoss');
    Route::get('/reports/get-opening-stock', 'ReportController@getOpeningStock');
    Route::get('/reports/purchase-sell', 'ReportController@getPurchaseSell');
    Route::get('/reports/customer-supplier', 'ReportController@getCustomerSuppliers');
    Route::get('/reports/stock-report', 'ReportController@getStockReport');
    Route::get('/reports/stock-details', 'ReportController@getStockDetails');
    Route::get('/reports/tax-report', 'ReportController@getTaxReport');
    Route::get('/reports/tax-details', 'ReportController@getTaxDetails');
    Route::get('/reports/trending-products', 'ReportController@getTrendingProducts');
    Route::get('/reports/expense-report', 'ReportController@getExpenseReport');
    Route::get('/reports/stock-adjustment-report', 'ReportController@getStockAdjustmentReport');
    Route::get('/reports/register-report', 'ReportController@getRegisterReport');
    Route::get('/reports/sales-representative-report', 'ReportController@getSalesRepresentativeReport');
    Route::get('/reports/sales-representative-total-expense', 'ReportController@getSalesRepresentativeTotalExpense');
    Route::get('/reports/sales-representative-total-sell', 'ReportController@getSalesRepresentativeTotalSell');
    Route::get('/reports/sales-representative-total-commission', 'ReportController@getSalesRepresentativeTotalCommission');
    Route::get('/reports/stock-expiry', 'ReportController@getStockExpiryReport');
    Route::get('/reports/stock-expiry-edit-modal/{purchase_line_id}', 'ReportController@getStockExpiryReportEditModal');
    Route::post('/reports/stock-expiry-update', 'ReportController@updateStockExpiryReport')->name('updateStockExpiryReport');
    Route::get('/reports/customer-group', 'ReportController@getCustomerGroup');
    Route::get('/reports/product-purchase-report', 'ReportController@getproductPurchaseReport');
    Route::get('/reports/product-sell-grouped-by', 'ReportController@productSellReportBy');
    Route::get('/reports/product-sell-report', 'ReportController@getproductSellReport');
    Route::get('/reports/product-sell-report-with-purchase', 'ReportController@getproductSellReportWithPurchase');
    Route::get('/reports/product-sell-grouped-report', 'ReportController@getproductSellGroupedReport');
    Route::get('/reports/lot-report', 'ReportController@getLotReport');
    Route::get('/reports/purchase-payment-report', 'ReportController@purchasePaymentReport');
    Route::get('/reports/sell-payment-report', 'ReportController@sellPaymentReport');
    Route::get('/reports/product-stock-details', 'ReportController@productStockDetails');
    Route::get('/reports/adjust-product-stock', 'ReportController@adjustProductStock');
    Route::get('/reports/get-profit/{by?}', 'ReportController@getProfit');
    Route::get('/reports/items-report', 'ReportController@itemsReport');
    Route::get('/reports/inventory-check-assistant', 'InventoryCheckController@index');
    Route::get('/reports/inventory-check-assistant/data', 'InventoryCheckController@data');
    Route::get('/reports/inventory-check-assistant/buckets', 'InventoryCheckController@buckets');
    Route::get('/reports/inventory-check-assistant/export', 'InventoryCheckController@export');
    Route::post('/reports/inventory-check-assistant/chart-import', 'InventoryCheckController@importChart');
    Route::get('/reports/inventory-check-assistant/chart-latest/{source}', 'InventoryCheckController@latestChart');
    Route::post('/reports/inventory-check-assistant/customer-want/{id}/fulfill', 'InventoryCheckController@fulfillCustomerWant');
    Route::post('/reports/inventory-check-assistant/run-email-import', 'InventoryCheckController@runEmailImport');
    Route::post('/reports/inventory-check-assistant/run-apple-music', 'InventoryCheckController@runAppleMusicImport');
    Route::get('/reports/inventory-check-assistant/notes', 'InventoryCheckController@listNotes');
    Route::post('/reports/inventory-check-assistant/notes', 'InventoryCheckController@storeNote');
    Route::delete('/reports/inventory-check-assistant/notes/{id}', 'InventoryCheckController@destroyNote');
    Route::get('/reports/inventory-check-assistant/sessions', 'InventoryCheckController@listSessions');
    Route::post('/reports/inventory-check-assistant/sessions', 'InventoryCheckController@storeSession');
    Route::put('/reports/inventory-check-assistant/sessions/{id}', 'InventoryCheckController@updateSession');
    Route::delete('/reports/inventory-check-assistant/sessions/{id}', 'InventoryCheckController@destroySession');
    Route::get('/reports/get-stock-value', 'ReportController@getStockValue');
    Route::get('/reports/inventory-valuation-summary', 'ReportController@inventoryValuationSummary');
    Route::get('/reports/inventory-valuation-detail', 'ReportController@inventoryValuationDetail');
    Route::get('/reports/sales-by-item-cost-margin', 'ReportController@salesByItemCostMargin');
    Route::get('/reports/purchases-by-item-vendor', 'ReportController@purchasesByItemVendor');
    Route::get('/reports/abc-inventory-classification', 'ReportController@abcInventoryClassification');
    Route::get('/reports/inventory-aging-summary', 'ReportController@inventoryAgingSummary');
    Route::get('/reports/landed-cost-summary', 'ReportController@landedCostSummary');
    Route::get('/reports/purchase-order-vs-received', 'ReportController@purchaseOrderVsReceived');
    Route::get('/reports/item-transaction-history', 'ReportController@itemTransactionHistory');
    Route::get('/reports/product-entry-productivity', 'ReportController@productEntryProductivity');
    Route::get('/reports/dead-stock', 'ReportController@deadStockReport');
    Route::get('/reports/whatnot', 'ReportController@whatnotReport');
    // The old "Clover vs ERP" rollup is superseded by the EOD reconciliation
    // page — same data, better structure (shift cards with drawer math).
    // Redirect preserves any bookmarks pointing at the old URL.
    Route::redirect('/reports/clover-vs-erp', '/reports/clover-eod-reconciliation');
    Route::get('/reports/clover-eod-reconciliation', 'ReportController@cloverEodReconciliation')->name('reports.clover-eod');
    Route::redirect('/reports/clover-reconciliation', '/reports/clover-eod-reconciliation');
    Route::post('/reports/clover-eod-reconciliation/sync-now', 'ReportController@cloverEodSyncNow')->name('reports.clover-eod.sync');
    Route::get('/reports/employee-leaderboard', 'ReportController@employeeLeaderboard');

    // Reports hub — organized index of all reports with per-user favorites
    Route::get('/reports', 'ReportsHubController@index')->name('reports.hub');
    Route::post('/reports/favorite', 'ReportsHubController@toggleFavorite')->name('reports.favorite');

    // Customer Wants (call-me-when-it-comes-in list)
    Route::get('/customer-wants', 'CustomerWantController@index')->name('customer-wants.index');
    Route::get('/customer-wants/create', 'CustomerWantController@create')->name('customer-wants.create');
    Route::post('/customer-wants', 'CustomerWantController@store')->name('customer-wants.store');
    Route::get('/customer-wants/{id}/edit', 'CustomerWantController@edit')->name('customer-wants.edit');
    Route::put('/customer-wants/{id}', 'CustomerWantController@update')->name('customer-wants.update');
    Route::post('/customer-wants/{id}/fulfill', 'CustomerWantController@fulfill')->name('customer-wants.fulfill');
    Route::delete('/customer-wants/{id}', 'CustomerWantController@destroy')->name('customer-wants.destroy');

    // POS-facing endpoints — JSON, used by the customer-wants sidebar widget
    // that renders on /pos/create when a rewards account is loaded.
    Route::get('/customer-wants/for-contact/{contactId}', 'CustomerWantController@forContact')->name('customer-wants.for-contact');
    Route::post('/customer-wants/from-pos', 'CustomerWantController@storeFromPos')->name('customer-wants.from-pos');
    Route::post('/customer-wants/{id}/fulfill-ajax', 'CustomerWantController@fulfillAjax')->name('customer-wants.fulfill-ajax');

    // Customer Wants list ("call me when X comes in")
    Route::get('/customer-wants', 'CustomerWantController@index')->name('customer_wants.index');
    Route::get('/customer-wants/create', 'CustomerWantController@create')->name('customer_wants.create');
    Route::post('/customer-wants', 'CustomerWantController@store')->name('customer_wants.store');
    Route::get('/customer-wants/{id}/edit', 'CustomerWantController@edit')->name('customer_wants.edit');
    Route::put('/customer-wants/{id}', 'CustomerWantController@update')->name('customer_wants.update');
    Route::post('/customer-wants/{id}/fulfill', 'CustomerWantController@fulfill')->name('customer_wants.fulfill');
    Route::delete('/customer-wants/{id}', 'CustomerWantController@destroy')->name('customer_wants.destroy');

    Route::get('business-location/activate-deactivate/{location_id}', 'BusinessLocationController@activateDeactivateLocation');

    //Business Location Settings...
    Route::prefix('business-location/{location_id}')->name('location.')->group(function () {
        Route::get('settings', 'LocationSettingsController@index')->name('settings');
        Route::post('settings', 'LocationSettingsController@updateSettings')->name('settings_update');
    });

    //Business Locations...
    Route::post('business-location/check-location-id', 'BusinessLocationController@checkLocationId');
    Route::resource('business-location', 'BusinessLocationController');

    //Invoice layouts..
    Route::resource('invoice-layouts', 'InvoiceLayoutController');

    Route::post('get-expense-sub-categories', 'ExpenseCategoryController@getSubCategories');

    //Expense Categories...
    Route::resource('expense-categories', 'ExpenseCategoryController');

    //Expenses...
    Route::resource('expenses', 'ExpenseController');

    //Transaction payments...
    // Route::get('/payments/opening-balance/{contact_id}', 'TransactionPaymentController@getOpeningBalancePayments');
    Route::get('/payments/show-child-payments/{payment_id}', 'TransactionPaymentController@showChildPayments');
    Route::get('/payments/view-payment/{payment_id}', 'TransactionPaymentController@viewPayment');
    Route::get('/payments/add_payment/{transaction_id}', 'TransactionPaymentController@addPayment');
    Route::get('/payments/pay-contact-due/{contact_id}', 'TransactionPaymentController@getPayContactDue');
    Route::post('/payments/pay-contact-due', 'TransactionPaymentController@postPayContactDue');
    Route::resource('payments', 'TransactionPaymentController');

    //Printers...
    Route::resource('printers', 'PrinterController');

    Route::get('/stock-adjustments/remove-expired-stock/{purchase_line_id}', 'StockAdjustmentController@removeExpiredStock');
    Route::post('/stock-adjustments/get_product_row', 'StockAdjustmentController@getProductRow');
    Route::resource('stock-adjustments', 'StockAdjustmentController');

    Route::get('/cash-register/register-details', 'CashRegisterController@getRegisterDetails');
    Route::get('/cash-register/close-register/{id?}', 'CashRegisterController@getCloseRegister');
    Route::post('/cash-register/close-register', 'CashRegisterController@postCloseRegister');
    Route::resource('cash-register', 'CashRegisterController');

    //Import products
    Route::get('/import-products', 'ImportProductsController@index');
    Route::post('/import-products/store', 'ImportProductsController@store');

    //Sales Commission Agent
    Route::resource('sales-commission-agents', 'SalesCommissionAgentController');

    //Stock Transfer
    Route::get('stock-transfers/print/{id}', 'StockTransferController@printInvoice');
    Route::post('stock-transfers/update-status/{id}', 'StockTransferController@updateStatus');
    Route::resource('stock-transfers', 'StockTransferController');
    
    Route::get('/opening-stock/add/{product_id}', 'OpeningStockController@add');
    Route::post('/opening-stock/save', 'OpeningStockController@save');

    //Customer Groups
    Route::resource('customer-group', 'CustomerGroupController');

    //Import opening stock
    Route::get('/import-opening-stock', 'ImportOpeningStockController@index');
    Route::post('/import-opening-stock/store', 'ImportOpeningStockController@store');

    //Sell return
    Route::get('validate-invoice-to-return/{invoice_no}', 'SellReturnController@validateInvoiceToReturn');
    Route::resource('sell-return', 'SellReturnController');
    Route::get('sell-return/get-product-row', 'SellReturnController@getProductRow');
    Route::get('/sell-return/print/{id}', 'SellReturnController@printInvoice');
    Route::get('/sell-return/add/{id}', 'SellReturnController@add');
    
    //Backup
    Route::get('backup/download/{file_name}', 'BackUpController@download');
    Route::get('backup/delete/{file_name}', 'BackUpController@delete');
    Route::resource('backup', 'BackUpController', ['only' => [
        'index', 'create', 'store'
    ]]);

    Route::get('selling-price-group/activate-deactivate/{id}', 'SellingPriceGroupController@activateDeactivate');
    Route::get('export-selling-price-group', 'SellingPriceGroupController@export');
    Route::post('import-selling-price-group', 'SellingPriceGroupController@import');

    Route::resource('selling-price-group', 'SellingPriceGroupController');

    Route::resource('notification-templates', 'NotificationTemplateController')->only(['index', 'store']);
    Route::get('notification/get-template/{transaction_id}/{template_for}', 'NotificationController@getTemplate');
    Route::post('notification/send', 'NotificationController@send');

    Route::post('/purchase-return/update', 'CombinedPurchaseReturnController@update');
    Route::get('/purchase-return/edit/{id}', 'CombinedPurchaseReturnController@edit');
    Route::post('/purchase-return/save', 'CombinedPurchaseReturnController@save');
    Route::post('/purchase-return/get_product_row', 'CombinedPurchaseReturnController@getProductRow');
    Route::get('/purchase-return/create', 'CombinedPurchaseReturnController@create');
    Route::get('/purchase-return/add/{id}', 'PurchaseReturnController@add');
    Route::resource('/purchase-return', 'PurchaseReturnController', ['except' => ['create']]);

    Route::get('/discount/activate/{id}', 'DiscountController@activate');
    Route::post('/discount/mass-deactivate', 'DiscountController@massDeactivate');
    Route::resource('discount', 'DiscountController');

    Route::group(['prefix' => 'account'], function () {
        Route::resource('/account', 'AccountController');
        Route::get('/fund-transfer/{id}', 'AccountController@getFundTransfer');
        Route::post('/fund-transfer', 'AccountController@postFundTransfer');
        Route::get('/deposit/{id}', 'AccountController@getDeposit');
        Route::post('/deposit', 'AccountController@postDeposit');
        Route::get('/close/{id}', 'AccountController@close');
        Route::get('/activate/{id}', 'AccountController@activate');
        Route::get('/delete-account-transaction/{id}', 'AccountController@destroyAccountTransaction');
        Route::get('/edit-account-transaction/{id}', 'AccountController@editAccountTransaction');
        Route::post('/update-account-transaction/{id}', 'AccountController@updateAccountTransaction');
        Route::get('/get-account-balance/{id}', 'AccountController@getAccountBalance');
        Route::get('/balance-sheet', 'AccountReportsController@balanceSheet');
        Route::get('/trial-balance', 'AccountReportsController@trialBalance');
        Route::get('/payment-account-report', 'AccountReportsController@paymentAccountReport');
        Route::get('/link-account/{id}', 'AccountReportsController@getLinkAccount');
        Route::post('/link-account', 'AccountReportsController@postLinkAccount');
        Route::get('/cash-flow', 'AccountController@cashFlow');
    });
    
    Route::resource('account-types', 'AccountTypeController');

    //Restaurant module
    Route::group(['prefix' => 'modules'], function () {
        Route::resource('tables', 'Restaurant\TableController');
        Route::resource('modifiers', 'Restaurant\ModifierSetsController');

        //Map modifier to products
        Route::get('/product-modifiers/{id}/edit', 'Restaurant\ProductModifierSetController@edit');
        Route::post('/product-modifiers/{id}/update', 'Restaurant\ProductModifierSetController@update');
        Route::get('/product-modifiers/product-row/{product_id}', 'Restaurant\ProductModifierSetController@product_row');

        Route::get('/add-selected-modifiers', 'Restaurant\ProductModifierSetController@add_selected_modifiers');

        Route::get('/kitchen', 'Restaurant\KitchenController@index');
        Route::get('/kitchen/mark-as-cooked/{id}', 'Restaurant\KitchenController@markAsCooked');
        Route::post('/refresh-orders-list', 'Restaurant\KitchenController@refreshOrdersList');
        Route::post('/refresh-line-orders-list', 'Restaurant\KitchenController@refreshLineOrdersList');

        Route::get('/orders', 'Restaurant\OrderController@index');
        Route::get('/orders/mark-as-served/{id}', 'Restaurant\OrderController@markAsServed');
        Route::get('/data/get-pos-details', 'Restaurant\DataController@getPosDetails');
        Route::get('/orders/mark-line-order-as-served/{id}', 'Restaurant\OrderController@markLineOrderAsServed');
        Route::get('/print-line-order', 'Restaurant\OrderController@printLineOrder');
    });

    Route::get('bookings/get-todays-bookings', 'Restaurant\BookingController@getTodaysBookings');
    Route::resource('bookings', 'Restaurant\BookingController');
    
    Route::resource('types-of-service', 'TypesOfServiceController');
    Route::get('sells/edit-shipping/{id}', 'SellController@editShipping');
    Route::put('sells/update-shipping/{id}', 'SellController@updateShipping');
    Route::get('shipments', 'SellController@shipments');

    Route::post('upload-module', 'Install\ModulesController@uploadModule');
    Route::resource('manage-modules', 'Install\ModulesController')
        ->only(['index', 'destroy', 'update']);
    Route::resource('warranties', 'WarrantyController');

    Route::resource('dashboard-configurator', 'DashboardConfiguratorController')
    ->only(['edit', 'update']);

    Route::get('view-media/{model_id}', 'SellController@viewMedia');

    //common controller for document & note
    Route::get('get-document-note-page', 'DocumentAndNoteController@getDocAndNoteIndexPage');
    Route::post('post-document-upload', 'DocumentAndNoteController@postMedia');
    Route::resource('note-documents', 'DocumentAndNoteController');
    Route::resource('purchase-order', 'PurchaseOrderController');
    Route::get('get-purchase-orders/{contact_id}', 'PurchaseOrderController@getPurchaseOrders');
    Route::get('get-purchase-order-lines/{purchase_order_id}', 'PurchaseController@getPurchaseOrderLines');
    Route::get('edit-purchase-orders/{id}/status', 'PurchaseOrderController@getEditPurchaseOrderStatus');
    Route::put('update-purchase-orders/{id}/status', 'PurchaseOrderController@postEditPurchaseOrderStatus');
    Route::resource('sales-order', 'SalesOrderController')->only(['index']);
    Route::get('get-sales-orders/{customer_id}', 'SalesOrderController@getSalesOrders');
    Route::get('get-sales-order-lines', 'SellPosController@getSalesOrderLines');
    Route::get('edit-sales-orders/{id}/status', 'SalesOrderController@getEditSalesOrderStatus');
    Route::put('update-sales-orders/{id}/status', 'SalesOrderController@postEditSalesOrderStatus');
    Route::get('reports/activity-log', 'ReportController@activityLog');
    Route::get('user-location/{latlng}', 'HomeController@getUserLocation');

    // Browser-based runner for the Nivessa Backend xlsx imports.
    // Sarah has no SSH; this page uploads the xlsx and streams artisan output back.
    Route::get('/admin/nivessa-backend-import', 'NivessaBackendImportController@index');
    Route::post('/admin/nivessa-backend-import/chunk', 'NivessaBackendImportController@chunk');
    Route::post('/admin/nivessa-backend-import/run', 'NivessaBackendImportController@run');

    // Flat-rate cost price rules (accountant punch list). Backfills missing
    // default_purchase_price on variations by category. Preview then Apply.
    Route::get('/admin/cost-price-rules', 'CostPriceRulesController@index');
    Route::post('/admin/cost-price-rules/run', 'CostPriceRulesController@run');

    // One-shot cleanup: historical xlsx import wrote some transactions with
    // future dates (typos / no-year rows defaulting to 2026). Rewrites them
    // to the 1st of the month encoded in the import source slug.
    Route::get('/admin/fix-imported-dates', 'FixImportedDatesController@index');
    Route::post('/admin/fix-imported-dates/run', 'FixImportedDatesController@run');

    // Read-only audit: per-day overlap between ERP-native and xlsx-imported
    // sells. Flags Sep 2025+ overlaps as likely duplicates (ERP was live).
    Route::get('/admin/import-duplicate-check', 'ImportDuplicateCheckController@index');

    // Lists products where exc-tax / inc-tax purchase prices disagree (legacy
    // tax-math artifact — Nivessa has resale cert so they should always match).
    // One-click aligns both columns to a chosen value.
    Route::get('/admin/purchase-price-mismatch', 'PurchasePriceMismatchController@index');
    Route::post('/admin/purchase-price-mismatch/run', 'PurchasePriceMismatchController@run');

    // Recovery for variations whose cost was zeroed by the 2026-04-27 backfill.
    // Pulls the most recent purchase_lines entry per variation and copies it back.
    Route::get('/admin/recover-zeroed-costs', 'RecoverZeroedCostsController@index');
    Route::post('/admin/recover-zeroed-costs/run', 'RecoverZeroedCostsController@run');

    // History of destructive admin backfills with one-click Undo. Every /admin/*
    // /run endpoint that mutates rows in bulk should write a snapshot here first.
    Route::get('/admin/admin-action-history', 'AdminActionHistoryController@index');
    Route::post('/admin/admin-action-history/undo', 'AdminActionHistoryController@undo');

    // Pinpoint the actual rows wiped by the 2026-04-27 backfill so Sohaib's
    // surgical restore from the 04-24 backup hits only the victims.
    Route::get('/admin/wipe-audit', 'WipeAuditController@index');
    Route::get('/admin/wipe-audit/csv', 'WipeAuditController@csv');

    // One-shot diagnostic: did the Nivessa Backend xlsx imports land on prod?
    // Hit /admin/nivessa-import-status in the browser to see row counts per table.
    Route::get('/admin/nivessa-import-status', function () {
        $salesLike = 'nivessa_backend_sales_%';

        $txCount = \DB::table('transactions')
            ->where('import_source', 'like', $salesLike)
            ->count();
        $txMin = \DB::table('transactions')
            ->where('import_source', 'like', $salesLike)
            ->min('transaction_date');
        $txMax = \DB::table('transactions')
            ->where('import_source', 'like', $salesLike)
            ->max('transaction_date');
        $txBySheet = \DB::table('transactions')
            ->where('import_source', 'like', $salesLike)
            ->selectRaw('import_source, COUNT(*) as row_count')
            ->groupBy('import_source')
            ->orderByDesc('row_count')
            ->get();

        $sellLineCount = \DB::table('transaction_sell_lines')
            ->where('import_source', 'like', $salesLike)
            ->count();

        $storeCreditContacts = \DB::table('contacts')
            ->where('import_source', 'nivessa_backend_store_credit')
            ->count();

        $customerWants = \DB::table('customer_wants')
            ->where('import_source', 'nivessa_backend_customer_asks')
            ->count();

        return response()->json([
            'historical_sales' => [
                'transactions' => $txCount,
                'sell_lines' => $sellLineCount,
                'date_range' => [$txMin, $txMax],
                'sheets_imported' => $txBySheet->count(),
                'by_sheet' => $txBySheet,
            ],
            'store_credit' => [
                'contacts_tagged' => $storeCreditContacts,
            ],
            'customer_asks' => [
                'customer_wants' => $customerWants,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    });
});


Route::middleware(['EcomApi'])->prefix('api/ecom')->group(function () {
    Route::get('products/{id?}', 'ProductController@getProductsApi');
    //Route::get('categories', 'CategoryController@getCategoriesApi');
    Route::get('brands', 'BrandController@getBrandsApi');
    Route::post('customers', 'ContactController@postCustomersApi');
    Route::get('settings', 'BusinessController@getEcomSettings');
    Route::get('variations', 'ProductController@getVariationsApi');
    Route::post('orders', 'SellPosController@placeOrdersApi');
});

//common route
Route::middleware(['auth'])->group(function () {
    Route::get('/logout', 'Auth\LoginController@logout')->name('logout');
});

Route::middleware(['setData', 'auth', 'SetSessionData', 'language', 'timezone'])->group(function () {
    Route::get('/load-more-notifications', 'HomeController@loadMoreNotifications');
    Route::get('/get-total-unread', 'HomeController@getTotalUnreadNotifications');
    Route::get('/purchases/print/{id}', 'PurchaseController@printInvoice');
    Route::get('/purchases/{id}', 'PurchaseController@show');
    Route::get('/download-purchase-order/{id}/pdf', 'PurchaseOrderController@downloadPdf')->name('purchaseOrder.downloadPdf');
    Route::get('/sells/{id}', 'SellController@show');
    Route::get('/sells/{transaction_id}/print', 'SellPosController@printInvoice')->name('sell.printInvoice');
    Route::get('/download-sells/{transaction_id}/pdf', 'SellPosController@downloadPdf')->name('sell.downloadPdf');
    Route::get('/download-quotation/{id}/pdf', 'SellPosController@downloadQuotationPdf')
        ->name('quotation.downloadPdf');
    Route::get('/download-packing-list/{id}/pdf', 'SellPosController@downloadPackingListPdf')
        ->name('packing.downloadPdf');
    Route::get('/sells/invoice-url/{id}', 'SellPosController@showInvoiceUrl');
    Route::get('/show-notification/{id}', 'HomeController@showNotification');
});


Route::get('/download-barcode' , [\App\Http\Controllers\ProductController::class , 'downloadBarCode']);

Route::get('/purchases-download-demo-excel', 'PurchaseController@downloadDemoExcel')->name('purchases.download-demo-excel');
Route::post('store-purchase-excel', 'PurchaseController@importExcel')->name('purchases.store-excel');
Route::get('/import-purchase-excel-file', 'PurchaseController@importExcelFile')->name('purchases.import-excel-file');

Route::post('updateStock' , [\App\Http\Controllers\ProductController::class , 'updateStock']);
