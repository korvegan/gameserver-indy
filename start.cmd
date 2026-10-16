@echo off
title GameServer Indy
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-manager.ps1" -ConfigPath "%LOCALAPPDATA%\GameServerIndy\launcher.local.json" %*
if errorlevel 1 pause
