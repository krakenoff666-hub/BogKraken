@echo off
title KrakenBog - Servidor Local
cd /d "%~dp0"

echo ============================================
echo   KRAKENBOG - SERVICIO LOCAL
echo ============================================
echo   Servidor: http://localhost:8080
echo   Cierra esta ventana para detener el servidor
echo ============================================
echo.

rem Cerrar cualquier servidor previo
taskkill /F /IM php.exe >nul 2>&1

rem Abrir navegador luego de 3s (tiempo para que arranque PHP)
start /b cmd /c "timeout /t 3 /nobreak >nul & start http://localhost:8080"

rem Levantar el servidor con 12 workers (procesa los fetch en paralelo)
set PHP_CLI_SERVER_WORKERS=12
C:\xampp\php\php.exe -S localhost:8080 -t .

pause