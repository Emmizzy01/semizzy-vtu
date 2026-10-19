<?php

use App\Http\Controllers\Admin\IntegrationSettingsController;
use App\Http\Controllers\Admin\StaffAccessController;
use App\Http\Controllers\Admin\StorefrontCommissionController;
use App\Http\Controllers\Admin\VtuOrderOperationsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginChallengeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EducationServiceController;
use App\Http\Controllers\Payments\PaymentFundingController;
use App\Http\Controllers\Payments\ManualDepositController;
use App\Http\Controllers\Admin\ManualDepositSettingsController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\BrandingAssetsController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\UserLevelPolicyController;
use App\Http\Controllers\Admin\UserLevelUpgradeReviewController;
use App\Http\Controllers\Admin\SecurityEventsController;
use App\Http\Controllers\Admin\FeatureControlController;
use App\Models\PlatformFeatureControl;
use App\Http\Controllers\Security\TransactionPinController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\BusinessWorkspaceController;
use App\Http\Controllers\Account\BusinessWorkspaceStorefrontController;
use App\Http\Controllers\Account\UserSessionController;
use App\Http\Controllers\Account\AccountTierController;
use App\Http\Controllers\Support\SupportTicketController;
use App\Http\Controllers\Support\HelpPageController;
use App\Http\Controllers\Admin\SupportTicketOperationsController;
use App\Services\IntegrationSettings;
use App\Http\Controllers\Payments\PaymentRefundController;\nuse App\Http\Controllers\Wallet\PeerWalletTransferController;
use App\Http\Controllers\Vtu\VtuOrderController;
use App\Http\Controllers\Vtu\VtuBillerVerificationController;
use App\Http\Controllers\Kyc\KycSubmissionController;
use App\Http\Controllers\VtuWebsiteRequestController;
use App\Http\Controllers\StreamingSubscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (IntegrationSettings $settings) {
    return view('semizzy-home', ['homepage' => [
        'browser_title' => (string) $settings->get('homepage.browser_title', 'Semizzy VTU - Single File Dashboard'),
        'cashback_line_one' => (string) $settings->get('homepage.cashback_line_one', 'Enjoy 10% cashback'),
        'cashback_line_two' => (string) $settings->get('homepage.cashback_line_two', 'on data purchases'),
        'cashback_subtitle' => (string) $settings->get('homepage.cashback_subtitle', 'Limited time offer'),
        'gadget_sale_title' => (string) $settings->get('homepage.gadget_sale_title', '🔥 Big Gadget Sale! Up to 50% OFF'),
        'gadget_sale_subtitle' => (string) $settings->get('homepage.gadget_sale_subtitle', 'iPhones, Samsung, Laptops'),
        'referral_title' => (string) $settings->get('homepage.referral_title', '💰 Refer & Earn ₦500 per friend!'),
        'referral_subtitle' => (string) $settings->get('homepage.referral_subtitle', 'Invite friends to Semizzy'),
    ]]);
})->name('home');

Route::get('/help/{slug}', [HelpPageController::class, 'show'])->whereIn('slug', array_keys(HelpPageController::definitions()))->name('help.pages.show');

