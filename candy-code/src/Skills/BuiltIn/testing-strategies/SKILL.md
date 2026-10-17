---
name: testing-strategies
description: PHPUnit best practices, mock patterns, test organization, coverage goals. Use when writing tests, setting up test suites, creating mocks, or analyzing test coverage.
license: MIT
metadata:
  author: php-testing-community
  version: "1.0.0"
  framework: PHPUnit
  phpVersion: "8.1 - 8.5"
---

# Testing Strategies

PHPUnit best practices, mock patterns, test organization, and coverage goals for writing maintainable, effective tests.

## When to Apply

- Writing or reviewing PHP tests
- Setting up test suites and fixtures
- Creating mocks and test doubles
- Analyzing and improving test coverage
- Debugging test failures

## Test Organization

### Directory Structure

Mirror the source structure in tests:

```
src/
  Service/
    PaymentService.php
tests/
  Unit/
    Service/
      PaymentServiceTest.php
  Integration/
    Service/
      PaymentServiceIntegrationTest.php
```

### Test Naming Conventions

Follow consistent naming:

```php
class PaymentServiceTest extends TestCase
{
    public function test_process_charge_returns_success_on_valid_payment(): void
    {
        // Given
        $payment = new Payment(100, 'usd');

        // When
        $result = $this->service->processCharge($payment);

        // Then
        $this->assertTrue($result->isSuccessful());
    }

    public function test_process_charge_throws_invalid_payment_exception_on_expired_card(): void
    {
        $this->expectException(InvalidPaymentException::class);

        $payment = new Payment(100, 'usd', new \DateTime('2020-01-01'));
        $this->service->processCharge($payment);
    }

    /**
     * @dataProvider discountProvider
     */
    public function test_calculate_total_applies_discount_correctly(float $subtotal, float $discount, float $expected): void
    {
        $result = $this->calculator->calculateTotal($subtotal, $discount);
        $this->assertEquals($expected, $result);
    }

    public static function discountProvider(): array
    {
        return [
            'no discount' => [100.00, 0.00, 100.00],
            'percentage discount' => [100.00, 10.00, 90.00],
            'full discount' => [100.00, 100.00, 0.00],
        ];
    }
}
```

## Mock Patterns

### Creating Mocks

Use mocks for dependencies:

```php
public function test_order_service_creates_order_with_items(): void
{
    // Create mock for repository
    $orderRepository = $this->createMock(OrderRepositoryInterface::class);

    // Configure mock expectations
    $orderRepository
        ->expects($this->once())
        ->method('save')
        ->with($this->isInstanceOf(Order::class));

    // Create service with mock
    $service = new OrderService($orderRepository);

    // Act
    $order = $service->createOrder(['item1', 'item2']);

    // Assert
    $this->assertCount(2, $order->getItems());
}
```

### Callback Verification

Use `$this->callback()` for complex assertions:

```php
public function test_processor_calls_callback_with_correct_data(): void
{
    $invoked = false;
    $capturedData = null;

    $this->processor->process(function ($data) use (&$invoked, &$capturedData) {
        $invoked = true;
        $capturedData = $data;
        return true;
    });

    $this->assertTrue($invoked);
    $this->assertEquals(['key' => 'value'], $capturedData);
}
```

### Partial Mocks

Use partial mocks sparingly, prefer dependency injection:

```php
// Prefer: Inject the dependency
public function test_calculator_with_custom_formatter(): void
{
    $formatter = $this->createMock(NumberFormatter::class);
    $formatter->method('format')->willReturn('$1,234.56');

    $calculator = new PriceCalculator($formatter);
    $result = $calculator->calculate(1234.56);

    $this->assertEquals('$1,234.56', $result);
}

// Avoid: Partial mock
public function test_calculator_uses_correct_formatter(): void
{
    $calculator = $this->getMockBuilder(PriceCalculator::class)
        ->onlyMethods(['format'])
        ->getMock();

    $calculator->method('format')->willReturn('$1,234.56');

    $result = $calculator->calculate(1234.56);
    $this->assertEquals('$1,234.56', $result);
}
```

### Mocking Static Methods

Avoid mocking static methods; wrap in services:

```php
// BAD: Mocking static method directly
class OrderServiceTest extends TestCase
{
    public function test_creates_order_with_timestamp(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getCreatedAt')->willReturn(new \DateTime());

        $this->assertInstanceOf(\DateTime::class, $order->getCreatedAt());
    }
}

// GOOD: Mock the wrapper service instead
class TimeService
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

class OrderService
{
    public function __construct(
        private readonly OrderRepository $repository,
        private readonly TimeService $time,
    ) {}

    public function create(array $data): Order
    {
        $order = new Order($data);
        $order->setCreatedAt($this->time->now());
        return $this->repository->save($order);
    }
}
```

