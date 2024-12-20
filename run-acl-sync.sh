#!/bin/bash

# Configuration
BASE_DIR="wp-content/blogs.dir"
MAX_WORKERS=8
PIDS=()

# Resolve the directory of the current script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$SCRIPT_DIR/handle-acl-for-site-attachments.php"

LOG_FILE="$SCRIPT_DIR/runs/$(date +"%Y-%m-%d-%H:%M:%S").log"

# Verify the script exists
if [ ! -f "$SCRIPT_PATH" ]; then
    echo "Error: PHP script not found at $SCRIPT_PATH"
    exit 1
fi

# Create the runs directory if it doesn't exist
mkdir -p "$(dirname "$LOG_FILE")"

# Start logging
echo "Logging to $LOG_FILE"
echo "Starting ACL updates at $(date)" > "$LOG_FILE"

# Function to wait for available workers
wait_for_pids() {
    while [ "${#PIDS[@]}" -ge "$MAX_WORKERS" ]; do
        for i in "${!PIDS[@]}"; do
            if ! kill -0 "${PIDS[i]}" 2>/dev/null; then
                unset PIDS[i]
            fi
        done
        PIDS=("${PIDS[@]}") # Rebuild array to remove gaps
        sleep 1
    done
}

# Iterate over directories
BLOG_IDS=$(mysql -e "SELECT blog_id FROM wp_blogs ORDER BY blog_id;" -s -N)
for blog_id in $BLOG_IDS; do

    # Log the start of processing
    echo "Starting ACL update for blog $blog_id" | tee -a "$LOG_FILE"

    # Start the PHP script in the background
#    wp eval-file "$SCRIPT_PATH" "$blog_id" >> "$LOG_FILE" 2>&1 &
    wp eval-file "$SCRIPT_PATH" "$blog_id" "$LOG_FILE" 2>&1 &
    PIDS+=($!)

    # Wait for available worker slots
    wait_for_pids
done

# Wait for all remaining processes to finish
for pid in "${PIDS[@]}"; do
    wait "$pid"
done

# Log completion
echo "All ACL updates completed at $(date)" | tee -a "$LOG_FILE"

