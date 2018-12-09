<?php declare(strict_types=1);
namespace Phan\Language\Type;

use ast\Node;
use InvalidArgumentException;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\RecursionDepthException;
use Phan\Language\Context;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\Language\UnionTypeBuilder;

/**
 * Phan's representation for the types `array<string,MyClass>` and `MyClass[]`
 * @see ArrayShapeType for representations of `array{key:MyClass}`
 * @see ArrayType for the representation of `array`
 */
final class GenericArrayType extends ArrayType implements GenericArrayInterface
{
    /** @phan-override */
    const NAME = 'array';

    // In PHP, array keys can be integers or strings. These constants describe all possible combinations of those key types.

    /**
     * No array keys.
     * Array types with this key type Similar to KEY_MIXED, but adding a key type will change the array to the new key
     * instead of staying as KEY_MIXED.
     */
    const KEY_EMPTY  = 0;  // No way to create this type yet.
    /** array keys are integers */
    const KEY_INT    = 1;
    /** array keys are strings */
    const KEY_STRING = 2;
    /** array keys are integers or strings. */
    const KEY_MIXED  = 3;  // i.e. KEY_INT|KEY_STRING

    const KEY_NAMES = [
        self::KEY_EMPTY  => 'empty',
        self::KEY_INT    => 'int',
        self::KEY_STRING => 'string',
        self::KEY_MIXED  => 'mixed',  // treated the same way as int|string
    ];

    /**
     * @var Type
     * The type of every value in this array
     */
    private $element_type;

    /**
     * @var int
     * Enum representing the type of every key in this array
     */
    private $key_type;

    /**
     * @param Type $type
     * The type of every element in this array
     *
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @param int $key_type
     * Corresponds to the type of the array keys. Set this to a GenericArrayType::KEY_* constant.
     *
     * @throws InvalidArgumentException if $key_type is an invalid constant
     */
    protected function __construct(Type $type, bool $is_nullable, int $key_type)
    {
        if ($key_type & ~3) {
            throw new InvalidArgumentException("Invalid key_type $key_type");
        }
        parent::__construct('\\', self::NAME, [], $is_nullable);
        $this->element_type = $type;
        $this->key_type = $key_type;
    }

    /**
     * Returns the key type of this generic array.
     * e.g. for `int[]`, returns self::KEY_MIXED, for `array<string,mixed>`, returns self::KEY_STRING.
     */
    public function getKeyType() : int
    {
        return $this->key_type;
    }

