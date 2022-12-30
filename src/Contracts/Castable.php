<?php


namespace AhmetAksoy\EloquentCastable\Contracts;


interface Castable
{
    /**
     * Get the name of the caster class to use when casting from / to this cast target.
     *
     * @param array $arguments
     * @return string
     * @return string|CastsAttributes|CastsInboundAttributes
     */
    public static function castUsing(array $arguments);
}
