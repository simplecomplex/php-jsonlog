## JsonLog ##

#### Dependency injection container ID: logger ####

Recommendation: access (and thus instantiate) JsonLog via DI container ID 'logger'.  
See [SimpleComplex Utils](https://github.com/simplecomplex/php-utils) ``` Dependency ```.

### CLI commands ###

```bash
# List all json-log commands and their help.
php cli.phpsh json-log -h
# One command's help.
php cli.phpsh json-log-xxx -h

# Check/enable JsonLog to write logs.
php cli.phpsh json-log-committable

# Truncate current log file.
php cli.phpsh json-log-truncate
```

### Requirements ###

- PHP >=7.0
- [PSR-3 Log](https://github.com/php-fig/log)
- [SimpleComplex Config](https://github.com/simplecomplex/php-config)
- [SimpleComplex Utils](https://github.com/simplecomplex/php-utils)
