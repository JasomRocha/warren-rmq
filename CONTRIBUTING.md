# Contributing

Thanks for taking the time. Bug reports, ideas and pull requests are all welcome.

## Getting started

The quickest way to see your change working is the demo stack, which brings a throwaway RabbitMQ and fake traffic:

```bash
printf 'WARREN_ADMIN_EMAIL=demo@example.com\nWARREN_ADMIN_PASSWORD=demo-password\n' > .env
docker compose -f docker-compose.yml -f docker-compose.demo.yml up --build
```

For front-end work, `composer install && npm install`, then `npm run dev` next to `php artisan serve`.

## Before opening a pull request

```bash
vendor/bin/pint      # PHP code style
php artisan test     # PHP tests
npm test             # front-end tests
npm run typecheck    # vue-tsc
```

CI runs the same checks plus a smoke test of the Docker image against the demo stack.

## Guidelines

- Keep pull requests focused: one change, with tests for what it adds or fixes.
- Warren must stay read-only towards the broker: it reads the management API and never touches users' queues.
- Interface texts live in `resources/js/lib/i18n.ts` (Portuguese and English) and `lang/`. Add both languages.
- Visual decisions are in [docs/DESIGN.md](docs/DESIGN.md).
