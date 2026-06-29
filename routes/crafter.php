<?php

use App\Http\Controllers\Admin\AdminUser\AdminUserController;
use App\Http\Controllers\Admin\AdminUser\AaminUserInvitationController;
use App\Http\Controllers\Admin\AdminUser\MyPasswordController;
use App\Http\Controllers\Admin\AdminUser\MyProfileController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Admin\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Admin\Auth\NewPasswordController;
use App\Http\Controllers\Admin\Auth\PasswordResetLinkController;
use App\Http\Controllers\Admin\Auth\RegisteredUserController;
use App\Http\Controllers\Admin\Auth\VerifyEmailController;
use App\Http\Controllers\Admin\FileUploadController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\Media\MediaController;
use App\Http\Controllers\Admin\Permissions\PermissionController;
use App\Http\Controllers\Admin\Roles\RoleController;
use App\Http\Controllers\Admin\Settings\SettingsController;
use App\Http\Controllers\Admin\TagsController;
use App\Http\Controllers\Admin\Translations\TranslationsController;
use App\Http\Controllers\Admin\UnassignedMediaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::name('crafter.')->middleware('crafter.base')->prefix('admin')->group(function () {
    Route::middleware('crafter.guest')->group(function () {
        if (config('crafter.self_registration.enabled', false)) {
            Route::get('register', [RegisteredUserController::class, 'create'])
                ->name('register');

            Route::post('register', [RegisteredUserController::class, 'store'])
                ->name('register.store');
        }

        Route::get('login', [AuthenticatedSessionController::class, 'create'])
            ->name('login');

        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware(['throttle:6,1'])
            ->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->name('password.update');

        Route::get('invite-user/{email}', [AaminUserInvitationController::class, 'createInviteAcceptationUser'])->name('invite-user.create');
        Route::post('invite-user', [AaminUserInvitationController::class, 'storeInviteAcceptationUser'])->name('invite-user.store');
    });

    Route::middleware('auth')->group(function () {
        // auth
        Route::get('verify-email', [EmailVerificationPromptController::class, '__invoke'])
            ->name('verification.notice');

        Route::get('verify-email/{id}/{hash}', [VerifyEmailController::class, '__invoke'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
            ->name('password.confirm');

        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
            ->name('password.confirm.submit');

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');

        Route::middleware('crafter.verified')->group(function () {
            // upload
            Route::post('upload', [FileUploadController::class, 'upload'])->name('upload');
            Route::post('media-zip', [MediaController::class, 'zip']);
            Route::post('unassigned-media-upload', [UnassignedMediaController::class, 'upload'])->name('unassignedMediaUpload');
            Route::delete('unassigned-media-destroy/{id}', [UnassignedMediaController::class, 'destroy'])->name('unassignedMediaDestroy');

            // home
            Route::get('/', [HomeController::class, 'index'])
                ->name('home');

            // dashboard
            Route::get('/dashboard', [HomeController::class, 'dashboard'])
                ->name('dashboard');

            // users crud
            Route::delete('admin-users/bulk-destroy', [AdminUserController::class, 'bulkDestroy']);
            Route::resource('admin-users', AdminUserController::class)->parameters([
                'admin-users' => 'adminUser',
            ]);
            Route::post('admin-users/{adminUser}/resend-verification-email', [AdminUserController::class, 'resendEmailVerificationNotification']);
            Route::post('admin-users/bulk-deactivate', [AdminUserController::class, 'bulkDeactivate']);
            Route::post('admin-users/bulk-activate', [AdminUserController::class, 'bulkActivate']);
            Route::get('admin-users/{adminUser}/impersonalLogin', [AdminUserController::class, 'impersonalLogin'])->name('admin-user.impersonalLogin');
            Route::post('admin-users/invite-user', [AaminUserInvitationController::class, 'inviteUser'])->name('admin-user.invite-user');

            // user profile

            Route::get('profile', [MyProfileController::class, 'edit'])->name('admin-users.profile');
            Route::put('profile', [MyProfileController::class, 'update'])->name('admin-users.profile.update');

            Route::get('password', [MyPasswordController::class, 'edit'])->name('admin-users.password');
            Route::put('password', [MyPasswordController::class, 'update'])->name('admin-users.password.update');

            // translations management
            Route::get('translations', [TranslationsController::class, 'index'])->name('translations.index');
            Route::post('translations/rescan', [TranslationsController::class, 'rescan'])->name('translations.rescan');
            Route::get('translations/export', [TranslationsController::class, 'export'])->name('translations.export');
            Route::post('translations/import', [TranslationsController::class, 'import'])->name('translations.import');
            Route::post('translations/import/conflicts', [TranslationsController::class, 'importResolvedConflicts'])->name('translations.import.conflicts');
            Route::post('translations/publish', [TranslationsController::class, 'publish'])->name('translations.publish');
            Route::post('translations/{translation}', [TranslationsController::class, 'update'])->name('translations.update');

            // tags management
            Route::post('tags', [TagsController::class, 'store'])->name('tags.store');

            // media management
            Route::get('media', [MediaController::class, 'index'])->name('media.index');
            Route::get('media/images', [MediaController::class, 'images'])->name('media.images');
            Route::get('media/files', [MediaController::class, 'files'])->name('media.files');
            Route::post('media/update/{media}', [MediaController::class, 'updateMedia'])->name('media.update');

            // permissions management
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
            Route::put('permissions', [PermissionController::class, 'update'])->name('permissions.update');

            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}/update', [RoleController::class, 'update'])->name('roles.update');

            Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');


            Route::get('products', [App\Http\Controllers\Admin\ProductController::class, 'index'])->name('products.index');
            Route::get('products/create', [App\Http\Controllers\Admin\ProductController::class, 'create'])->name('products.create');
            Route::post('products', [App\Http\Controllers\Admin\ProductController::class, 'store'])->name('products.store');
            Route::get('products/export-import', [App\Http\Controllers\Admin\ProductController::class, 'exportImport'])->name('products.export-import');
            Route::get('products/export', [App\Http\Controllers\Admin\ProductController::class, 'export'])->name('products.export');
            Route::post('products/import', [App\Http\Controllers\Admin\ProductController::class, 'import'])->name('products.import');
            Route::get('products/edit/{product}/ai', [App\Http\Controllers\Admin\ProductController::class, 'editAI'])->name('products.edit-ai');
            Route::get('products/edit/{product}', [App\Http\Controllers\Admin\ProductController::class, 'edit'])->name('products.edit');
            Route::match(['put', 'patch'], 'products/{product}', [App\Http\Controllers\Admin\ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [App\Http\Controllers\Admin\ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('products/bulk-destroy', [App\Http\Controllers\Admin\ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');

            Route::get('exchange-rates/nbp', [App\Http\Controllers\Admin\ExchangeRateController::class, 'nbp'])->name('exchange-rates.nbp');

            Route::get('pricelists', [App\Http\Controllers\Admin\PricelistController::class, 'index'])->name('pricelists.index');
            Route::get('pricelists/create', [App\Http\Controllers\Admin\PricelistController::class, 'create'])->name('pricelists.create');
            Route::post('pricelists', [App\Http\Controllers\Admin\PricelistController::class, 'store'])->name('pricelists.store');
            Route::get('pricelists/edit/{pricelist}', [App\Http\Controllers\Admin\PricelistController::class, 'edit'])->name('pricelists.edit');
            Route::post('pricelists/{pricelist}/clone', [App\Http\Controllers\Admin\PricelistController::class, 'clone'])->name('pricelists.clone');
            Route::get('pricelists/{pricelist}/export-csv', [App\Http\Controllers\Admin\PricelistController::class, 'exportCsv'])->name('pricelists.export-csv');
            Route::post('pricelists/{pricelist}/import-csv', [App\Http\Controllers\Admin\PricelistController::class, 'importCsv'])->name('pricelists.import-csv');
            Route::match(['put', 'patch'], 'pricelists/{pricelist}', [App\Http\Controllers\Admin\PricelistController::class, 'update'])->name('pricelists.update');
            Route::delete('pricelists/{pricelist}', [App\Http\Controllers\Admin\PricelistController::class, 'destroy'])->name('pricelists.destroy');
            Route::post('pricelists/bulk-destroy', [App\Http\Controllers\Admin\PricelistController::class, 'bulkDestroy'])->name('pricelists.bulk-destroy');

            Route::get('templates', [App\Http\Controllers\Admin\TemplateController::class, 'index'])->name('templates.index');
            Route::get('templates/create', [App\Http\Controllers\Admin\TemplateController::class, 'create'])->name('templates.create');
            Route::post('templates', [App\Http\Controllers\Admin\TemplateController::class, 'store'])->name('templates.store');
            Route::get('templates/edit/{template}', [App\Http\Controllers\Admin\TemplateController::class, 'edit'])->name('templates.edit');
            Route::get('templates/preview/{template}', [App\Http\Controllers\Admin\TemplateController::class, 'preview'])->name('templates.preview');
            Route::match(['put', 'patch'], 'templates/{template}', [App\Http\Controllers\Admin\TemplateController::class, 'update'])->name('templates.update');
            Route::delete('templates/{template}', [App\Http\Controllers\Admin\TemplateController::class, 'destroy'])->name('templates.destroy');
            Route::post('templates/bulk-destroy', [App\Http\Controllers\Admin\TemplateController::class, 'bulkDestroy'])->name('templates.bulk-destroy');

            Route::get('products', [App\Http\Controllers\Admin\ProductController::class, 'index'])->name('products.index');
            Route::get('products/create', [App\Http\Controllers\Admin\ProductController::class, 'create'])->name('products.create');
            Route::post('products', [App\Http\Controllers\Admin\ProductController::class, 'store'])->name('products.store');
            Route::get('products/export', [App\Http\Controllers\Admin\ProductController::class, 'export'])->name('products.export');
            Route::get('products/edit/{product}', [App\Http\Controllers\Admin\ProductController::class, 'edit'])->name('products.edit');
            Route::match(['put', 'patch'], 'products/{product}', [App\Http\Controllers\Admin\ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [App\Http\Controllers\Admin\ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('products/bulk-destroy', [App\Http\Controllers\Admin\ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');

            Route::get('integrations', [App\Http\Controllers\Admin\IntegrationController::class, 'index'])->name('integrations.index');
            // --- connectors-package: status sync (IntegrationSyncLogController) ---
            Route::get('integrations/status', [App\Http\Controllers\Admin\IntegrationSyncLogController::class, 'index'])->name('integrations.status');
            Route::get('integrations/status/json', [App\Http\Controllers\Admin\IntegrationSyncLogController::class, 'json'])->name('integrations.status.json');
            Route::post('integrations/status/stop-all', [App\Http\Controllers\Admin\IntegrationSyncLogController::class, 'stopAllActive'])->name('integrations.status.stop-all');
            Route::get('integrations/sync/{integration}', [App\Http\Controllers\Admin\IntegrationController::class, 'sync'])->name('integrations.sync');
            Route::post('integrations/sync/{integration}/{connector}', [App\Http\Controllers\Admin\IntegrationController::class, 'syncConnector'])->name('integrations.sync-connector');
            Route::get('integrations/create', [App\Http\Controllers\Admin\IntegrationController::class, 'create'])->name('integrations.create');
            Route::post('integrations', [App\Http\Controllers\Admin\IntegrationController::class, 'store'])->name('integrations.store');
            Route::get('integrations/edit/{integration}', [App\Http\Controllers\Admin\IntegrationController::class, 'edit'])->name('integrations.edit');
            Route::match(['put', 'patch'], 'integrations/{integration}', [App\Http\Controllers\Admin\IntegrationController::class, 'update'])->name('integrations.update');
            Route::delete('integrations/{integration}', [App\Http\Controllers\Admin\IntegrationController::class, 'destroy'])->name('integrations.destroy');
            Route::post('integrations/bulk-destroy', [App\Http\Controllers\Admin\IntegrationController::class, 'bulkDestroy'])->name('integrations.bulk-destroy');

            Route::prefix('integrations')->group(function () {

                Route::get('{integration}/products', [App\Http\Controllers\Admin\IntegrationProductController::class, 'index'])->name('integration-products.index');
//                Route::get('{integration}/products/create', [App\Http\Controllers\Admin\IntegrationProductController::class, 'create'])->name('integration-products.create');
//                Route::post('{integration}/products', [App\Http\Controllers\Admin\IntegrationProductController::class, 'store'])->name('integration-products.store');
                Route::get('{integration}/products/export', [App\Http\Controllers\Admin\IntegrationProductController::class, 'export'])->name('integration-products.export');
                Route::get('{integration}/products/export-csv', [App\Http\Controllers\Admin\IntegrationProductController::class, 'exportCsv'])->name('integration-products.export-csv');
                Route::post('{integration}/products/import-csv', [App\Http\Controllers\Admin\IntegrationProductController::class, 'importCsv'])->name('integration-products.import-csv');
//                Route::get('{integration}/products/edit/{integrationProduct}', [App\Http\Controllers\Admin\IntegrationProductController::class, 'edit'])->name('integration-products.edit');
                Route::match(['put', 'patch'], '{integration}/products', [App\Http\Controllers\Admin\IntegrationProductController::class, 'update'])->name('integration-products.update');
//                Route::delete('{integration}/products/{integrationProduct}', [App\Http\Controllers\Admin\IntegrationProductController::class, 'destroy'])->name('integration-products.destroy');
//                Route::post('{integration}/products/bulk-destroy', [App\Http\Controllers\Admin\IntegrationProductController::class, 'bulkDestroy'])->name('integration-products.bulk-destroy');
            });


                Route::get('sources', [App\Http\Controllers\Admin\SourceController::class, 'index'])->name('sources.index');
                Route::get('sources/create', [App\Http\Controllers\Admin\SourceController::class, 'create'])->name('sources.create');
                Route::post('sources', [App\Http\Controllers\Admin\SourceController::class, 'store'])->name('sources.store');
                Route::get('sources/edit/{source}', [App\Http\Controllers\Admin\SourceController::class, 'edit'])->name('sources.edit');
                Route::match(['put', 'patch'], 'sources/{source}', [App\Http\Controllers\Admin\SourceController::class, 'update'])->name('sources.update');
                Route::delete('sources/{source}', [App\Http\Controllers\Admin\SourceController::class, 'destroy'])->name('sources.destroy');
                Route::post('sources/bulk-destroy', [App\Http\Controllers\Admin\SourceController::class, 'bulkDestroy'])->name('sources.bulk-destroy');

                Route::get('attributes', [App\Http\Controllers\Admin\AttributeController::class, 'index'])->name('attributes.index');
                Route::get('attributes/create', [App\Http\Controllers\Admin\AttributeController::class, 'create'])->name('attributes.create');
                Route::post('attributes', [App\Http\Controllers\Admin\AttributeController::class, 'store'])->name('attributes.store');
                Route::post('attributes/update-order', [App\Http\Controllers\Admin\AttributeController::class, 'updateOrder'])->name('attributes.update-order');
                Route::get('attributes/edit/{attribute}', [App\Http\Controllers\Admin\AttributeController::class, 'edit'])->name('attributes.edit');
                Route::match(['put', 'patch'], 'attributes/{attribute}', [App\Http\Controllers\Admin\AttributeController::class, 'update'])->name('attributes.update');
                Route::delete('attributes/{attribute}', [App\Http\Controllers\Admin\AttributeController::class, 'destroy'])->name('attributes.destroy');
                Route::post('attributes/bulk-destroy', [App\Http\Controllers\Admin\AttributeController::class, 'bulkDestroy'])->name('attributes.bulk-destroy');

                Route::get('attribute-values', [App\Http\Controllers\Admin\AttributeValueController::class, 'index'])->name('attribute-values.index');
                Route::get('attribute-values/create', [App\Http\Controllers\Admin\AttributeValueController::class, 'create'])->name('attribute-values.create');
                Route::post('attribute-values', [App\Http\Controllers\Admin\AttributeValueController::class, 'store'])->name('attribute-values.store');
                Route::get('attribute-values/edit/{attributeValue}', [App\Http\Controllers\Admin\AttributeValueController::class, 'edit'])->name('attribute-values.edit');
                Route::match(['put', 'patch'], 'attribute-values/{attributeValue}', [App\Http\Controllers\Admin\AttributeValueController::class, 'update'])->name('attribute-values.update');
                Route::delete('attribute-values/{attributeValue}', [App\Http\Controllers\Admin\AttributeValueController::class, 'destroy'])->name('attribute-values.destroy');
                Route::post('attribute-values/bulk-destroy', [App\Http\Controllers\Admin\AttributeValueController::class, 'bulkDestroy'])->name('attribute-values.bulk-destroy');


        });
    });
});






/* Auto-generated admin routes */
Route::middleware('crafter.base')->prefix('admin')->name('crafter.')->group(function () {
    Route::get('categories', [App\Http\Controllers\Admin\CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/create', [App\Http\Controllers\Admin\CategoryController::class, 'create'])->name('categories.create');
    Route::post('categories', [App\Http\Controllers\Admin\CategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/edit/{category}', [App\Http\Controllers\Admin\CategoryController::class, 'edit'])->name('categories.edit');
    Route::match(['put', 'patch'], 'categories/{category}', [App\Http\Controllers\Admin\CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [App\Http\Controllers\Admin\CategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('categories/bulk-destroy', [App\Http\Controllers\Admin\CategoryController::class, 'bulkDestroy'])->name('categories.bulk-destroy');
});


/* Auto-generated admin routes */
Route::middleware('crafter.base')->prefix('admin')->name('crafter.')->group(function () {
    Route::get('ai-tools', [App\Http\Controllers\Admin\AiToolController::class, 'index'])->name('ai-tools.index');
    Route::get('api/ai-tools', [App\Http\Controllers\Admin\AiToolController::class, 'getTools'])->name('api.ai-tools');
    Route::post('api/ai-tools/execute', [App\Http\Controllers\Admin\AiToolController::class, 'execute'])->name('api.ai-tools.execute');
    Route::get('ai-tools/create', [App\Http\Controllers\Admin\AiToolController::class, 'create'])->name('ai-tools.create');
    Route::post('ai-tools', [App\Http\Controllers\Admin\AiToolController::class, 'store'])->name('ai-tools.store');
    Route::get('ai-tools/edit/{aiTool}', [App\Http\Controllers\Admin\AiToolController::class, 'edit'])->name('ai-tools.edit');
    Route::match(['put', 'patch'], 'ai-tools/{aiTool}', [App\Http\Controllers\Admin\AiToolController::class, 'update'])->name('ai-tools.update');
    Route::delete('ai-tools/{aiTool}', [App\Http\Controllers\Admin\AiToolController::class, 'destroy'])->name('ai-tools.destroy');
    Route::post('ai-tools/bulk-destroy', [App\Http\Controllers\Admin\AiToolController::class, 'bulkDestroy'])->name('ai-tools.bulk-destroy');

    // [argo-mail-pkg] AI Tools — Mail: Administrator (automatyczne zarządzanie pocztą przez AI)
    Route::get('ai-tools/mail/administrator', [App\Http\Controllers\Admin\AiAgents\AiToolsMailController::class, 'administrator'])->name('ai-tools.mail.administrator');
    Route::post('ai-tools/mail/categorize', [App\Http\Controllers\Admin\AiAgents\AiToolsMailController::class, 'categorize'])->name('ai-tools.mail.categorize');
    Route::post('ai-tools/mail/categories', [App\Http\Controllers\Admin\AiAgents\AiToolsMailController::class, 'storeCategory'])->name('ai-tools.mail.categories.store');
    Route::delete('ai-tools/mail/categories/{category}', [App\Http\Controllers\Admin\AiAgents\AiToolsMailController::class, 'destroyCategory'])->name('ai-tools.mail.categories.destroy');
});


/* Translation matrix — matryca tłumaczeń (frazy + per-kanał + review queue) */
Route::middleware('crafter.base')->prefix('admin')->name('crafter.')->group(function () {
    Route::get('translation-phrases', [App\Http\Controllers\Admin\TranslationPhraseController::class, 'index'])->name('translation-phrases.index');
    Route::get('translation-phrases/{translationPhrase}/edit', [App\Http\Controllers\Admin\TranslationPhraseController::class, 'edit'])->name('translation-phrases.edit');
    Route::match(['put', 'patch'], 'translation-phrases/{translationPhrase}', [App\Http\Controllers\Admin\TranslationPhraseController::class, 'update'])->name('translation-phrases.update');
    Route::post('translation-phrases/{translationPhrase}/reapply', [App\Http\Controllers\Admin\TranslationPhraseController::class, 'reapply'])->name('translation-phrases.reapply');

    Route::get('translation-review', [App\Http\Controllers\Admin\TranslationReviewController::class, 'index'])->name('translation-review.index');
    Route::post('translation-review/{product}/auto-translate', [App\Http\Controllers\Admin\TranslationReviewController::class, 'autoTranslate'])->name('translation-review.auto-translate');
    Route::post('translation-review/{product}/approve', [App\Http\Controllers\Admin\TranslationReviewController::class, 'approve'])->name('translation-review.approve');
    Route::post('translation-review/approve-bulk', [App\Http\Controllers\Admin\TranslationReviewController::class, 'approveBulk'])->name('translation-review.approve-bulk');
    Route::post('translation-review/auto-translate-bulk', [App\Http\Controllers\Admin\TranslationReviewController::class, 'autoTranslateBulk'])->name('translation-review.auto-translate-bulk');
});


/* Argo Task — Grupy projektów → Projekty (kontenery) + Taski (karty w kanbanie) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    // Grupy projektów (najwyższy poziom hierarchii)
    Route::get('argo-task/groups/create', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'create'])->name('argo-task.groups.create');
    Route::post('argo-task/groups', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'store'])->name('argo-task.groups.store');
    Route::get('argo-task/groups/{argoProjectGroup}', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'show'])->name('argo-task.groups.show');
    Route::get('argo-task/groups/{argoProjectGroup}/edit', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'edit'])->name('argo-task.groups.edit');
    Route::patch('argo-task/groups/{argoProjectGroup}', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'update'])->name('argo-task.groups.update');
    Route::delete('argo-task/groups/{argoProjectGroup}', [App\Http\Controllers\Admin\ArgoProjectGroupController::class, 'destroy'])->name('argo-task.groups.destroy');

    // Projekty (w ramach grupy — create/store nested pod grupę)
    Route::get('argo-task/groups/{argoProjectGroup}/projects/create', [App\Http\Controllers\Admin\ArgoProjectController::class, 'create'])->name('argo-task.projects.create');
    Route::post('argo-task/groups/{argoProjectGroup}/projects', [App\Http\Controllers\Admin\ArgoProjectController::class, 'store'])->name('argo-task.projects.store');
    Route::get('argo-task/projects/{argoProject}', [App\Http\Controllers\Admin\ArgoProjectController::class, 'show'])->name('argo-task.projects.show');
    Route::patch('argo-task/projects/{argoProject}', [App\Http\Controllers\Admin\ArgoProjectController::class, 'update'])->name('argo-task.projects.update');
    Route::put('argo-task/projects/{argoProject}/config', [App\Http\Controllers\Admin\ArgoProjectController::class, 'updateConfig'])->name('argo-task.projects.config');
    Route::delete('argo-task/projects/{argoProject}', [App\Http\Controllers\Admin\ArgoProjectController::class, 'destroy'])->name('argo-task.projects.destroy');

    // Taski (karty kanbanu)
    Route::get('argo-task/tasks/{argoTask}', [App\Http\Controllers\Admin\ArgoTaskController::class, 'show'])->name('argo-task.tasks.show');
    Route::post('argo-task/projects/{argoProject}/tasks', [App\Http\Controllers\Admin\ArgoTaskController::class, 'store'])->name('argo-task.tasks.store');
    Route::patch('argo-task/tasks/{argoTask}', [App\Http\Controllers\Admin\ArgoTaskController::class, 'update'])->name('argo-task.tasks.update');
    Route::delete('argo-task/tasks/{argoTask}', [App\Http\Controllers\Admin\ArgoTaskController::class, 'destroy'])->name('argo-task.tasks.destroy');
    Route::post('argo-task/tasks/{argoTask}/move', [App\Http\Controllers\Admin\ArgoTaskController::class, 'move'])->name('argo-task.tasks.move');

    // Workspace editor — content auto-save
    Route::patch('argo-task/tasks/{argoTask}/content', [App\Http\Controllers\Admin\ArgoTaskController::class, 'updateContent'])->name('argo-task.tasks.content');

    // Assignees (pivot)
    Route::post('argo-task/tasks/{argoTask}/assignees', [App\Http\Controllers\Admin\ArgoTaskAssigneeController::class, 'store'])->name('argo-task.tasks.assignees.store');
    Route::delete('argo-task/tasks/{argoTask}/assignees/{user}', [App\Http\Controllers\Admin\ArgoTaskAssigneeController::class, 'destroy'])->name('argo-task.tasks.assignees.destroy');

    // Activities
    Route::get('argo-task/tasks/{argoTask}/activities', [App\Http\Controllers\Admin\ArgoTaskActivityController::class, 'index'])->name('argo-task.tasks.activities.index');

    // Attachments
    Route::post('argo-task/tasks/{argoTask}/attachments', [App\Http\Controllers\Admin\ArgoTaskAttachmentController::class, 'store'])->name('argo-task.tasks.attachments.store');
    Route::delete('argo-task/tasks/{argoTask}/attachments/{activity}', [App\Http\Controllers\Admin\ArgoTaskAttachmentController::class, 'destroy'])->name('argo-task.tasks.attachments.destroy');

    // Mentions autocomplete
    Route::get('argo-task/mentions/search', [App\Http\Controllers\Admin\MentionController::class, 'search'])->name('argo-task.mentions.search');

    // Link preview (Open Graph)
    Route::get('argo-task/link-preview', [App\Http\Controllers\Admin\LinkPreviewController::class, 'show'])->name('argo-task.link-preview');

    // Notifications (database channel)
    Route::get('argo-task/notifications', [App\Http\Controllers\Admin\NotificationController::class, 'index'])->name('argo-task.notifications.index');
    Route::post('argo-task/notifications/{id}/read', [App\Http\Controllers\Admin\NotificationController::class, 'markRead'])->name('argo-task.notifications.read');
    Route::post('argo-task/notifications/read-all', [App\Http\Controllers\Admin\NotificationController::class, 'markAllRead'])->name('argo-task.notifications.readAll');
});


/* Argo Connect — Zamówienia & Klienci & Mapa & Integracje (BaseLinker Multi-Base) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    // Zamówienia
    Route::get('connect/orders', [App\Http\Controllers\Admin\Connect\OrderController::class, 'index'])
        ->name('connect.orders.index');
    Route::get('connect/orders/{order}', [App\Http\Controllers\Admin\Connect\OrderController::class, 'show'])
        ->name('connect.orders.show');
    Route::post('connect/orders/{order}/sync', [App\Http\Controllers\Admin\Connect\OrderController::class, 'syncSingle'])
        ->name('connect.orders.sync');

    // Connect → Integracja chatboot (raporty na WhatsApp)
    Route::get('connect/chatbot', [App\Http\Controllers\Admin\Connect\ChatbotController::class, 'index'])
        ->name('connect.chatbot.index');
    Route::put('connect/chatbot/sales', [App\Http\Controllers\Admin\Connect\ChatbotController::class, 'updateSales'])
        ->name('connect.chatbot.sales.update');
    Route::post('connect/chatbot/sales/test', [App\Http\Controllers\Admin\Connect\ChatbotController::class, 'testSales'])
        ->name('connect.chatbot.sales.test');

    // Klienci
    Route::get('connect/customers', [App\Http\Controllers\Admin\Connect\CustomerController::class, 'index'])
        ->name('connect.customers.index');
    Route::get('connect/customers/{customer}', [App\Http\Controllers\Admin\Connect\CustomerController::class, 'show'])
        ->name('connect.customers.show');

    // Mapa (Leaflet + geo_postal_codes)
    Route::get('connect/map', [App\Http\Controllers\Admin\Connect\MapController::class, 'index'])
        ->name('connect.map.index');
    Route::get('connect/map/points', [App\Http\Controllers\Admin\Connect\MapController::class, 'points'])
        ->name('connect.map.points');
    Route::get('connect/map/orders', [App\Http\Controllers\Admin\Connect\MapController::class, 'orders'])
        ->name('connect.map.orders');

    // Integracje Base (CRUD wielokontowy)
    Route::get('connect/integrations/base', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'index'])
        ->name('connect.integrations.base.index');
    Route::get('connect/integrations/base/create', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'create'])
        ->name('connect.integrations.base.create');
    Route::post('connect/integrations/base', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'store'])
        ->name('connect.integrations.base.store');
    Route::get('connect/integrations/base/{base}/edit', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'edit'])
        ->name('connect.integrations.base.edit');
    Route::put('connect/integrations/base/{base}', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'update'])
        ->name('connect.integrations.base.update');
    Route::delete('connect/integrations/base/{base}', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'destroy'])
        ->name('connect.integrations.base.destroy');
    Route::post('connect/integrations/base/test', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'testConnection'])
        ->name('connect.integrations.base.test');
    Route::post('connect/integrations/base/{base}/test', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'testConnection'])
        ->name('connect.integrations.base.test.existing');
    Route::post('connect/integrations/base/{base}/sync', [App\Http\Controllers\Admin\Connect\IntegrationBaseController::class, 'triggerSync'])
        ->name('connect.integrations.base.sync');
});


/* Argo HQ — Koszty + Kasa + Odyssey */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('cost-planner/reports', [App\Http\Controllers\Admin\CostPlannerReportController::class, 'index'])->name('cost-planner.reports.index');
    Route::get('cost-planner/summaries', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'index'])->name('cost-planner.summaries.index');
    Route::post('cost-planner/summaries', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'store'])->name('cost-planner.summaries.store');
    Route::get('cost-planner/summaries/{summaryMonth}', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'show'])->name('cost-planner.summaries.show');
    Route::delete('cost-planner/summaries/{summaryMonth}', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'destroy'])->name('cost-planner.summaries.destroy');
    Route::post('cost-planner/summaries/{summaryMonth}/refresh', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'refresh'])->name('cost-planner.summaries.refresh');
    Route::get('cost-planner/summaries/{summaryMonth}/export', [App\Http\Controllers\Admin\CostPlannerSummaryController::class, 'export'])->name('cost-planner.summaries.export');
    Route::get('kasa', [App\Http\Controllers\Admin\KasaController::class, 'index'])->name('kasa.index');

    // KSeF — faktury per firma (lista + filtry); konfiguracja integracji w Argo Connect
    Route::get('ksef/pareto', [App\Http\Controllers\Admin\KsefController::class, 'pareto'])->name('ksef.pareto');
    Route::get('ksef/bsp', [App\Http\Controllers\Admin\KsefController::class, 'bsp'])->name('ksef.bsp');
    Route::post('ksef/{company}/import', [App\Http\Controllers\Admin\KsefController::class, 'import'])
        ->whereIn('company', ['pareto', 'bsp'])->name('ksef.import');
    Route::patch('ksef/invoices/{ksefInvoice}/category', [App\Http\Controllers\Admin\KsefController::class, 'updateCategory'])->name('ksef.invoices.category');
    Route::get('ksef/invoices/{ksefInvoice}/pdf', [App\Http\Controllers\Admin\KsefController::class, 'pdf'])->name('ksef.invoices.pdf');
    Route::patch('ksef/invoices/{ksefInvoice}/status', [App\Http\Controllers\Admin\KsefController::class, 'updateStatus'])->name('ksef.invoices.status');
    // Ustawienia → kategorie (CRUD)
    Route::post('ksef/{company}/categories', [App\Http\Controllers\Admin\KsefController::class, 'storeCategory'])
        ->whereIn('company', ['pareto', 'bsp'])->name('ksef.categories.store');
    Route::patch('ksef/categories/{ksefCategory}', [App\Http\Controllers\Admin\KsefController::class, 'updateCategoryName'])->name('ksef.categories.update');
    Route::delete('ksef/categories/{ksefCategory}', [App\Http\Controllers\Admin\KsefController::class, 'destroyCategory'])->name('ksef.categories.destroy');
    // Ustawienia → powiadomienia Signal (globalne)
    Route::put('ksef/signal-settings', [App\Http\Controllers\Admin\KsefController::class, 'updateSignalSettings'])->name('ksef.signal.update');
    Route::post('ksef/signal-test', [App\Http\Controllers\Admin\KsefController::class, 'sendSignalTest'])->name('ksef.signal.test');

    // Wyciągi bankowe
    Route::get('bank-statements', [App\Http\Controllers\Admin\BankStatementMonthController::class, 'index'])->name('bank-statements.index');
    Route::post('bank-statements', [App\Http\Controllers\Admin\BankStatementMonthController::class, 'store'])->name('bank-statements.store');
    Route::get('bank-statements/{bankStatementMonth}', [App\Http\Controllers\Admin\BankStatementMonthController::class, 'show'])->name('bank-statements.show');
    Route::delete('bank-statements/{bankStatementMonth}', [App\Http\Controllers\Admin\BankStatementMonthController::class, 'destroy'])->name('bank-statements.destroy');

    Route::patch('bank-statements/items/{bankStatementItem}', [App\Http\Controllers\Admin\BankStatementItemController::class, 'update'])->name('bank-statements.items.update');
    Route::post('bank-statements/items/{bankStatementItem}/match', [App\Http\Controllers\Admin\BankStatementItemController::class, 'match'])->name('bank-statements.items.match');
    Route::delete('bank-statements/items/{bankStatementItem}/match', [App\Http\Controllers\Admin\BankStatementItemController::class, 'unmatch'])->name('bank-statements.items.unmatch');

    // Planer kosztów — ustawienia
    Route::get('cost-planner/settings', [App\Http\Controllers\Admin\CostPlannerSettingsController::class, 'edit'])->name('cost-planner.settings.edit');
    Route::put('cost-planner/settings', [App\Http\Controllers\Admin\CostPlannerSettingsController::class, 'update'])->name('cost-planner.settings.update');

    // Planer kosztów — miesiące
    Route::get('cost-planner', [App\Http\Controllers\Admin\CostPlannerMonthController::class, 'index'])->name('cost-planner.index');
    Route::post('cost-planner', [App\Http\Controllers\Admin\CostPlannerMonthController::class, 'store'])->name('cost-planner.store');
    Route::get('cost-planner/{costPlannerMonth}', [App\Http\Controllers\Admin\CostPlannerMonthController::class, 'show'])->name('cost-planner.show');
    Route::patch('cost-planner/{costPlannerMonth}', [App\Http\Controllers\Admin\CostPlannerMonthController::class, 'update'])->name('cost-planner.update');
    Route::delete('cost-planner/{costPlannerMonth}', [App\Http\Controllers\Admin\CostPlannerMonthController::class, 'destroy'])->name('cost-planner.destroy');

    // Planer kosztów — pozycje
    Route::post('cost-planner/{costPlannerMonth}/items', [App\Http\Controllers\Admin\CostPlannerItemController::class, 'store'])->name('cost-planner.items.store');
    Route::patch('cost-planner/items/{costPlannerItem}', [App\Http\Controllers\Admin\CostPlannerItemController::class, 'update'])->name('cost-planner.items.update');
    Route::delete('cost-planner/items/{costPlannerItem}', [App\Http\Controllers\Admin\CostPlannerItemController::class, 'destroy'])->name('cost-planner.items.destroy');
    Route::post('cost-planner/{costPlannerMonth}/items/reorder', [App\Http\Controllers\Admin\CostPlannerItemController::class, 'reorder'])->name('cost-planner.items.reorder');

    // Koszty Odyssey
    Route::get('odyssey-cost',                              [App\Http\Controllers\Admin\OdysseyCostController::class, 'index'])->name('odyssey-cost.index');
    Route::post('odyssey-cost',                             [App\Http\Controllers\Admin\OdysseyCostController::class, 'store'])->name('odyssey-cost.store');
    Route::get('odyssey-cost/{odysseyCostMonth}',           [App\Http\Controllers\Admin\OdysseyCostController::class, 'show'])->name('odyssey-cost.show');
    Route::delete('odyssey-cost/{odysseyCostMonth}',        [App\Http\Controllers\Admin\OdysseyCostController::class, 'destroy'])->name('odyssey-cost.destroy');
    Route::post('odyssey-cost/{odysseyCostMonth}/refresh',  [App\Http\Controllers\Admin\OdysseyCostController::class, 'refresh'])->name('odyssey-cost.refresh');
    Route::patch('odyssey-cost/entries/{entry}',            [App\Http\Controllers\Admin\OdysseyCostController::class, 'updateEntry'])->name('odyssey-cost.entries.update');
    Route::post('odyssey-cost/{odysseyCostMonth}/payments', [App\Http\Controllers\Admin\OdysseyCostController::class, 'storePayment'])->name('odyssey-cost.payments.store');
    Route::patch('odyssey-cost/payments/{payment}',         [App\Http\Controllers\Admin\OdysseyCostController::class, 'updatePayment'])->name('odyssey-cost.payments.update');
    Route::delete('odyssey-cost/payments/{payment}',        [App\Http\Controllers\Admin\OdysseyCostController::class, 'destroyPayment'])->name('odyssey-cost.payments.destroy');
});


/* ============================================================= */
/* [argo-mail-pkg] Argo Mail — RDZEŃ + Mobile/PWA + SMTP (auto)   */
/* ============================================================= */


/*
 * ARGO MAIL — fragment tras do wklejenia w routes/crafter.php docelowego PIM-a.
 *
 * To jest RDZEŃ modułu (menedżer poczty). Wklej CAŁY blok poniżej tam, gdzie
 * trzymasz inne grupy tras crafter (np. tuż przed sekcją "AI Agent Clusters").
 *
 * Grupa ma własny wrapper middleware — jeśli docelowy PIM używa innego prefixu/nazwy,
 * dopasuj wrapper, ale NIE zmieniaj ścieżek wewnętrznych ('argo-mail/...') ani nazw
 * tras ('argo-mail.*') — front (Vue) odwołuje się do route('crafter.argo-mail.*').
 */

/* Argo Mail — menedżer poczty (agregacja skrzynek IMAP + wysyłka SMTP) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('argo-mail', [App\Http\Controllers\Admin\Mail\MailController::class, 'index'])
        ->name('argo-mail.index');

    // Wiadomość — pełna treść (JSON) + pobieranie załącznika na żądanie
    Route::get('argo-mail/messages/{message}', [App\Http\Controllers\Admin\Mail\MailController::class, 'showMessage'])
        ->name('argo-mail.messages.show');
    Route::get('argo-mail/messages/{message}/thread', [App\Http\Controllers\Admin\Mail\MailController::class, 'showThread'])
        ->name('argo-mail.messages.thread');
    Route::get('argo-mail/messages/{message}/attachments/{attachment}', [App\Http\Controllers\Admin\Mail\MailController::class, 'downloadAttachment'])
        ->name('argo-mail.messages.attachment');

    // Skrzynki (konta) — lista + wpięcie + test połączenia
    Route::get('argo-mail/accounts', [App\Http\Controllers\Admin\Mail\AccountController::class, 'index'])
        ->name('argo-mail.accounts.index');
    Route::get('argo-mail/accounts/create', [App\Http\Controllers\Admin\Mail\AccountController::class, 'create'])
        ->name('argo-mail.accounts.create');
    Route::post('argo-mail/accounts', [App\Http\Controllers\Admin\Mail\AccountController::class, 'store'])
        ->name('argo-mail.accounts.store');
    Route::get('argo-mail/accounts/{account}/edit', [App\Http\Controllers\Admin\Mail\AccountController::class, 'edit'])
        ->name('argo-mail.accounts.edit');
    Route::put('argo-mail/accounts/{account}', [App\Http\Controllers\Admin\Mail\AccountController::class, 'update'])
        ->name('argo-mail.accounts.update');
    Route::delete('argo-mail/accounts/{account}', [App\Http\Controllers\Admin\Mail\AccountController::class, 'destroy'])
        ->name('argo-mail.accounts.destroy');
    Route::post('argo-mail/accounts/test', [App\Http\Controllers\Admin\Mail\AccountController::class, 'test'])
        ->name('argo-mail.accounts.test');
    Route::post('argo-mail/accounts/{account}/test', [App\Http\Controllers\Admin\Mail\AccountController::class, 'test'])
        ->name('argo-mail.accounts.test.existing');
    Route::post('argo-mail/accounts/{account}/sync', [App\Http\Controllers\Admin\Mail\MailController::class, 'syncAccount'])
        ->name('argo-mail.accounts.sync');

    // Ustawienia (taby: Katalogi, Kategorie)
    Route::get('argo-mail/settings', [App\Http\Controllers\Admin\Mail\MailController::class, 'settings'])
        ->name('argo-mail.settings');

    // Katalogi (drzewo do sortowania maili)
    Route::post('argo-mail/catalogs', [App\Http\Controllers\Admin\Mail\CatalogController::class, 'store'])
        ->name('argo-mail.catalogs.store');
    Route::post('argo-mail/catalogs/reorder', [App\Http\Controllers\Admin\Mail\CatalogController::class, 'reorder'])
        ->name('argo-mail.catalogs.reorder');
    Route::post('argo-mail/catalogs/{catalog}/move', [App\Http\Controllers\Admin\Mail\CatalogController::class, 'move'])
        ->name('argo-mail.catalogs.move');
    Route::put('argo-mail/catalogs/{catalog}', [App\Http\Controllers\Admin\Mail\CatalogController::class, 'update'])
        ->name('argo-mail.catalogs.update');
    Route::delete('argo-mail/catalogs/{catalog}', [App\Http\Controllers\Admin\Mail\CatalogController::class, 'destroy'])
        ->name('argo-mail.catalogs.destroy');

    // Przypisanie wiadomości do katalogu
    Route::post('argo-mail/messages/{message}/catalog', [App\Http\Controllers\Admin\Mail\MailController::class, 'assignCatalog'])
        ->name('argo-mail.messages.catalog');
    // Drag & drop maila na katalog → reguła nadawcy + przeniesienie wszystkich jego maili
    Route::post('argo-mail/messages/{message}/file-sender', [App\Http\Controllers\Admin\Mail\MailController::class, 'fileSenderToCatalog'])
        ->name('argo-mail.messages.file-sender');

    // Reguły „nadawca/domena → katalog" (+ wykluczenia ze słowem w temacie) — zakładka Filtry
    Route::post('argo-mail/rules', [App\Http\Controllers\Admin\Mail\SenderRuleController::class, 'store'])
        ->name('argo-mail.rules.store');
    Route::delete('argo-mail/rules/{senderRule}', [App\Http\Controllers\Admin\Mail\SenderRuleController::class, 'destroy'])
        ->name('argo-mail.rules.destroy');

    // Kosz (przenieś / przywróć)
    Route::post('argo-mail/messages/{message}/trash', [App\Http\Controllers\Admin\Mail\MailController::class, 'trashMessage'])
        ->name('argo-mail.messages.trash');

    // Spam (nadawca → spam / nie spam) + zarządzanie listą
    Route::post('argo-mail/messages/{message}/spam', [App\Http\Controllers\Admin\Mail\MailController::class, 'markSpam'])
        ->name('argo-mail.messages.spam');
    Route::post('argo-mail/messages/{message}/unspam', [App\Http\Controllers\Admin\Mail\MailController::class, 'unspamMessage'])
        ->name('argo-mail.messages.unspam');
    Route::post('argo-mail/spam', [App\Http\Controllers\Admin\Mail\MailController::class, 'storeSpamSender'])
        ->name('argo-mail.spam.store');
    Route::delete('argo-mail/spam/{spamSender}', [App\Http\Controllers\Admin\Mail\MailController::class, 'destroySpamSender'])
        ->name('argo-mail.spam.destroy');

    // Wykluczenia z grupowania (wątkowania) — nadawca + opcjonalny fragment tytułu
    Route::post('argo-mail/thread-excludes', [App\Http\Controllers\Admin\Mail\MailController::class, 'storeThreadExclude'])
        ->name('argo-mail.thread-excludes.store');
    Route::delete('argo-mail/thread-excludes/{threadExclude}', [App\Http\Controllers\Admin\Mail\MailController::class, 'destroyThreadExclude'])
        ->name('argo-mail.thread-excludes.destroy');

    // Operacje masowe (multi-select)
    Route::post('argo-mail/messages/bulk', [App\Http\Controllers\Admin\Mail\MailController::class, 'bulk'])
        ->name('argo-mail.messages.bulk');
    Route::post('argo-mail/messages/color', [App\Http\Controllers\Admin\Mail\MailController::class, 'setColor'])
        ->name('argo-mail.messages.color');

    // Przypisanie osoby / kategorii (menu kontekstowe)
    Route::post('argo-mail/messages/{message}/user', [App\Http\Controllers\Admin\Mail\MailController::class, 'assignUser'])
        ->name('argo-mail.messages.user');
    Route::post('argo-mail/messages/{message}/category', [App\Http\Controllers\Admin\Mail\MailController::class, 'assignCategory'])
        ->name('argo-mail.messages.category');

    // Osoby obsługujące pocztę
    Route::post('argo-mail/mail-users', [App\Http\Controllers\Admin\Mail\MailUserController::class, 'store'])
        ->name('argo-mail.mail-users.store');
    Route::put('argo-mail/mail-users/{mailUser}', [App\Http\Controllers\Admin\Mail\MailUserController::class, 'update'])
        ->name('argo-mail.mail-users.update');
    Route::delete('argo-mail/mail-users/{mailUser}', [App\Http\Controllers\Admin\Mail\MailUserController::class, 'destroy'])
        ->name('argo-mail.mail-users.destroy');

    // Wysyłka (nowa / odpowiedź / przekazanie)
    Route::post('argo-mail/send', [App\Http\Controllers\Admin\Mail\MailController::class, 'send'])
        ->name('argo-mail.send');
});


/*
 * ARGO MOBILE (PWA) + WEB PUSH — fragment tras (ROZSZERZENIE, opcjonalne).
 *
 * Wklej CAŁY blok do routes/crafter.php. To shell mobilny (poczta + zadania +
 * powiadomienia) oraz endpointy zapisu subskrypcji push z telefonu.
 *
 * ZALEŻNOŚCI:
 *  - 'm/mail'  → wymaga RDZENIA Argo Mail (App\Models\Mail\Message).
 *  - 'm/tasks' → wymaga modułu ARGO TASK (AdminUser::assignedTasks). Jeśli docelowy
 *                PIM nie ma TASK-a, usuń trasę 'm/tasks' i metodę tasks() z kontrolera,
 *                albo zostaw — zwróci pustą listę.
 *  - push.*    → wymaga pakietu laravel-notification-channels/webpush + tabeli
 *                push_subscriptions + traitu HasPushSubscriptions na modelu AdminUser
 *                (szczegóły w integracja/08-env-blade-trait.md).
 */

/* ARGO Mobile (PWA) — uproszczony shell na telefon: poczta + zadania + powiadomienia */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('m', [App\Http\Controllers\Admin\MobileController::class, 'home'])->name('mobile.home');
    Route::get('m/mail', [App\Http\Controllers\Admin\MobileController::class, 'mail'])->name('mobile.mail');
    Route::get('m/tasks', [App\Http\Controllers\Admin\MobileController::class, 'tasks'])->name('mobile.tasks');
    Route::get('m/notifications', [App\Http\Controllers\Admin\MobileController::class, 'notifications'])->name('mobile.notifications');

    Route::post('m/notifications/{id}/read', [App\Http\Controllers\Admin\MobileController::class, 'markNotificationRead'])->name('mobile.notifications.read');
    Route::post('m/notifications/read-all', [App\Http\Controllers\Admin\MobileController::class, 'markAllNotificationsRead'])->name('mobile.notifications.read-all');

    // Web Push — zapis/usunięcie subskrypcji telefonu
    Route::post('push/subscribe', [App\Http\Controllers\Admin\PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('push/unsubscribe', [App\Http\Controllers\Admin\PushController::class, 'unsubscribe'])->name('push.unsubscribe');
});


/*
 * POCZTA TRANSAKCYJNA (Mail SMTP / Szablony / Logi) — fragment tras (ROZSZERZENIE).
 *
 * To OSOBNY, starszy moduł — NIE jest częścią Argo Mail. Obsługuje konfigurację
 * SMTP wychodzącego PIM-a, szablony maili systemowych i log wysyłki.
 * Dołączaj tylko jeśli docelowy PIM jeszcze nie ma własnej obsługi SMTP.
 *
 * Sidebar używa uprawnienia 'crafter.mail.view' / '...templates.edit' / '...logs.view'.
 */

/* Poczta — SMTP, szablony, logi */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    // SMTP
    Route::get('mail/smtp',        [App\Http\Controllers\Admin\MailController::class, 'smtp'])->name('mail.smtp');
    Route::post('mail/smtp',       [App\Http\Controllers\Admin\MailController::class, 'smtpUpdate'])->name('mail.smtp.update');
    Route::post('mail/smtp/test',  [App\Http\Controllers\Admin\MailController::class, 'smtpTest'])->name('mail.smtp.test');

    // Templates
    Route::get('mail/templates',                       [App\Http\Controllers\Admin\MailController::class, 'templates'])->name('mail.templates');
    Route::get('mail/templates/{template}/edit',       [App\Http\Controllers\Admin\MailController::class, 'templateEdit'])->name('mail.templates.edit');
    Route::put('mail/templates/{template}',            [App\Http\Controllers\Admin\MailController::class, 'templateUpdate'])->name('mail.templates.update');

    // Logs
    Route::get('mail/logs', [App\Http\Controllers\Admin\MailController::class, 'logs'])->name('mail.logs');
});


/* Argo Connect → Integracje → Ebay — monitoring konkurencji (Browse API) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('connect/integrations/ebay', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'index'])
        ->name('connect.integrations.ebay.index');
    Route::put('connect/integrations/ebay', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'update'])
        ->name('connect.integrations.ebay.update');
    Route::post('connect/integrations/ebay/test', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'testConnection'])
        ->name('connect.integrations.ebay.test');
    Route::post('connect/integrations/ebay/sync', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'sync'])
        ->name('connect.integrations.ebay.sync');
    // OAuth user-token (Sell/Trading API — własne oferty + zmiana cen)
    Route::get('connect/integrations/ebay/oauth/connect', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'oauthConnect'])
        ->name('connect.integrations.ebay.oauth.connect');
    Route::get('connect/integrations/ebay/oauth/callback', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'oauthCallback'])
        ->name('connect.integrations.ebay.oauth.callback');
    Route::delete('connect/integrations/ebay/oauth', [App\Http\Controllers\Admin\Connect\IntegrationEbayController::class, 'oauthDisconnect'])
        ->name('connect.integrations.ebay.oauth.disconnect');
});


/* Argo Connect → Integracje → KSeF — poświadczenia integracji per firma (Pareto / BSP) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('connect/integrations/ksef', [App\Http\Controllers\Admin\Connect\IntegrationKsefController::class, 'index'])
        ->name('connect.integrations.ksef.index');
    Route::put('connect/integrations/ksef', [App\Http\Controllers\Admin\Connect\IntegrationKsefController::class, 'update'])
        ->name('connect.integrations.ksef.update');
});


/* Argo Scope → Scrapy → Rumuni — dane monitoringu konkurencji (per kanał) */
Route::middleware(['crafter.base', 'auth', 'crafter.verified'])->prefix('admin')->name('crafter.')->group(function () {
    Route::get('scope/rumuni', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'index'])
        ->name('scope.rumuni.index');
    Route::post('scope/rumuni/sync/{source}', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'sync'])
        ->name('scope.rumuni.sync');
    Route::get('scope/rumuni/product-search', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'searchProducts'])
        ->name('scope.rumuni.product-search');
    Route::post('scope/rumuni/products/{scrapProduct}/assign', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'assignProduct'])
        ->name('scope.rumuni.assign');
    Route::post('scope/rumuni/products/{scrapProduct}/individual', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'setIndividual'])
        ->name('scope.rumuni.individual');
    Route::post('scope/rumuni/excluded', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'setExcluded'])
        ->name('scope.rumuni.excluded');
    Route::post('scope/rumuni/pricelist', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'createPricelist'])
        ->name('scope.rumuni.pricelist');
    Route::post('scope/rumuni/compare', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'setCompare'])
        ->name('scope.rumuni.compare');
    Route::post('scope/rumuni/target', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'setTarget'])
        ->name('scope.rumuni.target');
    Route::post('scope/rumuni/update-all', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'updateAll'])
        ->name('scope.rumuni.update-all');
    Route::post('scope/rumuni/match', [App\Http\Controllers\Admin\Scope\ScopeRumuniController::class, 'matchProducts'])
        ->name('scope.rumuni.match');
});
