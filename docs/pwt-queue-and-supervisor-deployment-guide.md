# PWT Queue & Supervisor Deployment Guide

**Objective:** Prevent duplicated SMS from accumulating, avoid duplicate deliveries, and ensure smooth restart of queue worker boot.

## 1. Before enabling Supervisor

- Stop manually running queue workers.
- Check for jobs in the queue.
- Reduce the `tries` option.
- Review the `failed_jobs` table.
- Queue contains only valid jobs.
- No expired stuck jobs.

## 2. Why emails were all at once

When a default queue worker is running with a single job in the queue, it may start child processes. All workers may be started at the same time, therefore up to 4 workers and ~10% may send together. This is expected if many workers started at once.

## 3. Why duplicate emails happen

Possible reasons:

- Slow queue caused issue.
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

- `sleep=3` reduces CPU polling.
- `tries=120` retries failed jobs (not immediate).
- `timeout=120` matches server/retry logic.
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

## 8. Failure incident

If the API becomes slow:

- Stop queue worker.
- Run `tail -n 500 /var/log/php_error.log`.
- Check nginx and Laravel logs before restarting services.
