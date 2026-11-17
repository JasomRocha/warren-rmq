# Security

## Reporting a vulnerability

Please report security issues privately through GitHub: **Security → Report a vulnerability** on this repository.
Do not open a public issue for them. You will get an answer within a few days.

## Security model

Warren is meant for the people who operate a RabbitMQ, on a network they trust. Keep these in mind when deploying it:

- **Logged-in users see message contents.** The tap copies published messages and the UI shows their headers and body.
  Values under sensitive keys (password, token, secret, authorization...) and JWTs are masked, but anything else in a
  payload is visible. Only give accounts to people who may read your traffic.
- **There are no roles yet.** Every user can add, edit and remove connections.
- **Connections may point at private networks**, because watching an internal broker is the point. Link-local
  addresses (such as cloud metadata at `169.254.169.254`), URLs with credentials or query strings, and HTTP redirects are refused.
- **Saved passwords are bound to their destination.** Changing a connection's address, user, vhost or AMQP endpoint requires
  typing the password again, so it can never be sent somewhere else.
- **Passwords are encrypted at rest** with `APP_KEY` and never returned to the browser. Back up `APP_KEY` together with the
  database: without it the saved passwords cannot be read.

## Deploying

- The Docker setup binds the port to `127.0.0.1`. Put an HTTPS reverse proxy in front before exposing it.
- Set `APP_URL` to the `https://` address (session cookies then become secure) and `WARREN_TRUSTED_PROXIES` to the proxy's
  address, not `*`, unless the proxy is the only way to reach the app.
- Logins are rate limited per e-mail and IP, per e-mail and per IP. Every page is sent with a Content Security Policy,
  `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff`.
- `WARREN_AUTH=false` turns the login off. Use it only behind something that already authenticates (VPN, SSO proxy).
