<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model {


    protected $table = 'sources';
    protected $fillable = ['name', 'service_class', 'options', 'enabled'];

    protected $casts = [
        'options' => 'array',
        'enabled' => 'boolean',
    ];
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function synchronize()
    {
        $class_name = 'App\\Sources\\' . $this->service_class;
        dump($class_name, class_exists($class_name));
        if(class_exists($class_name)){

            $class = new $class_name($this);
            $class->synchronize();
        }
    }
}
