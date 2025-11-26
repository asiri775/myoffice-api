# MyOffice API - Startup Guide

## Quick Start Commands

### Option 1: Using Startup Scripts (Easiest)

**Mac/Linux:**
```bash
./start.sh
# Or with custom host/port:
./start.sh localhost 8080
```

**Windows:**
```batch
start.bat
REM Or with custom host/port:
start.bat localhost 8080
```

### Option 2: Manual Command

From the project root directory (`/Users/ganeshbhagya/Personal/myofiice/myoffice-api`):

```bash
# Option 1: Using PHP built-in server (Recommended)
php -S localhost:8000 -t .

# Option 2: Using PHP built-in server on all interfaces (for network access)
php -S 0.0.0.0:8000 -t .

# Option 3: Using PHP built-in server on custom port
php -S localhost:8080 -t .
```

### Start with Production URL

If you need to test with the production callback URL:

```bash
php -S api.mybackpocket.co:8000 -t .
```

Or use a local domain mapping in your `/etc/hosts` file:
```
127.0.0.1 api.mybackpocket.co
```

Then run:
```bash
php -S api.mybackpocket.co:8000 -t .
```

## Prerequisites

1. **PHP 8.1+** installed
2. **Composer** dependencies installed:
   ```bash
   cd application
   composer install
   ```

3. **Environment Configuration**:
   - Copy `.env.example` to `.env` if not exists
   - Update database credentials in `.env`
   - Google OAuth credentials are already configured

## Environment Setup

The `.env` file should contain:

```env
APP_URL=http://api.mybackpocket.co
GOOGLE_CLIENT_ID=your_google_client_id_here
GOOGLE_CLIENT_SECRET=your_google_client_secret_here
GOOGLE_REDIRECT_URI=http://api.mybackpocket.co/api/oauth/google/callback
```

**Note:** Replace the placeholder values with your actual Google OAuth credentials from Google Cloud Console.

## API Base URL

- **Local Development**: `http://localhost:8000`
- **Production**: `http://api.mybackpocket.co`

## Testing the API

Once the server is running, you can test it:

```bash
# Check API version
curl http://localhost:8000/

# Test health endpoint (if available)
curl http://localhost:8000/api/space/
```

## Postman Collection

Import `MyOffice_API.postman_collection.json` into Postman for easy API testing.

## Troubleshooting

### Port Already in Use
```bash
# Find process using port 8000
lsof -i :8000

# Kill the process
kill -9 <PID>
```

### Permission Issues
```bash
# Make sure storage directories are writable
chmod -R 775 application/storage
```

### Clear Cache
```bash
cd application
php artisan config:clear
php artisan cache:clear
```

## Production Deployment

For production, use a proper web server like:
- **Nginx** with PHP-FPM
- **Apache** with mod_php
- **Docker** container

Example Nginx configuration:
```nginx
server {
    listen 80;
    server_name api.mybackpocket.co;
    root /path/to/myoffice-api;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