Route::get('/store/{storefront:slug}', [BusinessWorkspaceStorefrontController::class, 'show'])->middleware('throttle:60,1')->name('storefronts.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/login/verify', [LoginChallengeController::class, 'show'])->middleware('throttle:10,1')->name('login.challenge');
    Route::post('/login/verify', [LoginChallengeController::class, 'verify'])->middleware('throttle:10,1')->name('login.challenge.verify');
    Route::get('/register', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1')->name('register.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportTicketController::class, 'store'])->middleware('throttle:5,1')->name('support.store');
    Route::post('/support/{ticket}/reply', [SupportTicketController::class, 'reply'])->middleware('throttle:10,1')->name('support.reply');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/workspaces', [BusinessWorkspaceController::class, 'index'])->middleware('throttle:30,1')->name('workspaces.index');
    Route::post('/workspaces', [BusinessWorkspaceController::class, 'store'])->middleware('throttle:5,1')->name('workspaces.store');
    Route::get('/workspaces/{workspace}/team', [BusinessWorkspaceController::class, 'team'])->middleware('throttle:30,1')->name('workspaces.team');
    Route::get('/workspaces/{workspace}/storefront', [BusinessWorkspaceStorefrontController::class, 'edit'])->middleware('throttle:30,1')->name('workspaces.storefront.edit');
    Route::put('/workspaces/{workspace}/storefront', [BusinessWorkspaceStorefrontController::class, 'update'])->middleware('throttle:10,1')->name('workspaces.storefront.update');
    Route::post('/workspaces/{workspace}/team/invitations', [BusinessWorkspaceController::class, 'invite'])->middleware('throttle:5,1')->name('workspaces.team.invite');
    Route::put('/workspaces/{workspace}/team/{membership}', [BusinessWorkspaceController::class, 'updateMember'])->middleware('throttle:10,1')->name('workspaces.team.update');
    Route::delete('/workspaces/{workspace}/team/{membership}', [BusinessWorkspaceController::class, 'removeMember'])->middleware('throttle:10,1')->name('workspaces.team.remove');
    Route::get('/workspace-invitations/{membership}/{token}', [BusinessWorkspaceController::class, 'showInvitation'])->middleware('throttle:10,1')->name('workspaces.invitations.show');
    Route::post('/workspace-invitations/{membership}/{token}', [BusinessWorkspaceController::class, 'acceptInvitation'])->middleware('throttle:5,1')->name('workspaces.invitations.accept');
    Route::get('/account/sessions', [UserSessionController::class, 'index'])->name('account.sessions.index');
    Route::delete('/account/sessions/{sessionId}', [UserSessionController::class, 'revoke'])->whereUuid('sessionId')->middleware('throttle:10,1')->name('account.sessions.revoke');
    Route::post('/account/sessions/revoke-others', [UserSessionController::class, 'revokeOthers'])->middleware('throttle:5,1')->name('account.sessions.revoke-others');
    Route::get('/account/kyc', [KycSubmissionController::class, 'index'])->name('account.kyc.index');
    Route::post('/account/kyc', [KycSubmissionController::class, 'submit'])->middleware('throttle:3,1')->name('account.kyc.submit');
    Route::get('/account/tiers', [AccountTierController::class, 'index'])->name('account.tiers');
    Route::post('/account/tiers/upgrade', [AccountTierController::class, 'requestUpgrade'])->middleware('throttle:3,1')->name('account.tiers.upgrade');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:5,1')->name('profile.password.update');
    Route::get('/dashboard', [DashboardController::class, 'customer'])->name('dashboard');
    Route::get('/services/education', [EducationServiceController::class, 'index'])->name('education-services.index');
    Route::get('/services/education/orders/{order:public_id}/delivery', [EducationServiceController::class, 'delivery'])
        ->middleware('throttle:10,1')->name('education-services.delivery');
    Route::get('/service-requests/vtu-website', [VtuWebsiteRequestController::class, 'index'])->name('vtu-website-requests.index');
    Route::post('/service-requests/vtu-website', [VtuWebsiteRequestController::class, 'store'])->middleware('throttle:5,1')->name('vtu-website-requests.store');
    Route::get('/security/transaction-pin', [TransactionPinController::class, 'show'])->name('security.transaction-pin');
    Route::put('/security/transaction-pin', [TransactionPinController::class, 'update'])->middleware('throttle:5,1')->name('security.transaction-pin.update');
    Route::get('/wallet/transfer', fn () => Inertia::render('Wallet/Transfer'))->name('wallet.transfer');\n    Route::get('/wallet/transfers/history', [PeerWalletTransferController::class, 'index'])->middleware('throttle:30,1')->name('wallet.transfers.history');\n    Route::post('/wallet/transfers', [PeerWalletTransferController::class, 'store'])->middleware('throttle:5,1')->name('wallet.transfers.store');
    Route::get('/account/referrals', fn () => Inertia::render('Services/ComingSoon', ['serviceName' => 'Refer & Earn']))->name('account.referrals');
    Route::get('/services/coming-soon/{feature}', function (string $feature) {
        $features = [
            'internet' => 'Internet bills',
            'water-bill' => 'Water Bill',
            'betting' => 'Betting',
            'education' => 'Education services',
            'airtime-to-cash' => 'Airtime to Cash',
            'e-pin' => 'E-PIN',
            'bulk-sms' => 'Bulk SMS',
            'gift-cards' => 'Gift Cards',
            'flight' => 'Flight booking',
            'gadget-catalogue' => 'Shop Gadgets',
        ];
        abort_unless(isset($features[$feature]), 404);
        return Inertia::render('Services/ComingSoon', ['serviceName' => $features[$feature]]);
    })->name('services.coming-soon');
    Route::get('/services', function (IntegrationSettings $settings) {
    $manualDepositFeature = PlatformFeatureControl::query()->where('feature_key', 'service.manual_deposit')->value('is_enabled');
    return Inertia::render('Services/Index', ['availability' => [
        'airtimeData' => filter_var($settings->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN),
        'manualDeposit' => filter_var($settings->get('manual_deposit.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && $manualDepositFeature !== null && (bool) $manualDepositFeature,
    ]]);
})->name('services.index');
    Route::get('/customer/dashboard', [DashboardController::class, 'customerPreview'])->name('customer.dashboard');
    Route::get('/admin/dashboard', [DashboardController::class, 'admin'])
        ->middleware('can:admin.dashboard')
        ->name('admin.dashboard');
});

