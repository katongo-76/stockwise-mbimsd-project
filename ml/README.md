# Forecasting research (v0.7)

Python code used to compare forecasting methods before integrating forecasting into the system.

```
pip install -r requirements.txt
py generate_history.py      # writes generated sales history into the database (source = synthetic)
py evaluate_models.py       # rolling-origin comparison, results in results/
py forecast_job.py         # nightly job: forecasts for every product
py -m unittest discover -s tests
```
