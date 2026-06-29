<?php

namespace App\Providers;

use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\IntegrationProduct;
use App\Models\Product;
use App\Observers\IntegrationProductTrackingObserver;
use App\Observers\TranslationTrackingObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tracking ręcznych edycji tłumaczeń → wpisy w `translation_overrides` chronią przed nadpisywaniem przez automaty (Sumpguard sync itd.).
        Product::observe(TranslationTrackingObserver::class);
        Category::observe(TranslationTrackingObserver::class);
        AttributeValue::observe(TranslationTrackingObserver::class);
        IntegrationProduct::observe(IntegrationProductTrackingObserver::class);
    }
}