Route::get('/wallet/funding/return', [PaymentFundingController::class, 'returnFromCheckout'])
    ->middleware('throttle:30,1')
    ->name('wallet.funding.return');

Route::middleware('auth')->get('/wallet/manual-deposits', [ManualDepositController::class, 'index'])->name('wallet.manual-deposits.index');
Route::middleware(['auth', 'throttle:5,1'])->post('/wallet/manual-deposits', [ManualDepositController::class, 'submit'])->name('wallet.manual-deposits.submit');

Route::middleware('auth')->post('/wallet/funding-intents', [PaymentFundingController::class, 'createIntent'])
    ->middleware('throttle:10,1')
    ->name('wallet.funding-intents.create');

Route::middleware(['auth', 'throttle:10,1'])->post('/wallet/refund-requests', [PaymentRefundController::class, 'requestRefund'])
    ->name('wallet.refund-requests.create');

Route::middleware(['auth', 'throttle:30,1'])->get('/wallet/refund-requests/{refund}', [PaymentRefundController::class, 'show'])
    ->name('wallet.refund-requests.show');

Route::middleware(['auth', 'can:refunds.approve'])->get('/admin/refunds', [PaymentRefundController::class, 'dashboard'])
    ->name('admin.refunds.dashboard');

Route::middleware(['auth', 'can:refunds.approve', 'throttle:30,1'])->get('/admin/payment-refunds', [PaymentRefundController::class, 'index'])
    ->name('admin.payment-refunds.index');

Route::middleware(['auth', 'can:refunds.approve', 'throttle:20,1'])->post('/admin/payment-refunds/{refund}/approve', [PaymentRefundController::class, 'approveRefund'])
    ->name('admin.payment-refunds.approve');

Route::put('/admin/platform-theme', [PlatformSettingsController::class, 'updateTheme'])->middleware(['auth', 'can:admin.dashboard', 'throttle:10,1'])->name('admin.platform-theme.update');

