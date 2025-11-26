# MyOffice API - Startup Commands

## Quick Start

### From Project Root
```bash
# Navigate to project root
cd /Users/ganeshbhagya/Personal/myofiice/myoffice-api

# Start server
php -S localhost:8000 -t .
```

### From Application Directory
```bash
# Navigate to application directory
cd /Users/ganeshbhagya/Personal/myofiice/myoffice-api/application

# Start server (pointing to parent directory)
php -S localhost:8000 -t ..
```

## Alternative Commands

```bash
# Start on all network interfaces
php -S 0.0.0.0:8000 -t .

# Start on custom port
php -S localhost:8080 -t .

# Start with production domain (requires /etc/hosts setup)
php -S api.mybackpocket.co:8000 -t .
```

## API Endpoints

- Base URL: `http://localhost:8000`
- API Prefix: `/api/`
- Example: `http://localhost:8000/api/login/`

## Environment Variables

Make sure `.env` file exists in `application/` directory with:
- Database credentials
- Google OAuth credentials (already configured)
- Mail settings

