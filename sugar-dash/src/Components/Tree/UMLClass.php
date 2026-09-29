<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A UML class in a class diagram.
 */
final class UMLClass
{
    /** @var list<ClassMember> */
    private array $attributes = [];

    /** @var list<ClassMember> */
    private array $methods = [];

    /** @var list<string> */
    private array $templateParams = [];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $package = null,
        public readonly bool $isAbstract = false,
        public readonly bool $isInterface = false,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Add an attribute.
     */
    public function withAttribute(ClassMember $attribute): self
    {
        $clone = clone $this;
        $clone->attributes[] = $attribute;
        return $clone;
    }

    /**
     * Add a method.
     */
    public function withMethod(ClassMember $method): self
    {
        $clone = clone $this;
        $clone->methods[] = $method;
        return $clone;
    }

    /**
     * Add a template parameter.
     */
    public function withTemplateParam(string $param): self
    {
        $clone = clone $this;
        $clone->templateParams[] = $param;
        return $clone;
    }

    /**
     * Get all attributes.
     *
     * @return list<ClassMember>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get all methods.
     *
     * @return list<ClassMember>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get all template parameters.
     *
     * @return list<string>
     */
    public function getTemplateParams(): array
    {
        return $this->templateParams;
    }
}