Route::middleware(['auth', 'can:staff.permissions.manage'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/search', GlobalSearchController::class)->middleware('throttle:30,1')->name('search');
    Route::get('/support', [SupportTicketOperationsController::class, 'index'])->middleware('throttle:30,1')->name('support.index');
    Route::get('/vtu-website-requests', [VtuWebsiteRequestController::class, 'adminIndex'])->middleware('throttle:30,1')->name('vtu-website-requests.index');
    Route::put('/vtu-website-requests/{websiteRequest}/status', [VtuWebsiteRequestController::class, 'updateStatus'])->middleware('throttle:10,1')->name('vtu-website-requests.status');
    Route::post('/support/{ticket}/reply', [SupportTicketOperationsController::class, 'reply'])->middleware('throttle:10,1')->name('support.reply');
    Route::put('/support/{ticket}/status', [SupportTicketOperationsController::class, 'updateStatus'])->middleware('throttle:20,1')->name('support.status');
    Route::get('/feature-controls', [FeatureControlController::class, 'index'])->name('feature-controls.index');
    Route::put('/feature-controls/{featureKey}', [FeatureControlController::class, 'update'])->middleware('throttle:20,1')->name('feature-controls.update');
    Route::get('/security-events', [SecurityEventsController::class, 'index'])->middleware('throttle:30,1')->name('security-events');
    Route::get('/user-levels', [UserLevelPolicyController::class, 'index'])->name('user-levels.index');
    Route::get('/user-level-upgrade-requests', [UserLevelUpgradeReviewController::class, 'index'])->name('user-level-upgrade-requests.index');
    Route::post('/user-level-upgrade-requests/{upgradeRequest}/review', [UserLevelUpgradeReviewController::class, 'review'])->middleware('throttle:10,1')->name('user-level-upgrade-requests.review');
    Route::put('/user-levels/{level}', [UserLevelPolicyController::class, 'update'])->middleware('throttle:20,1')->name('user-levels.update');
    Route::get('/users', [UserManagementController::class, 'index'])->middleware('throttle:60,1')->name('users.index');
    Route::get('/kyc-reviews', [KycSubmissionController::class, 'adminIndex'])->middleware('throttle:30,1')->name('kyc-reviews.index');
    Route::get('/kyc-reviews/{submission}/evidence', [KycSubmissionController::class, 'evidence'])->middleware('throttle:10,1')->name('kyc-reviews.evidence');
    Route::post('/kyc-reviews/{submission}/review', [KycSubmissionController::class, 'review'])->middleware('throttle:10,1')->name('kyc-reviews.review');
    Route::put('/users/{user}/classification', [UserManagementController::class, 'update'])->middleware('throttle:20,1')->name('users.classification.update');
    Route::put('/users/{user}/status', [UserManagementController::class, 'updateStatus'])->middleware('throttle:10,1')->name('users.status.update');
    Route::get('/support-pages', [HelpPageController::class, 'adminEdit'])->name('support-pages.edit');
    Route::put('/support-pages', [HelpPageController::class, 'adminUpdate'])->middleware('throttle:10,1')->name('support-pages.update');
    Route::get('/platform-settings', [PlatformSettingsController::class, 'edit'])->name('platform-settings');
    Route::put('/platform-settings', [PlatformSettingsController::class, 'update'])->middleware('throttle:10,1')->name('platform-settings.update');
    Route::post('/branding-assets', [BrandingAssetsController::class, 'update'])->middleware('throttle:10,1')->name('branding-assets.update');
    Route::get('/manual-deposits', [ManualDepositController::class, 'adminIndex'])->middleware('throttle:30,1')->name('manual-deposits.index');
    Route::get('/manual-deposits/settings', [ManualDepositSettingsController::class, 'edit'])->name('manual-deposits.settings');
    Route::put('/manual-deposits/settings', [ManualDepositSettingsController::class, 'update'])->middleware('throttle:10,1')->name('manual-deposits.settings.update');
    Route::get('/manual-deposits/{publicId}/evidence', [ManualDepositController::class, 'evidence'])->middleware('throttle:20,1')->name('manual-deposits.evidence');
    Route::post('/manual-deposits/{publicId}/review', [ManualDepositController::class, 'review'])->middleware('throttle:10,1')->name('manual-deposits.review');
    Route::get('/payment-gateway', [IntegrationSettingsController::class, 'paymentGateway'])->name('payment-gateway');
    Route::put('/payment-gateway', [IntegrationSettingsController::class, 'updatePaymentGateway'])->name('payment-gateway.update');
    Route::get('/storefront-commissions', [StorefrontCommissionController::class, 'index'])->middleware('throttle:30,1')->name('storefront-commissions.index');
    Route::post('/provider-catalogue/{provider}/import', [\App\Http\Controllers\Admin\ProviderCatalogueImportController::class, 'import'])->whereIn('provider', ['sogo', 'gsubz', 'dataway'])->middleware('throttle:3,1')->name('provider-catalogue.import');
    Route::put('/api-providers/{provider}/credentials', [IntegrationSettingsController::class, 'saveProviderCredentials'])->whereIn('provider', ['sogo', 'gsubz', 'dataway'])->middleware('throttle:10,1')->name('api-providers.credentials');
    Route::get('/api-providers', [IntegrationSettingsController::class, 'apiProviders'])->name('api-providers');
    Route::get('/education-services', [EducationServiceController::class, 'adminIndex'])->name('education-services.index');
    Route::post('/education-services', [EducationServiceController::class, 'store'])->middleware('throttle:10,1')->name('education-services.store');
    Route::put('/education-services/{product}', [EducationServiceController::class, 'update'])->whereNumber('product')->middleware('throttle:10,1')->name('education-services.update');
    Route::put('/api-providers', [IntegrationSettingsController::class, 'updateApiProviders'])->name('api-providers.update');
    Route::put('/storefronts/{storefront}/commission-rate', [StorefrontCommissionController::class, 'updateRate'])->middleware('throttle:10,1')->name('storefronts.commission-rate.update');
    Route::post('/storefront-commissions/{commission}/settle', [StorefrontCommissionController::class, 'settle'])->middleware('throttle:10,1')->name('storefront-commissions.settle');
});

