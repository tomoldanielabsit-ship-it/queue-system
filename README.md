# Queue

A lightweight service-counter appointment and queue app. Customers can reserve an available 30-minute appointment, receive a queue number by email, staff can call and complete customers in appointment order, and a public display shows the current call and waiting queue.

## Run locally

Requires Python 3.9 or newer; no third-party packages are needed.

```powershell
py -3 server.py
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000) to book. Staff use `/staff`; the public queue screen is at `/display`. Appointments are saved in `data/appointments.json`.

When SMTP is not configured, bookings are still saved and the server logs a development-only email notice; the customer sees that no email was sent. To enable actual email, set `SMTP_HOST`, optionally `SMTP_PORT` (default `587`), `SMTP_USER`, `SMTP_PASSWORD`, and `SMTP_FROM`. The server uses STARTTLS. Use an SMTP provider's credentials and sender address; do not commit credentials. `QUEUE_DATA_FILE` can point to another data file, while `HOST` and `PORT` configure the bind address and port.

The staff page is intentionally unauthenticated for a local MVP and is not suitable for public deployment as-is. Before exposing this app on a network, add staff authentication, HTTPS, and a production database with backups. The included JSON store is appropriate for local development and single-process use.

## Tests

```powershell
py -3 -m unittest discover -s tests -v
```
