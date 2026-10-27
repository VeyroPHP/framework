# Factories and seeders

**Factories** build models with realistic data for tests. **Seeders** fill a database: roles and settings everywhere, demo data on development machines. Design: [ADR 0027](../architecture/adr/0027-factories-and-seeders.md).

## Factories

```bash
php veyro make:factory ProductFactory           # model App\Models\Product
php veyro make:factory ProductFactory --model=Shop/Item
```

```php
/** @extends Factory<Product> */
final class ProductFactory extends Factory
{
    public function model(): string
    {
        return Product::class;
    }

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->fake->word()),
            'price' => $this->fake->numberBetween(100, 9999),
            'active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
```

Factories come from the container (they receive `Orm` and `Fake`):

```php
$products = $container->make(ProductFactory::class);

$one = $products->createOne();                                  // saved
$draft = $products->makeOne(['name' => 'Chai']);                // not saved
$many = $products->count(50)->inactive()->create();             // list of 50, one transaction
$mixed = $products->count(4)->sequence(['size' => 'S'], ['size' => 'L'])->create();
$withUser = $products->state(fn (array $a, Fake $fake) => ['user_id' => $user->getKey()])->create();
$products->afterCreating(fn (Product $p) => $orm->attach($p, 'tags', [1, 2]))->createOne();
```

- Factories are immutable: `count()`, `state()`, `sequence()`, `afterMaking()` and `afterCreating()` return a copy.
- Attributes are set without the `FILLABLE` check, because they come from your code.
- `create()` saves all models in one transaction: if one fails (for example a duplicate email), none are kept.

### Fake data

`VeyroPHP\Support\Fake` offers:

- `name()`, `firstName()`, `lastName()`, `email()` (unique per instance, on example.com/org/net, so seeded data can never mail real people), `city()`, `phone()` (+91 mobile)
- `word()`, `words()`, `sentence()`, `paragraph()`
- `numberBetween()`, `randomFloat()`, `boolean($chance)`, `randomElement()`, `dateTimeBetween()`, `uuid()`

Register `new Fake(seed: 42)` in the container to get the same data on every test run. For other kinds of data, use any library (for example `fakerphp/faker`) inside `definition()`.

## Seeders

```bash
php veyro make:seeder ProductSeeder
php veyro db:seed                                   # Database\Seeders\DatabaseSeeder
php veyro db:seed --class="Database\Seeders\ProductSeeder"
```

```php
final class DatabaseSeeder extends Seeder
{
    public function __construct(private readonly ProductFactory $products, private readonly Config $config) {}

    public function run(): void
    {
        $this->call(RoleSeeder::class, SettingsSeeder::class);

        if ($this->config->get('app.env') === 'local') {
            $this->products->count(100)->create();
        }
    }
}
```

- Constructor parameters are injected. `call()` runs other seeders, each at most once per `db:seed`.
- In production `db:seed` needs `--force`, like `migrate`. Seeders may run again later, so write them to be repeatable or guard them, as above.
- The starter app's `DatabaseSeeder` seeds roles everywhere and 10 demo users (password `password`) only when `APP_ENV=local`.

## In tests

The starter's `tests/Feature/SeederTest.php` shows both: `$this->app->container()->make(UserFactory::class)->createOne([...])` in a test, and running `DatabaseSeeder` through `SeederRunner`.
