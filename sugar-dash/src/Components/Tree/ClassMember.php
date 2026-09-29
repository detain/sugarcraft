<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * UML class member (attribute or method).
 */
final class ClassMember
{
    public function __construct(
        public readonly Visibility $visibility,
        public readonly string $name,
        public readonly string $type = '',
        public readonly bool $isStatic = false,
        public readonly bool $isAbstract = false,
    ) {}

    /**
     * Create a public member.
     */
    public static function public(string $name, string $type = ''): self
    {
        return new self(Visibility::Public, $name, $type);
    }

    /**
     * Create a private member.
     */
    public static function private(string $name, string $type = ''): self
    {
        return new self(Visibility::Private, $name, $type);
    }

    /**
     * Create a protected member.
     */
    public static function protected(string $name, string $type = ''): self
    {
        return new self(Visibility::Protected, $name, $type);
    }

    /**
     * Create a static member.
     */
    public static function static(self $member): self
    {
        return new self(
            $member->visibility,
            $member->name,
            $member->type,
            true,
            $member->isAbstract,
        );
    }

    /**
     * Render the member as a string.
     */
    public function render(): string
    {
        $result = $this->visibility->value;

        if ($this->isStatic) {
            $result .= '_';
        }

        if ($this->isAbstract) {
            $result .= 'Δ';
        }

        $result .= $this->name;

        if ($this->type !== '') {
            $result .= ': ' . $this->type;
        }

        return $result;
    }
}
