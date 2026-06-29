<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;

class Template extends Model
{


    protected $table = 'templates';
    protected $fillable = ['slug', 'locale', 'name', 'title', 'short_description', 'description', 'meta_title', 'meta_description'];

    private function cleanHtml($html) {
        $html = htmlspecialchars_decode($html);
        $html = preg_replace('/\s+/', ' ', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        $html = trim($html);
        return $html;
    }

    public function getRenderedTitle($product)
    {
        $variables = $product->getVariables($this->locale);

        return $this->cleanHtml(Blade::render($this->title, $variables));

    }

    public function getRenderedMetaTitle($product)
    {
        $variables = $product->getVariables($this->locale);

        return $this->cleanHtml(Blade::render($this->meta_title, $variables));

    }
    public function getRenderedDescription($product)
    {
        $variables = $product->getVariables($this->locale);

        return $this->cleanHtml(Blade::render($this->description, $variables));

    }
    public function getRenderedMetaDescription($product)
    {
        $variables = $product->getVariables($this->locale);

        return $this->cleanHtml(Blade::render($this->meta_description, $variables));

    }
    public function getRenderedShortDescription($product)
    {
        $variables = $product->getVariables($this->locale);

        return $this->cleanHtml(Blade::render($this->short_description, $variables));

    }
}
