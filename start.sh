#!/bin/bash

# MyOffice API Startup Script

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   MyOffice API - Starting Server${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# Get the directory where the script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Default values
HOST=${1:-localhost}
PORT=${2:-8000}

echo -e "${GREEN}Starting server on ${HOST}:${PORT}${NC}"
echo -e "${GREEN}API will be available at: http://${HOST}:${PORT}${NC}"
echo -e "${GREEN}Press Ctrl+C to stop the server${NC}"
echo ""

# Start PHP built-in server
php -S ${HOST}:${PORT} -t .



