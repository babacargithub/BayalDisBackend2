#!/bin/bash

# Configuration
FTP_HOST="your-ftp-host.com"
FTP_USER="your-username"
FTP_PASS="your-password"
REMOTE_PATH="/public_html/public/build"
LOCAL_PATH="./public/build"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}Starting build process...${NC}"

# Run npm build
if npm run build; then
    echo -e "${GREEN}Build completed successfully!${NC}"
else
    echo -e "${RED}Build failed!${NC}"
    exit 1
fi

# Check if lftp is installed
if ! command -v lftp &> /dev/null; then
    echo -e "${RED}lftp is not installed. Please install it first:${NC}"
    echo "Ubuntu/Debian: sudo apt-get install lftp"
    echo "macOS: brew install lftp"
    exit 1
fi

# Upload using lftp
echo -e "${GREEN}Uploading files to server...${NC}"
lftp -c "
open $FTP_HOST
user $FTP_USER $FTP_PASS
lcd $LOCAL_PATH
cd $REMOTE_PATH
mirror -R --parallel=4 --verbose
bye
"

echo -e "${GREEN}Deployment completed!${NC}"