@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%LOCALAPPDATA%\GameServerIndy\Start-Manager.ps1" %*
if errorlevel 1 pause
