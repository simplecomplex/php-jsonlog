## JsonLog ##

PSR-3 logger which files events as JSON.


### Columns ###

Columns are configuration-wise split in groups of _event_-, _request_- and _site_-specific items.  
For every column there's a ```JsonLogEvent``` method and a JSON bucket name (this lists the bucket names).

Several columns get skipped if their equivalent method returns empty string (that's the un-bold items).

Some column's must be set via the PSR-3 logger methods' ```$context``` argument.

All column values are string, except the _code_ column.

##### Event #####
- **message**
- **@timestamp**: ISO-8601
- **message_id**: fairly random ID, using site ID as salt
- correlation_id: set via ```$context```
- **subtype**: set via ```$context```, default ```component```
- **level**: ```emergency|alert|critical|error|warning|notice|info|debug```
- **code**: integer, set via ```$context```; default ```0```
- exception: PHP throwable, via ```$context```; becomes class name
- trunc: ```(original byte length/truncated length)```
- user: set via ```$context```, or override ```JsonLogEvent::user()``` in extending class
- session: set via ```$context```, override ```JsonLogEvent::session()``` in extending class

##### Request #####

- **method**: HTTP request method; ```cli``` if in CLI mode
- **request_uri**: HTTP request URI; console arguments if in CLI mode
- referer: HTTP referrer (sanitized)
- client_ip: remote address, filtered for _reverse_proxy_addresses_
- useragent: sanitized

##### Site #####

- **type**: _type_ setting; default ```webapp```
- **host**: ```$_SERVER['SERVER_NAME']``` or empty
- **site_id**: _siteid_ setting
- canonical: _canonical_ setting
- tags:  _tags_ setting

### Settings ###

Are set via 'global' [SimpleComplex Config](https://github.com/simplecomplex/php-config),
section ```lib_simplecomplex_jsonlog```.

The simplest approach to that is to **use environment variables**, like  
```SetEnv lib_simplecomplex_jsonlog__threshold 7```.  
```JsonLog``` constructor uses environment variables as fallback.

- (int) **threshold**: less severe events are skipped (not logged); default ```4``` (~```warning```)
- (str) **siteid**: defaults to name of directory above document root
- (str) **path**: default ```/var/log/[apache2|httpd|nginx]```/php-jsonlog
- (int) **truncate** (Kb): truncate _message_ so that event JSON doesn't exceed that length; default ```32```
- (str) **reverse_proxy_addresses**: comma-separated list of IP addresses; default empty
- (str) **type**: default ```webapp```
- (str) **canonical**: site identifier across multiple instances; default empty
- (str) **tags**: comma-separated list; default empty
- (str) **reverse_proxy_header**: default ```HTTP_X_FORWARDED_FOR```
- (str) **file_time**: set to empty or ```none``` to write to the same log file forever; default ```Ymd```
- (str) **format**: ```default|pretty|prettier```; ```prettier``` is not valid JSON, but easier on the eyes

#### Recommended settings ####

##### All environments #####

- **siteid**: use something more meaningful than the default
- **truncate**: way higher, to like ```256```, if your system generates giant dumps or traces

##### Prod environment #####

- **reverse_proxy_addresses**: look out for proxy servers

##### Dev/test environment #####

- **threshold**: ```7``` (~```debug```)
- **path**: make it closer to home if no log extractor (like [Kibana+ElasticSearch](https://www.elastic.co)) running
- **format**: ```prettier``` if no log extractor running


### CLI commands ###

```bash
# List all json-log commands and their help.
php cli.php json-log -h
# One command's help.
php cli.php json-log-xxx -h

# Check/enable JsonLog to write logs.
php cli.php json-log-committable

# Truncate current log file.
php cli.php json-log-truncate
```

### Dependency injection container ID: logger ###

Recommendation: access (and thus instantiate) JsonLog via DI container ID 'logger'.  
See [SimpleComplex Utils](https://github.com/simplecomplex/php-utils) ``` Dependency ```.

### Requirements ###

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)

##### Suggestions #####

- [SimpleComplex Inspect](https://github.com/simplecomplex/inspect)
- [SimpleComplex Config](https://github.com/simplecomplex/php-config)