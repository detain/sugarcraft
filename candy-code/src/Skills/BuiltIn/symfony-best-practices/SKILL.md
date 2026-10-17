---
name: symfony-best-practices
description: Symfony coding standards, service definition, event dispatcher patterns, form handling. Use when writing Symfony code, configuring services, handling forms, or working with event subscribers.
license: MIT
metadata:
  author: symfony-community
  version: "1.0.0"
  framework: Symfony
  phpVersion: "8.1 - 8.5"
---

# Symfony Best Practices

Coding standards, service configuration, event dispatcher patterns, and form handling for building maintainable Symfony applications.

## When to Apply

- Writing or reviewing Symfony code
- Configuring services and dependency injection
- Working with forms and validation
- Implementing event-driven logic
- Building API endpoints with Symfony

## Service Configuration

### Autowiring

Let Symfony handle dependency injection via autowiring:

```php
// src/Controller/OrderController.php
namespace App\Controller;

use App\Service\OrderService;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class OrderController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderRepository $orderRepository,
    ) {}

    public function index(): Response
    {
        $orders = $this->orderRepository->findRecent();
        return $this->json(['orders' => $orders]);
    }
}
```

### Service Naming

Follow PSR-4 naming conventions for services:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    App\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Kernel.php'
```

### Tagged Services

Use tags for collecting related services:

```php
// src/EventSubscriber/PaymentSubscriberInterface.php
interface PaymentSubscriberInterface
{
    public function onPaymentSuccess(PaymentEvent $event): void;
}

// src/EventSubscriber/StripeSubscriber.php
class StripeSubscriber implements PaymentSubscriberInterface
{
    public function onPaymentSuccess(PaymentEvent $event): void
    {
        // Handle Stripe-specific logic
    }
}

// config/services.yaml
services:
    _instanceof:
        App\EventSubscriber\PaymentSubscriberInterface:
            tags: ['app.payment_subscriber']

    App\EventDispatcher\PaymentDispatcher:
        arguments:
            $subscribers: !tagged_iterator app.payment_subscriber
```

## Event Dispatcher Patterns

### Event Classes

Create dedicated event classes for domain events:

```php
// src/Event/OrderPlacedEvent.php
namespace App\Event;

use App\Entity\Order;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\Event;

class OrderPlacedEvent extends Event
{
    public const NAME = 'order.placed';

    public function __construct(
        public readonly Order $order,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
```

### Event Listeners vs Subscribers

Prefer listeners for single-use, subscribers for multiple events:

```php
// Listener (simpler, recommended for single handlers)
class SendOrderConfirmationListener
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function __invoke(OrderPlacedEvent $event): void
    {
        $this->mailer->send(
            new OrderConfirmation($event->order)
        );
    }
}

// Subscriber (for multiple events, use #[AsEventListener]
#[AsEventListener(event: OrderPlacedEvent::class, method: 'onOrderPlaced')]
#[AsEventListener(event: OrderCancelledEvent::class, method: 'onOrderCancelled')]
class OrderEventSubscriber
{
    public function onOrderPlaced(OrderPlacedEvent $event): void
    {
        // Handle placed
    }

    public function onOrderCancelled(OrderCancelledEvent $event): void
    {
        // Handle cancelled
    }
}
```

### Kernel Events

Subscribe to kernel events for request/response handling:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
class LocaleListener
{
    public function __construct(private readonly RouterInterface $router) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $request->setLocale($request->attributes->get('_locale', 'en'));
    }
}
```

## Form Handling

### Form Types

Create focused, reusable form types:

```php
// src/Form/Type/OrderType.php
namespace App\Form\Type;

use App\Entity\Order;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('customerName', TextType::class, [
                'label' => 'Customer Name',
                'attr' => ['placeholder' => 'Enter full name'],
            ])
            ->add('email', EmailType::class)
            ->add('notes', TextType::class, [
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Order::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
```

### Form Events

Use form events for dynamic form modification:

```php
public function buildForm(FormBuilderInterface $builder, array $options): void
{
    $builder
        ->add('country', ChoiceType::class, [
            'choices' => ['USA' => 'US', 'Canada' => 'CA'],
        ])
        ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();

            if ($data && $data->getCountry() === 'US') {
                $form->add('state', ChoiceType::class, [
                    'choices' => $this->getUsStates(),
                ]);
            }
        });
}
```

### Form Handling in Controller

Handle forms with proper flow:

```php
#[Route('/order/new', name: 'order_new')]
public function new(Request $request, EntityManagerInterface $entityManager): Response
{
    $form = $this->createForm(OrderType::class);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $entityManager->persist($form->getData());
        $entityManager->flush();

        $this->addFlash('success', 'Order created successfully.');

        return $this->redirectToRoute('order_show', [
            'id' => $form->getData()->getId(),
        ]);
    }

    return $this->render('order/new.html.twig', [
        'order_form' => $form,
    ]);
}
```

## Validation

### Entity Constraints

Define validation at the entity level:

```php
namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

class Product
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private string $name;

    #[Assert\Positive]
    #[Assert\Range(min: 0.01, max: 9999.99)]
    private float $price;

    #[Assert\Url]
    private ?string $imageUrl = null;

    #[Assert\All([
        new Assert\NotBlank,
        new Assert\Length(max: 50),
    ])]
    private array $tags = [];
}
```

### Custom Validators

Create domain-specific validators:

```php
// src/Validator/ContainsUppercase.php
namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[Attribute]
class ContainsUppercase extends Constraint
{
    public string $message = 'The string "{{ value }}" must contain at least one uppercase letter.';
}

#[\Attribute]
class ContainsUppercaseValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (null === $value || '' === $value) {
            return;
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
```

## Console Commands

### Creating Commands

Use dependency injection and return exit codes:

```php
// src/Command/ExportOrdersCommand.php
namespace App\Command;

use App\Repository\OrderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export:orders',
    description: 'Export orders to CSV',
)]
class ExportOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format', 'csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $orders = $this->orderRepository->findAll();
            $io->success(sprintf('Exported %d orders.', count($orders)));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

## Output Format

When auditing Symfony code, output findings in this format:

```
file:line - [category] Description of issue
```

Example:
```
src/Controller/OrderController.php:42 - [service] Missing type hints
src/EventSubscriber/PaymentSubscriber.php:28 - [event] Use #[AsEventListener]
src/Form/Type/OrderType.php:15 - [form] Add validation constraints
```