## Data Providers

### Using Data Providers

Organize test data with providers:

```php
public static function purchaseDataProvider(): array
{
    return [
        'standard purchase' => [
            'items' => [['sku' => 'A', 'qty' => 1, 'price' => 10.00]],
            'expectedTotal' => 10.00,
            'expectedShipping' => 5.00,
        ],
        'purchase with quantity discount' => [
            'items' => [
                ['sku' => 'A', 'qty' => 5, 'price' => 10.00],
            ],
            'expectedTotal' => 45.00,
            'expectedShipping' => 0.00,
        ],
        'free shipping threshold' => [
            'items' => [
                ['sku' => 'A', 'qty' => 1, 'price' => 100.00],
            ],
            'expectedTotal' => 100.00,
            'expectedShipping' => 0.00,
        ],
    ];
}

#[\PHPUnit\Framework\Attributes\DataProvider('purchaseDataProvider')]
public function test_checkout_calculates_totals_correctly(array $items, float $expectedTotal, float $expectedShipping): void
{
    $result = $this->checkout->calculate($items);

    $this->assertEquals($expectedTotal, $result->total());
    $this->assertEquals($expectedShipping, $result->shipping());
}
```

## Test Doubles

### Stubs, Mocks, and Fakes

Choose the right double type:

```php
// STUB: Provide predetermined responses
public function test_discount_applies_to_eligible_customers(): void
{
    $customer = $this->createStub(Customer::class);
    $customer->method('getTier')->willReturn('premium');

    $discount = new TierDiscount();
    $result = $discount->apply($customer, 100.00);

    $this->assertEquals(90.00, $result);
}

// FAKE: Simple implementation for testing
public function test_order_repository_fake(): void
{
    $repository = new InMemoryOrderRepository();
    $repository->save(new Order(['id' => '1']));
    $repository->save(new Order(['id' => '2']));

    $this->assertCount(2, $repository->findAll());
}

// MOCK: Verify interactions
public function test_payment_service_records_transaction(): void
{
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
        ->method('info')
        ->with('Payment processed', $this->anything());

    $service = new PaymentService($logger);
    $service->process(new Payment(100));
}
```

## Coverage Goals

### What to Cover

Prioritize business logic coverage:

| Priority | Area | Target |
|----------|------|--------|
| 1 | Domain/Entity logic | 100% |
| 2 | Service layer | 90% |
| 3 | Controllers | 80% |
| 4 | Data access | 70% |

### Measuring Coverage

Use PHPUnit's coverage features:

```bash
# Run with coverage report
php vendor/bin/phpunit --coverage-text --coverage-html coverage/

# Check specific file coverage
php vendor/bin/phpunit --coverage-text --coverage-filter "src/Service"
```

### Coverage Trap

High coverage doesn't mean good tests:

```php
// BAD: Tests coverage but not behavior
public function test_getter_returns_value(): void
{
    $user = new User();
    $user->setName('Test');
    $this->assertEquals('Test', $user->getName());
}

// GOOD: Test actual behavior and edge cases
public function test_order_total_calculates_with_discount_and_tax(): void
{
    $order = new Order([
        ['price' => 100.00, 'quantity' => 2],
        ['price' => 50.00, 'quantity' => 1],
    ]);

    $order->applyDiscount(10.0); // 10%
    $order->setTaxRate(8.25);    // 8.25%

    // Subtotal: 250, Discount: 25, Taxable: 225, Tax: 18.56
    $this->assertEquals(243.56, $order->getTotal());
}
```

## Fixtures and Setup

### setUp and tearDown

Use wisely to avoid interdependencies:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->orderRepository = new InMemoryOrderRepository();
    $this->paymentGateway = $this->createMock(PaymentGateway::class);
    $this->service = new OrderService(
        $this->orderRepository,
        $this->paymentGateway
    );
}

protected function tearDown(): void
{
    $this->service = null;
    $this->paymentGateway = null;
    $this->orderRepository = null;

    parent::tearDown();
}
```

## Output Format

When reviewing tests, output findings in this format:

```
file:line - [category] Description of issue
```

Example:
```
tests/Unit/Service/PaymentServiceTest.php:42 - [mock] Unused mock expectation
tests/Unit/Service/OrderServiceTest.php:28 - [naming] Test name doesn't describe behavior
tests/Integration/CheckoutTest.php:15 - [coverage] Missing test for edge case
```
