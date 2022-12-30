<?php


namespace AhmetAksoy\EloquentCastable\Concerns;


use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use AhmetAksoy\EloquentCastable\Contracts\Castable;
use AhmetAksoy\EloquentCastable\Contracts\CastsInboundAttributes;
use AhmetAksoy\EloquentCastable\InvalidCastException;

trait HasAttributes
{
    /**
     * The built-in, primitive cast types supported by Eloquent.
     *
     * @var string[]
     */
    protected static array $primitiveCastTypes = [
        'array',
        'bool',
        'boolean',
        'collection',
        'custom_datetime',
        'date',
        'datetime',
        'decimal',
        'double',
        'encrypted',
        'encrypted:array',
        'encrypted:collection',
        'encrypted:json',
        'encrypted:object',
        'float',
        'int',
        'integer',
        'json',
        'object',
        'real',
        'string',
        'timestamp',
    ];

    /**
     * The attributes that have been cast using custom classes.
     *
     * @var array
     */
    protected array $classCastCache = [];

    /**
     * The encrypter instance that is used to encrypt attributes.
     *
     * @var Encrypter
     */
    public static Encrypter $encrypter;

    /**
     * Determine whether a value is JSON castable for inbound manipulation.
     *
     * @param string $key
     * @return bool
     */
    protected function isJsonCastable($key)
    {
        return $this->hasCast($key, ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * @param $key
     * @return bool
     */
    protected function isEncryptedCastable($key)
    {
        return $this->hasCast($key, ['encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object']);
    }

    /**
     * Set a given JSON attribute on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function fillJsonAttribute($key, $value)
    {
        [$key, $path] = explode('->', $key, 2);

        $value = $this->asJson($this->getArrayAttributeWithValue(
            $path, $key, $value
        ));

        $this->attributes[$key] = $this->isEncryptedCastable($key)
            ? $this->castAttributeAsEncryptedString($value)
            : $value;

        return $this;
    }

    /**
     * @param $key
     * @return bool
     */
    protected function isClassCastable($key)
    {
        if (!array_key_exists($key, $this->getCasts())) {
            return false;
        }

        $castType = $this->parseCasterClass($this->getCasts()[$key]);

        if (in_array($castType, static::$primitiveCastTypes)) {
            return false;
        }

        if (class_exists($castType)) {
            return true;
        }

        throw new InvalidCastException($this->getModel(), $key, $castType);
    }

    /**
     * @param string $class
     * @return string
     */
    protected function parseCasterClass(string $class): string
    {
        return strpos($class, ':') === false ? $class : explode(':', $class, 2)[0];
    }

    /**
     * @param string $value
     * @return mixed
     */
    public function fromEncryptedString(string $value)
    {
        return (static::$encrypter ?? Crypt::getFacadeRoot())->decrypt($value, false);
    }

    protected function castAttributeAsEncryptedString($value)
    {
        return (static::$encrypter ?? Crypt::getFacadeRoot())->encrypt($value, false);
    }

    /**
     * Set a given attribute on the model.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        // First we will check for the presence of a mutator for the set operation
        // which simply lets the developers tweak the attribute as it is set on
        // the model, such as "json_encoding" an listing of data for storage.
        if ($this->hasSetMutator($key)) {
            return $this->setMutatedAttributeValue($key, $value);
        }

        // If an attribute is listed as a "date", we'll convert it from a DateTime
        // instance into a form proper for storage on the database tables using
        // the connection grammar's date format. We will auto set the values.
        elseif ($value && $this->isDateAttribute($key)) {
            $value = $this->fromDateTime($value);
        }

        if ($this->isClassCastable($key)) {
            $this->setClassCastableAttribute($key, $value);

            return $this;
        }

        if (!is_null($value) && $this->isJsonCastable($key)) {
            $value = $this->castAttributeAsJson($key, $value);
        }

        // If this attribute contains a JSON ->, we'll set the proper value in the
        // attribute's underlying array. This takes care of properly nesting an
        // attribute in the array's value in the case of deeply nested items.
        if (Str::contains($key, '->')) {
            return $this->fillJsonAttribute($key, $value);
        }

        if (!is_null($value) && $this->isEncryptedCastable($key)) {
            $value = $this->castAttributeAsEncryptedString($value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Set the value of a class castable attribute.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    protected function setClassCastableAttribute(string $key, $value)
    {
        $caster = $this->resolveCasterClass($key);

        if (is_null($value)) {
            $this->attributes = array_merge($this->attributes, array_map(
                function () {
                },
                $this->normalizeCastClassResponse($key, $caster->set(
                    $this, $key, $this->{$key}, $this->attributes
                ))
            ));
        } else {
            $this->attributes = array_merge(
                $this->attributes,
                $this->normalizeCastClassResponse($key, $caster->set(
                    $this, $key, $value, $this->attributes
                ))
            );
        }

        if ($caster instanceof CastsInboundAttributes || !is_object($value)) {
            unset($this->classCastCache[$key]);
        } else {
            $this->classCastCache[$key] = $value;
        }
    }

    /**
     * Normalize the response from a custom class caster.
     *
     * @param string $key
     * @param mixed $value
     * @return array
     */
    protected function normalizeCastClassResponse(string $key, $value)
    {
        return is_array($value) ? $value : [$key => $value];
    }

    /**
     * Resolve the custom caster class for a given key.
     *
     * @param string $key
     * @return mixed
     */
    protected function resolveCasterClass(string $key)
    {
        $castType = $this->getCasts()[$key];

        $arguments = [];

        if (is_string($castType) && strpos($castType, ':') !== false) {
            $segments = explode(':', $castType, 2);

            $castType = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castType, Castable::class)) {
            $castType = $castType::castUsing($arguments);
        }

        if (is_object($castType)) {
            return $castType;
        }

        return new $castType(...$arguments);
    }

    /**
     * Cast the given attribute using a custom cast class.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function getClassCastableAttributeValue(string $key, $value)
    {
        if (isset($this->classCastCache[$key])) {
            return $this->classCastCache[$key];
        } else {
            $caster = $this->resolveCasterClass($key);

            $value = $caster instanceof CastsInboundAttributes
                ? $value
                : $caster->get($this, $key, $value, $this->attributes);

            if ($caster instanceof CastsInboundAttributes || !is_object($value)) {
                unset($this->classCastCache[$key]);
            } else {
                $this->classCastCache[$key] = $value;
            }

            return $value;
        }
    }

    /**
     * @param string $key
     * @param string $type
     */
    protected function setCastType(string $key, string $type)
    {
        $this->casts[$key] = $type;
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (in_array($castType, static::$primitiveCastTypes)) {
            if (is_null($value)) {
                return null;
            }

            if ($this->isEncryptedCastable($key)) {
                $value = $this->fromEncryptedString($value);

                $this->setCastType($key, Str::after($castType, 'encrypted:'));
            }
            $value = parent::castAttribute($key, $value);
            $this->setCastType($key, $castType);
        } else {
            if ($this->isClassCastable($key)) {
                $value = $this->getClassCastableAttributeValue($key, $value);
            }
        }
        return $value;
    }
}