    /**
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @return Type
     * A new type that is a copy of this type but with the
     * given nullability value.
     */
    public function withIsNullable(bool $is_nullable) : Type
    {
        if ($is_nullable === $this->is_nullable) {
            return $this;
        }

        return GenericArrayType::fromElementType(
            $this->element_type,
            $is_nullable,
            $this->key_type
        );
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableType(Type $type) : bool
    {
        if ($type instanceof ArrayType) {
            if ($type instanceof GenericArrayType) {
                if (!$this->genericArrayElementType()
                    ->canCastToType($type->genericArrayElementType())) {
                    return false;
                }
                if ((($this->key_type ?: self::KEY_MIXED) & ($type->key_type ?: self::KEY_MIXED)) === 0) {
                    // Attempting to cast an int key to a string key (or vice versa) is normally invalid.
                    // However, the scalar_array_key_cast config would make any cast of array keys a valid cast.
                    return Config::getValue('scalar_array_key_cast');
                }
                return true;
            } elseif ($type instanceof ArrayShapeType) {
                if ((($this->key_type ?: self::KEY_MIXED) & $type->getKeyType()) === 0 && !Config::getValue('scalar_array_key_cast')) {
                    // Attempting to cast an int key to a string key (or vice versa) is normally invalid.
                    // However, the scalar_array_key_cast config would make any cast of array keys a valid cast.
                    return false;
                }
                return $this->genericArrayElementUnionType()->canCastToUnionType($type->genericArrayElementUnionType());
            }
            return true;
        }

        if (\get_class($type) === IterableType::class) {
            // can cast to Iterable but not Traversable
            return true;
        }
        if ($type instanceof GenericIterableType) {
            return $this->canCastToGenericIterableType($type);
        }

        $d = \strtolower($type->__toString());
        if ($d[0] == '\\') {
            $d = \substr($d, 1);
        }
        if ($d === 'callable') {
            return $this->key_type !== self::KEY_STRING;
        }

        return parent::canCastToNonNullableType($type);
    }

    private function canCastToGenericIterableType(
        GenericIterableType $iterable_type
    ) : bool {
        if (!$this->element_type->asUnionType()->canCastToUnionType($iterable_type->getElementUnionType())) {
            return false;
        }
        // TODO: Account for scalar key casting config
        $key_union_type = self::unionTypeForKeyType($this->key_type);
        if (!$key_union_type->canCastToUnionType($iterable_type->getKeyUnionType())) {
            return false;
        }
        return true;
    }

    /**
     * @param Type $type
     * The element type for an array.
     *
     * @param bool $is_nullable
     * Set to true if the this is a nullable array(e.g. `?($type[])`),
     * else pass false
     *
     * @param int $key_type
     * Corresponds to the type of the array keys. Set this to a GenericArrayType::KEY_* constant.
     *
     * @return GenericArrayType
     * Get a type representing an array of the given type
     */
    public static function fromElementType(
        Type $type,
        bool $is_nullable,
        int $key_type
    ) : GenericArrayType {
        // Make sure we only ever create exactly one
        // object for any unique type
        static $canonical_object_maps = null;

        if ($canonical_object_maps === null) {
            $canonical_object_maps = [];
            for ($i = 0; $i < 8; $i++) {
                $canonical_object_maps[] = new \SplObjectStorage();
            }
        }
        $map_index = $key_type * 2 + ($is_nullable ? 1 : 0);

        $map = $canonical_object_maps[$map_index];

        if (!$map->contains($type)) {
            $map->attach(
                $type,
                new GenericArrayType($type, $is_nullable, $key_type)
            );
        }

        return $map->offsetGet($type);
    }

    public function isGenericArray() : bool
    {
        return true;
    }

    /**
     * @return Type
     * A variation of this type that is not generic.
     * i.e. 'int[]' becomes 'int'.
     */
    public function genericArrayElementType() : Type
    {
        return $this->element_type;
    }

    /**
     * @return UnionType returns the array value's union type
     * @phan-override
     */
    public function iterableValueUnionType(CodeBase $unused_codebase)
    {
        return $this->element_type->asUnionType();
    }

    /**
     * @return UnionType the array key's union type
     * @phan-override
     */
    public function iterableKeyUnionType(CodeBase $unused_codebase)
    {
        return self::unionTypeForKeyType($this->key_type);
    }

    /**
     * @return UnionType
     * A variation of this type that is not generic.
     * i.e. 'int[]' becomes 'int'.
     */
    public function genericArrayElementUnionType() : UnionType
    {
        return $this->element_type->asUnionType();
    }

    public function __toString() : string
    {
        $string = $this->element_type->__toString();
        if ($this->key_type === self::KEY_MIXED) {
            // Disambiguation is needed for ?T[] and (?T)[] but not array<int,?T>
            if ($string[0] === '?') {
                $string = '(' . $string . ')';
            }
            $string = "{$string}[]";
        } else {
            $string = 'array<' . self::KEY_NAMES[$this->key_type] . ',' . $string . '>';
        }

        if ($this->is_nullable) {
            if ($string[0] === '?') {
                $string = "?($string)";
            } else {
                $string = '?' . $string;
            }
        }

        return $string;
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands class types to all inherited classes returning
     * a superset of this type.
     * @override
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        // We're going to assume that if the type hierarchy
        // is taller than some value we probably messed up
        // and should bail out.
        if ($recursion_depth >= 20) {
            throw new RecursionDepthException("Recursion has gotten out of hand");
        }

        return $this->memoize(__METHOD__, function () use ($code_base, $recursion_depth) : UnionType {
            $union_type = $this->asUnionType();

            $class_fqsen = FullyQualifiedClassName::fromType($this->genericArrayElementType());


            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                return $union_type;
            }

            $clazz = $code_base->getClassByFQSEN($class_fqsen);

            $class_union_type = $clazz->getUnionType();
            $additional_union_type = $clazz->getAdditionalTypes();
            if ($additional_union_type !== null) {
                $class_union_type = $class_union_type->withUnionType($additional_union_type);
            }

            $union_type = $union_type->withUnionType(
                $class_union_type->asGenericArrayTypes($this->key_type)
            );

            // Recurse up the tree to include all types
            $recursive_union_type_builder = new UnionTypeBuilder();
            $representation = $this->__toString();
            try {
                foreach ($union_type->getTypeSet() as $clazz_type) {
                    if ($clazz_type->__toString() !== $representation) {
                        $recursive_union_type_builder->addUnionType(
                            $clazz_type->asExpandedTypes(
                                $code_base,
                                $recursion_depth + 1
                            )
                        );
                    } else {
                        $recursive_union_type_builder->addType($clazz_type);
                    }
                }
            } catch (RecursionDepthException $_) {
                return ArrayType::instance($this->is_nullable)->asUnionType();
            }

            // Add in aliases
            // (If enable_class_alias_support is false, this will do nothing)
            if (Config::getValue('enable_class_alias_support')) {
                self::addClassAliases($code_base, $recursive_union_type_builder, $class_fqsen);
            }
            return $recursive_union_type_builder->getUnionType();
        });
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands class types to all inherited classes returning
     * a superset of this type.
     * @override
     */
    public function asExpandedTypesPreservingTemplate(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        // We're going to assume that if the type hierarchy
        // is taller than some value we probably messed up
        // and should bail out.
        if ($recursion_depth >= 20) {
            throw new RecursionDepthException("Recursion has gotten out of hand");
        }

        return $this->memoize(__METHOD__, function () use ($code_base, $recursion_depth) : UnionType {
            $union_type = $this->asUnionType();

            $class_fqsen = FullyQualifiedClassName::fromType($this->genericArrayElementType());


            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                return $union_type;
            }

            $clazz = $code_base->getClassByFQSEN($class_fqsen);

            $class_union_type = $clazz->getUnionType();
            $additional_union_type = $clazz->getAdditionalTypes();
            if ($additional_union_type !== null) {
                $class_union_type = $class_union_type->withUnionType($additional_union_type);
            }

            $union_type = $union_type->withUnionType(
                $class_union_type->asGenericArrayTypes($this->key_type)
            );

            // Recurse up the tree to include all types
            $recursive_union_type_builder = new UnionTypeBuilder();
            $representation = $this->__toString();
            try {
                foreach ($union_type->getTypeSet() as $clazz_type) {
                    if ($clazz_type->__toString() !== $representation) {
                        $recursive_union_type_builder->addUnionType(
                            $clazz_type->asExpandedTypesPreservingTemplate(
                                $code_base,
                                $recursion_depth + 1
                            )
                        );
                    } else {
                        $recursive_union_type_builder->addType($clazz_type);
                    }
                }
            } catch (RecursionDepthException $_) {
                return ArrayType::instance($this->is_nullable)->asUnionType();
            }

            // Add in aliases
            // (If enable_class_alias_support is false, this will do nothing)
            if (Config::getValue('enable_class_alias_support')) {
                self::addClassAliases($code_base, $recursive_union_type_builder, $class_fqsen);
            }
            return $recursive_union_type_builder->getUnionType();
        });
    }