Route::middleware(['auth', 'can:staff.permissions.manage', 'throttle:30,1'])->get('/admin/vtu-orders', [VtuOrderOperationsController::class, 'index'])
    ->name('admin.vtu-orders');

Route::middleware(['auth', 'can:staff.permissions.manage', 'throttle:10,1'])->post('/admin/vtu-orders/{publicId}/reconcile', [VtuOrderOperationsController::class, 'reconcile'])
    ->where('publicId', '[0-9a-fA-F-]{36}')
    ->name('admin.vtu-orders.reconcile');

Route::middleware(['auth', 'can:staff.permissions.manage'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
    Route::get('/', [StaffAccessController::class, 'dashboard'])->name('index');
    Route::get('/users', [StaffAccessController::class, 'users'])->middleware('throttle:60,1')->name('users');
    Route::get('/permission-audits', [StaffAccessController::class, 'audits'])->middleware('throttle:30,1')->name('audits');
    Route::post('/users/{user}/permissions', [StaffAccessController::class, 'grant'])->middleware('throttle:20,1')->name('permissions.grant');
    Route::delete('/users/{user}/permissions', [StaffAccessController::class, 'revoke'])->middleware('throttle:20,1')->name('permissions.revoke');
});

Route::middleware(['auth', 'throttle:60,1'])->prefix('wallet/vtu')->name('wallet.vtu.')->group(function (): void {
    Route::post('/verify-biller', [VtuBillerVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('verify-biller');
    // Each service has its own purchase screen; the legacy entry opens airtime.
    Route::get('/', fn () => redirect()->route('wallet.vtu.airtime'))->name('index');
    Route::get('/airtime', fn () => Inertia::render('Wallet/VtuPurchase', ['serviceType' => 'airtime']))->name('airtime');
    Route::get('/data', fn () => Inertia::render('Wallet/VtuPurchase', ['serviceType' => 'data']))->name('data');
    Route::get('/electricity', fn () => Inertia::render('Wallet/VtuPurchase', ['serviceType' => 'electricity-bill']))->name('electricity');
    Route::get('/tv', fn () => Inertia::render('Wallet/VtuPurchase', ['serviceType' => 'tv-subscription']))->name('tv');
    Route::get('/products', [VtuOrderController::class, 'products'])->name('products');
    Route::post('/orders', [VtuOrderController::class, 'purchase'])->middleware('throttle:10,1')->name('orders.create');
    Route::post('/orders/bulk', [VtuOrderController::class, 'bulkPurchase'])->middleware('throttle:5,1')->name('orders.bulk');
    Route::get('/orders/recover', [VtuOrderController::class, 'recover'])->middleware('throttle:30,1')->name('orders.recover');
    Route::get('/orders/{publicId}', [VtuOrderController::class, 'show'])->name('orders.show');
});


Route::middleware(['auth', 'can:staff.permissions.manage'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/vtu-products', [\App\Http\Controllers\Admin\VtuProductCatalogueController::class, 'index'])->name('vtu-products.index');
    Route::post('/vtu-products/sync', [\App\Http\Controllers\Admin\VtuProductCatalogueController::class, 'sync'])->middleware('throttle:3,1')->name('vtu-products.sync');
    Route::post('/vtu-products', [\App\Http\Controllers\Admin\VtuProductCatalogueController::class, 'store'])->middleware('throttle:20,1')->name('vtu-products.store');
    Route::put('/vtu-products/{product}', [\App\Http\Controllers\Admin\VtuProductCatalogueController::class, 'update'])->whereNumber('product')->middleware('throttle:20,1')->name('vtu-products.update');
});


Route::middleware(['auth', 'throttle:30,1'])->get('/services/streaming-subscriptions', [StreamingSubscriptionController::class, 'index'])
    ->name('streaming-subscriptions.index');

Route::middleware(['auth', 'can:staff.permissions.manage', 'throttle:30,1'])->get('/admin/streaming-subscriptions', [StreamingSubscriptionController::class, 'adminIndex'])
    ->name('admin.streaming-subscriptions.index');

Route::middleware(['auth', 'can:staff.permissions.manage', 'throttle:10,1'])->post('/admin/streaming-subscriptions', [StreamingSubscriptionController::class, 'store'])
    ->name('admin.streaming-subscriptions.store');

Route::middleware(['auth', 'can:staff.permissions.manage', 'throttle:10,1'])->put('/admin/streaming-subscriptions/{product}', [StreamingSubscriptionController::class, 'update'])
    ->name('admin.streaming-subscriptions.update');
