# eReolen PL

```sh
composer install --no-dev  --classmap-authoritative
rm -fr var/cache/*
```

Copy `config.json.dist` to `config.json` and edit as needed.

## Development

```sh
symfony composer install
symfony local:server:start --document-root=.
```
