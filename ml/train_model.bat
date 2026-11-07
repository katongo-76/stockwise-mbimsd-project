@echo off
REM Stockwise: rebuild the dataset from the database and retrain the model.
REM Run it after a month or so of new sales (or schedule it monthly in Task Scheduler).
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (set PY=py) else (set PY=python)
%PY% build_dataset.py || goto :fail
%PY% train_model.py || goto :fail
echo.
echo Model trained and saved to ml\model\demand_model.joblib
pause
exit /b 0
:fail
echo Training failed - see the message above.
pause
exit /b 1
