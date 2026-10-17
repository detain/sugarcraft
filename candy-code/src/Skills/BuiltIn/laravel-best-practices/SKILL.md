---
name: laravel-best-practices
description: Laravel coding standards, Eloquent optimization, service container patterns, Blade conventions. Use when writing Laravel code, optimizing queries, structuring services, or working with Blade templates.
license: MIT
metadata:
  author: laravel-community
  version: "1.0.0"
  framework: Laravel
  phpVersion: "8.1 - 8.5"
---

# Laravel Best Practices

Coding standards, Eloquent optimization, service container patterns, and Blade conventions for building maintainable Laravel applications.

## When to Apply

- Writing or reviewing Laravel code
- Optimizing Eloquent queries and relationships
- Structuring services and dependency injection
- Working with Blade templates and components
- Implementing Laravel-specific patterns

## Eloquent Optimization

### Lazy Loading vs Eager Loading

Always use `select()` to limit columns and avoid N+1 queries:

```php
// BAD: N+1 query problem
$users = User::all();
foreach ($users as $user) {
    echo $user->posts->count();
}

// GOOD: Eager load with select
$users = User::select(['id', 'name'])->with('posts')->get();
```

### Chunking Large Datasets

Use `chunk()` and `lazy()` for processing large datasets without memory exhaustion:

```php
// Process 100 records at a time
User::chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process each user
    }
});

// Or use cursor() for even better memory efficiency
foreach (User::cursor() as $user) {
    // Process each user
}
```

### Indexing Strategy

Always index foreign keys and columns used in `where()` clauses:

```php
Schema::table('orders', function (Blueprint $table) {
    $table->index(['user_id', 'created_at']);
    $table->index('status');
});
```

## Service Container Patterns

### Dependency Injection

Prefer constructor injection over service location:

```php
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly UserService $userService,
    ) {}

    public function store(StoreOrderRequest $request): Order
    {
        return $this->orderService->create($request->validated());
    }
}
```

### Service Binding

Use service providers for binding interfaces to implementations:

```php
// App\Providers\AppServiceProvider
public function register(): void
{
    $this->app->singleton(
        PaymentGatewayInterface::class,
        StripePaymentGateway::class
    );
}
```

### Repository Pattern

Decouple Eloquent from business logic:

```php
interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
}

class UserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}
```

## Blade Conventions

### Component Style

Use anonymous components for simple, reusable UI pieces:

```blade
{{-- resources/views/components/alerts/flash.blade.php --}}
@props(['type' => 'info', 'message'])

<div {{ $attributes->merge(['class' => "alert alert-{$type}"]) }}>
    {{ $message }}
</div>

{{-- Usage --}}
<x-flash type="success" :message="session('success')" />
```

### Avoiding N+1 in Views

Pass only necessary data to views:

```php
// BAD: Loading relationships in loop
@foreach ($users as $user)
    <li>{{ $user->profile->name }}</li>
    <li>{{ $user->profile->company }}</li>
@endforeach

// GOOD: Eager load relationships
$users = User::with('profile')->get();
@foreach ($users as $user)
    <li>{{ $user->profile->name }}</li>
@endforeach
```

### Stack-based JavaScript

Use stacks for page-specific scripts:

```blade
{{-- In layout --}}
@stack('scripts')

{{-- In child view --}}
@push('scripts')
<script>
    document.getElementById('app').init();
</script>
@endpush
```

## Form Request Validation

Always use Form Request classes for complex validation:

```php
class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create-posts');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', 'unique:posts'],
            'body' => ['required', 'string'],
            'tags' => ['array', 'min:1'],
            'tags.*' => ['exists:tags,id'],
            'published_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.unique' => 'A post with this title already exists.',
        ];
    }
}
```

## Event-Driven Architecture

Use events to decouple application logic:

```php
// Define event
class OrderShipped
{
    public function __construct(
        public readonly Order $order,
        public readonly string $trackingNumber,
    ) {}
}

// Fire event
event(new OrderShipped($order, $trackingNumber));

// Listen in observer or listener
class OrderObserver
{
    public function shipped(Order $order): void
    {
        Mail::to($order->user)->send(new ShipmentNotification($order));
    }
}
```

## Queue Jobs

Always make jobs idempotent and transactional-aware:

```php
class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function handle(PaymentService $paymentService): void
    {
        if ($this->order->status !== 'pending') {
            return;
        }

        $paymentService->charge($this->order);
        $this->order->markAsPaid();
    }

    public function failed(\Throwable $exception): void
    {
        $this->order->markAsFailed($exception->getMessage());
    }
}
```

## Testing Laravel Code

### Feature Tests

Test HTTP endpoints with assertion helpers:

```php
public function test_users_can_create_account(): void
{
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
}
```

### Database Factories

Use factories for consistent test data:

```php
class OrderFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (Order $order) {
            // States applied when making
        })->afterCreating(function (Order $order) {
            // States applied when creating
        });
    }

    public function withItems(int $count = 3): static
    {
        return $this->has(Item::factory()->count($count));
    }
}
```

## Output Format

When auditing Laravel code, output findings in this format:

```
file:line - [category] Description of issue
```

Example:
```
app/Services/OrderService.php:42 - [eloquent] N+1 query detected, use with()
app/Http/Controllers/OrderController.php:28 - [container] Untyped parameter
resources/views/orders/index.blade.php:15 - [blade] Eager load relationship in loop
```
