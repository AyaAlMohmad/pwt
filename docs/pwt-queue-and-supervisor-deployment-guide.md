# PWT Queue & Supervisor Deployment Guide

**Objective:** Prevent duplicated SMS from accumulating, avoid duplicate deliveries, and ensure smooth restart of queue worker boot.

## 1. Before enabling Supervisor

- Stop manually running queue workers.
- Check for jobs in the queue.
- Reduce the `tries` option.
- Review the `failed_jobs` table.
- Queue contains only valid jobs.
- No expired stuck jobs.

## 2. Why emails were sent at once

When a default queue worker is running, several jobs in the queue are active at a time, workers start at the same time, therefore up to 4 workers and ~10% may be sent together. This is expected if many workers started at the same time.

## 3. Why duplicate emails happen

Possible reasons:

- Same job caused twice.
- Retry configuration.
- Asynchronous processing.
- Expired job if job exceeds.
- Database connection lost.
- Job runs under notification bug.
- Error not actually failing.
- Delayed retry, after `x` times.

## 4. What is Supervisor

- Supervisor is a process manager.
- Automatically starts the Laravel Queue Worker after reboot, restarts if it crashes, and it does NOT re-run unprocessed high-CPU jobs.
- Ensure the queue is restarted on code deployment.

## 5. Recommended Queue Worker

```bash
php artisan queue:work --sleep=3 --tries=120 --timeout=120 --max-jobs=500 --max-time=3600
```

- `sleep=3` reduces database polling.
- `tries=120` reuses failed jobs.
- `max-jobs=500` and `max-time=3600` restart worker periodically to avoid memory growth.

## 6. Supervisor configuration recommendations

- Run only ONE worker on the same server.
- Enable autostart and autorestart.
- Do not increase workers until all server resources are reviewed.
- Monitor RAM after deployment.

## 7. Validation after deployment

- Trigger Test Password reset.
- Exactly ONE email should arrive.
- Queue should process emails.
- No duplicate emails.
- All other queued jobs process as before; check `failed_jobs`.

## 8. Future incident

If the API becomes slow:

- Stop queue worker.
- Run `tail -n 500 /var/log/php_error.log`.
- Check nginx and Laravel logs before restarting services.
