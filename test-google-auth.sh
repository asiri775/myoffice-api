#!/bin/bash

# Google OAuth Testing Script for Backend Developers

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Default values
BASE_URL=${1:-http://localhost:8000}
PROVIDER=${2:-google}

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   Google OAuth Testing Tool${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

echo -e "${GREEN}Base URL:${NC} $BASE_URL"
echo -e "${GREEN}Provider:${NC} $PROVIDER"
echo ""

# Step 1: Get Redirect URL
echo -e "${YELLOW}Step 1: Getting OAuth redirect URL...${NC}"
RESPONSE=$(curl -s "$BASE_URL/api/oauth/$PROVIDER/redirect")

# Check if response is valid JSON
if ! echo "$RESPONSE" | jq . >/dev/null 2>&1; then
    echo -e "${RED}Error: Invalid response from server${NC}"
    echo "$RESPONSE"
    exit 1
fi

# Extract status and URL
STATUS=$(echo "$RESPONSE" | jq -r '.status // "unknown"')
URL=$(echo "$RESPONSE" | jq -r '.data.url // ""')

if [ "$STATUS" != "success" ] || [ -z "$URL" ]; then
    echo -e "${RED}Error: Failed to get redirect URL${NC}"
    echo "$RESPONSE" | jq '.'
    exit 1
fi

echo -e "${GREEN}✓ Successfully retrieved OAuth URL${NC}"
echo ""
echo -e "${BLUE}OAuth URL:${NC}"
echo "$URL"
echo ""

# Step 2: Instructions
echo -e "${YELLOW}Step 2: Next Steps${NC}"
echo "1. Copy the OAuth URL above"
echo "2. Open it in your browser"
echo "3. Complete Google authentication"
echo "4. After authentication, you'll be redirected to the callback"
echo "5. The callback will redirect to: myofficeapp://auth/success?token=API_KEY"
echo ""
echo -e "${YELLOW}To test callback manually:${NC}"
echo "After authentication, copy the 'code' parameter from the callback URL"
echo "Then run:"
echo -e "${BLUE}curl -I \"$BASE_URL/api/oauth/$PROVIDER/callback?code=YOUR_CODE\"${NC}"
echo ""

# Step 3: Check environment
echo -e "${YELLOW}Step 3: Environment Check${NC}"
if [ -f "application/.env" ]; then
    if grep -q "GOOGLE_CLIENT_ID" application/.env; then
        echo -e "${GREEN}✓ Google credentials found in .env${NC}"
    else
        echo -e "${RED}✗ Google credentials not found in .env${NC}"
    fi
    
    REDIRECT_URI=$(grep "GOOGLE_REDIRECT_URI" application/.env | cut -d '=' -f2)
    if [ -n "$REDIRECT_URI" ]; then
        echo -e "${GREEN}✓ Redirect URI: $REDIRECT_URI${NC}"
    fi
else
    echo -e "${RED}✗ .env file not found${NC}"
fi
echo ""

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   Testing Complete${NC}"
echo -e "${BLUE}========================================${NC}"

