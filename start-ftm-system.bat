@echo off
cd /d "%~dp0"

echo Starting FTM IT Property Management System...
echo.

REM Check if Docker is running
echo Checking Docker status...
docker info >nul 2>&1
if errorlevel 1 (
    echo ERROR: Docker is not running or not installed.
    echo Please start Docker Desktop first.
    echo.
    pause
    exit /b 1
)

echo Docker is running. Starting containers...
echo.

REM Stop any existing containers
echo Stopping any existing containers...
docker-compose down --remove-orphans

REM Start the containers
echo Starting containers...
docker-compose up -d

if errorlevel 1 (
    echo.
    echo ERROR: Failed to start containers.
    echo Checking what went wrong...
    echo.
    echo Container status:
    docker-compose ps
    echo.
    echo Recent logs:
    docker-compose logs --tail=20
    echo.
    pause
    exit /b 1
)

echo.
echo ========================================
echo FTM IT Property Management System Started!
echo ========================================
echo.
echo System URL: http://localhost:8000
echo Login Page: http://localhost:8000/auth/login.php
echo Database: Docker PostgreSQL on localhost:5432
echo.
echo Login uses credentials from your local .env file (ADMIN_PASSWORD).
echo If .env is missing, copy .env.example to .env and set passwords first.
echo.
echo Waiting for services to start...
timeout /t 15 /nobreak > nul

echo.
echo Container Status:
docker-compose ps

echo.
echo Testing services...
echo Checking if system is responding...
curl -s http://localhost:8000 >nul 2>&1
if errorlevel 1 (
    echo System not ready yet, checking logs...
    docker-compose logs php --tail=10
)

echo.
echo System startup complete!
echo.
echo Press any key to open the system in your browser...
pause >nul

echo Opening application in browser...
start http://localhost:8000/auth/login.php

echo.
echo System is now running!
echo You can close this window safely.
timeout /t 3 /nobreak > nul
