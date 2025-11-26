@echo off
REM MyOffice API Startup Script for Windows

echo ========================================
echo    MyOffice API - Starting Server
echo ========================================
echo.

REM Default values
set HOST=localhost
set PORT=8000

if not "%1"=="" set HOST=%1
if not "%2"=="" set PORT=%2

echo Starting server on %HOST%:%PORT%
echo API will be available at: http://%HOST%:%PORT%
echo Press Ctrl+C to stop the server
echo.

REM Start PHP built-in server
php -S %HOST%:%PORT% -t .