    // (If enable_class_alias_support is false, this will not be called)
    private function addClassAliases(
        CodeBase $code_base,
        UnionTypeBuilder $union_type_builder,
        FullyQualifiedClassName $class_fqsen
    ) {
        $fqsen_aliases = $code_base->getClassAliasesByFQSEN($class_fqsen);
        foreach ($fqsen_aliases as $alias_fqsen_record) {
            $alias_fqsen = $alias_fqsen_record->alias_fqsen;
            $union_type_builder->addType(
                $alias_fqsen->asType()->asGenericArrayType($this->key_type)
            );
        }
    }

    /**
     * Returns the key type for the keys of this union type.
     * E.g. for `array<string,\stdClass>`, returns self::KEY_STRING
     */
    public static function keyTypeFromUnionTypeKeys(UnionType $union_type) : int
    {
        $key_types = self::KEY_EMPTY;
        foreach ($union_type->getTypeSet() as $type) {
            if ($type instanceof GenericArrayType) {
                $key_types |= $type->key_type;
            } elseif ($type instanceof ArrayShapeType) {
                if ($type->isNotEmptyArrayShape()) {
                    $key_types |= $type->getKeyType();
                }
            }
            // Treating ArrayType as mixed or excluding ArrayType would both cause false positives. Ignore ArrayType.
        }
        // int|string corresponds to KEY_MIXED (KEY_INT|KEY_STRING)
        // And if we're unable to find any types, return KEY_MIXED.
        return $key_types ?: self::KEY_MIXED;
    }

    /** @suppress PhanUnreferencedPublicClassConstant */
    const CONVERT_KEY_MIXED_TO_EMPTY_UNION_TYPE = 0;
    const CONVERT_KEY_MIXED_TO_INT_OR_STRING_UNION_TYPE = 1;

    /**
     * @return UnionType a union type corresponding to $key_type
     */
    public static function unionTypeForKeyType(int $key_type, int $behavior = self::CONVERT_KEY_MIXED_TO_INT_OR_STRING_UNION_TYPE) : UnionType
    {
        static $int_union_type = null;
        static $string_union_type = null;
        static $int_or_string_union_type = null;
        if ($int_union_type === null) {
            $int_union_type = UnionType::fromFullyQualifiedString('int');
            $string_union_type = UnionType::fromFullyQualifiedString('string');
            $int_or_string_union_type = UnionType::fromFullyQualifiedString('int|string');
        }
        switch ($key_type) {
            case self::KEY_INT:
                return $int_union_type;
            case self::KEY_STRING:
                return $string_union_type;
            default:
                if ($behavior === self::CONVERT_KEY_MIXED_TO_INT_OR_STRING_UNION_TYPE) {
                    return $int_or_string_union_type;
                }
                return UnionType::empty();
        }
    }

