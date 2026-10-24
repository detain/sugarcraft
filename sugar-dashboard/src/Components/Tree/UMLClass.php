<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

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
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::attributes()} instead. REMOVED-IN: 2.0.0.
     */
    public function getAttributes(): array { return $this->attributes(); }

    /**
     * Get all methods.
     *
     * @return list<ClassMember>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::methods()} instead. REMOVED-IN: 2.0.0.
     */
    public function getMethods(): array { return $this->methods(); }

    /**
     * Get all template parameters.
     *
     * @return list<string>
     */
    public function templateParams(): array
    {
        return $this->templateParams;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::templateParams()} instead. REMOVED-IN: 2.0.0.
     */
    public function getTemplateParams(): array { return $this->templateParams(); }
}
