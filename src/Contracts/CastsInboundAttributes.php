<?php


namespace AhmetAksoy\EloquentCastable\Contracts;

use \Illuminate\Database\Eloquent\Model;

interface CastsInboundAttributes
{
    /**
     * Transform the attribute to its underlying model values.
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array $attributes
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes);
}
