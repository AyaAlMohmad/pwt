#!/bin/bash
# Restart the Laravel queue worker via Supervisor after code deployment.
# Run this on the server after every git pull / deployment.

echo "Restarting PWT queue worker..."
sudo supervisorctl restart pwt-queue-worker:*

if [ $? -eq 0 ]; then
    echo "Queue worker restarted successfully."
    sudo supervisorctl status pwt-queue-worker:*
else
    echo "Failed to restart queue worker. Check Supervisor config."
    exit 1
fi
