# Foreign key check toolie #

This little tool connects to your MySQL to check a database for foreign key references.

## When does this occur? We have foreign key constraints, right? ##
MySQL has a feature to disable foreign key checks. This is useful when you are doing imports, and have every part of the import within a single transaction, but cannot import everything within one transaction (for example for performance reasons). This should not be a regular case, but it might occur that if you import data from some more or less unstructured source, you will end up with values that refer to non-existent primary keys.

In that case, this script is handy to figure out which values are invalid. This is more a diagnostics tool than a library, i.e., use this to debug your import process, not to run it after every import. The import should be responsible for making sure this script will NOT report foreign key issues. 

# Usage #

```
php foreign-key-check.php my_database
```
Will check all foreign keys in the `my_database` schema, and output queries to either:

* `UPDATE ... SET foreign_key=NULL WHERE foreign_key NOT IN(SELECT primary_key FROM referred_table)` when the key is nullable
* `DELETE FROM ... WHERE foreign_key IN (SELECT primary_key FROM referred_table)`

All of the queries are prepended with some information about the query. This information is SQL-commented (prefixed with `-- `) so it is safe to run the output directly on a MySQL connection:

```
php foreign-key-check.php my_database | mysql my_database
```

If you wish to see the values the queries are based on, run the tool using a `-v` (or `--verbose`) flag. It will output the exact invalid values that trigger the queries.

If you want to know how the tool extracted all meta info from the database, use a `-vv` flag.
