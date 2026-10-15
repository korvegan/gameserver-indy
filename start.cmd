@echo off
title Enshrouded Indy
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-manager.ps1" %*
if errorlevel 1 pause
