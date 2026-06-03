declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

final readonly class ToolCall
{
    public function __construct(
        private string $id,
        private string $name,
        private array $arguments,
    ) {}

    public function id(): string => $this->id;
    public function name(): string => $this->name;
    public function arguments(): array => $this->arguments;

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            arguments: $data['arguments'] ?? [],
        );
    }

    public function toArray(): array => [
        'id' => $this->id,
        'name' => $this->name,
        'arguments' => $this->arguments,
    ];
}
