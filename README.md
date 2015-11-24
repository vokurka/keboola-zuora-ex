# Documentation

To use Zuora AQuA Extractor you just need to create the component in your KBC project and set the configuration correctly.

Here is an example of configuration:

```
{
  "bucket": "in.c-ex-zuora-main",

  "username": "<your_username>",
  "password": "<your_password>",

  "start_date": "-1 month",
  "end_date": "today",

  "queries": {
    "report1": "select AccountNumber from account where CreatedDate >= {start_date} and CreatedDate <= {end_date}",
    "report2": "select AccountNumber from account where CreatedDate >= {start_date} and CreatedDate <= {end_date}"
  }
}
```

* bucket - destination bucket for downloaded data
* username - username of account you are using to access Zuora
* password - password of account you are using to access Zuora
* start_date - placeholder for use in queries so you do not have to type the date again in every query
* end_date - placeholder for use in queries so you do not have to type the date again in every query
* queries - a list of queries and their names - the resulting data table in KBC will have the same name as query here

Warning: Zuora AQuA is implemented in stateless mode. So you have to have in mind order of the queries. For more informations look [here](https://knowledgecenter.zuora.com/BC_Developers/Aggregate_Query_API/BA_Stateless_and_Stateful_Modes).