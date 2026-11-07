@echo off
REM Stockwise nightly forecast. Double-click to run it now, or point Windows Task Scheduler at this file.
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (py forecast_job.py) else (python forecast_job.py)