    /**
     * Returns `self::KEY_*` corresponding to the provided union type.
     * E.g. for `string`, returns `self::KEY_STRING`.
     */
    public static function keyTypeFromUnionTypeValues(UnionType $union_type) : int
    {
        $key_types = self::KEY_EMPTY;
        foreach ($union_type->getTypeSet() as $type) {
            if ($type instanceof StringType) {
                $key_types |= self::KEY_STRING;
            } elseif ($type instanceof IntType) {
                $key_types |= self::KEY_INT;
            } elseif ($type instanceof MixedType) {
                // Anything including a mixed type is a mixed type.
                return self::KEY_MIXED;
            } // skip invalid types.
        }
        // int|string corresponds to KEY_MIXED (KEY_INT|KEY_STRING)
        // And if we're unable to find any types, return KEY_MIXED.
        return $key_types ?: self::KEY_MIXED;
    }

    /**
     * @param array<int|string,mixed> $array - The array keys are used for the final result.
     *
     * @return int
     * Corresponds to the type of the array keys of $array. This is a GenericArrayType::KEY_* constant (KEY_INT, KEY_STRING, or KEY_MIXED).
     */
    public static function getKeyTypeForArrayLiteral(array $array) : int
    {
        $key_type = GenericArrayType::KEY_EMPTY;
        foreach ($array as $key => $_) {
            $key_type |= (\is_string($key) ? GenericArrayType::KEY_STRING : GenericArrayType::KEY_INT);
        }
        return $key_type ?: GenericArrayType::KEY_MIXED;
    }

    /**
     * @return int
     * Corresponds to the type of the array keys of $array. This is a GenericArrayType::KEY_* constant (KEY_INT, KEY_STRING, or KEY_MIXED).
     */
    public static function getKeyTypeOfArrayNode(CodeBase $code_base, Context $context, Node $node, bool $should_catch_issue_exception = true) : int
    {
        $children = $node->children;
        if (($children[0] ?? null) instanceof Node
            && $children[0]->kind == \ast\AST_ARRAY_ELEM
        ) {
            $key_type_enum = GenericArrayType::KEY_EMPTY;
            // Check the all elements for key types.
            foreach ($children as $child) {
                if (!($child instanceof Node)) {
                    continue;
                }
                // Don't bother recursing more than one level to iterate over possible types.
                $key_node = $child->children['key'];
                if ($key_node instanceof Node) {
                    $key_type_enum |= self::keyTypeFromUnionTypeValues(UnionTypeVisitor::unionTypeFromNode(
                        $code_base,
                        $context,
                        $key_node,
                        $should_catch_issue_exception
                    ));
                } elseif ($key_node !== null) {
                    if (\is_string($key_node)) {
                        $key_type_enum |= GenericArrayType::KEY_STRING;
                    } elseif (\is_scalar($key_node)) {
                        $key_type_enum |= GenericArrayType::KEY_INT;
                    }
                } else {
                    $key_type_enum |= GenericArrayType::KEY_INT;
                }
                // If we already think it's mixed, return immediately.
                if ($key_type_enum === GenericArrayType::KEY_MIXED) {
                    return GenericArrayType::KEY_MIXED;
                }
            }
            return $key_type_enum ?: GenericArrayType::KEY_MIXED;
        }
        return GenericArrayType::KEY_MIXED;
    }

    public function hasArrayShapeOrLiteralTypeInstances() : bool
    {
        return $this->element_type->hasArrayShapeOrLiteralTypeInstances();
    }

    public function hasArrayShapeTypeInstances() : bool
    {
        return $this->element_type->hasArrayShapeTypeInstances();
    }

    /**
     * @return array<int,Type>
     * @override
     */
    public function withFlattenedArrayShapeOrLiteralTypeInstances() : array
    {
        // TODO: Any point in caching this?
        $type_instances = $this->element_type->withFlattenedArrayShapeOrLiteralTypeInstances();
        if (\count($type_instances) === 1 && $type_instances[0] === $this->element_type) {
            return [$this];
        }
        $results = [];
        foreach ($type_instances as $type) {
            $results[] = GenericArrayType::fromElementType($type, $this->is_nullable, $this->key_type);
        }
        return $results;
    }

    public function asGenericArrayType(int $key_type) : Type
    {
        return GenericArrayType::fromElementType($this, false, $key_type);
    }

    /**
     * @override
     */
    public function shouldBeReplacedBySpecificTypes() : bool
    {
        return false;
    }

    /**
     * Returns true if this contains a type that is definitely nullable or a non-object.
     * e.g. returns true false, array, int
     *      returns false for callable, object, iterable, T, etc.
     */
    public function isDefiniteNonCallableType() : bool
    {
        return $this->key_type === self::KEY_STRING;
    }
}
