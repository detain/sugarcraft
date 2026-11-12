@echo off
rem Launch shim for Windows: candy-code/bin/candy-code is a PHP script, which
rem cmd.exe cannot run directly. Exit status is propagated unchanged.
php "%~dp0candy-code" %*
exit /b %ERRORLEVEL%
