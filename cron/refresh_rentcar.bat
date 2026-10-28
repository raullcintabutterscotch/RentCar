@echo off
setlocal
set "PHP_EXE=C:\xampp\php\php.exe"
set "SCRIPT=%~dp0refresh_rentcar.php"

if not exist "%PHP_EXE%" (
    echo PHP tidak ditemukan: %PHP_EXE%
    echo Sesuaikan nilai PHP_EXE di file ini.
    exit /b 1
)

"%PHP_EXE%" "%SCRIPT%"
exit /b %ERRORLEVEL%
